#!/usr/bin/env bash
# X 项目核心工具：将已登记应用打包为 Android WebView 工程（可导入 Android Studio）
#
# Usage:
#   bash scripts/x-pack-android.sh --app=car_hailing_assistant
#   bash scripts/x-pack-android.sh --app=car_hailing_assistant --start-url=https://www.topstar.run/driver
#   bash scripts/x-pack-android.sh --list
#   bash scripts/x-pack-android.sh --validate --app=car_hailing_assistant
#   bash scripts/x-pack-android.sh --app=car_hailing_assistant --assemble   # needs JDK17 + SDK
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PACKER="$ROOT/tools/android-packer"
APPS_DIR="$PACKER/apps"
TEMPLATE="$PACKER/template"
OUT_DIR="${X_ANDROID_OUT_DIR:-/home/challey/staging/drupalX/android}"
APP_ID=""
START_URL=""
ALLOWED_HOST=""
APPLICATION_ID=""
VALIDATE_ONLY=0
LIST_ONLY=0
ASSEMBLE=0
STAMP="$(date +%Y%m%d_%H%M%S)"

usage() {
  sed -n '2,14p' "$0" | sed 's/^# \{0,1\}//'
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --app=*) APP_ID="${1#*=}" ;;
    --app) APP_ID="${2:-}"; shift ;;
    --start-url=*) START_URL="${1#*=}" ;;
    --allowed-host=*) ALLOWED_HOST="${1#*=}" ;;
    --application-id=*) APPLICATION_ID="${1#*=}" ;;
    --out=*) OUT_DIR="${1#*=}" ;;
    --validate) VALIDATE_ONLY=1 ;;
    --list) LIST_ONLY=1 ;;
    --assemble) ASSEMBLE=1 ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown arg: $1" >&2; usage; exit 1 ;;
  esac
  shift
done

if [[ "$LIST_ONLY" == "1" ]]; then
  echo "Registered X Android apps:"
  find "$APPS_DIR" -name '*.manifest.yml' -printf '%f\n' 2>/dev/null | sed 's/\.manifest\.yml$//' | sort
  exit 0
fi

if [[ -z "$APP_ID" ]]; then
  echo "ERROR: --app=<id> required (try --list)" >&2
  exit 1
fi

MANIFEST="$APPS_DIR/${APP_ID}.manifest.yml"
if [[ ! -f "$MANIFEST" ]]; then
  echo "ERROR: manifest missing: $MANIFEST" >&2
  exit 1
fi
if [[ ! -d "$TEMPLATE/app" ]]; then
  echo "ERROR: android template missing at $TEMPLATE" >&2
  exit 1
fi

yaml_get() {
  local key="$1"
  awk -v k="$key" '
    $0 ~ "^" k ":" {
      sub("^[^:]+:[[:space:]]*", "", $0);
      gsub(/^["'\'']|["'\'']$/, "", $0);
      print $0;
      exit
    }
  ' "$MANIFEST"
}

LABEL="$(yaml_get label)"
BRAND="$(yaml_get brand_name)"
PROJECT_NAME="$(yaml_get project_name)"
M_APP_ID="$(yaml_get application_id)"
M_START="$(yaml_get start_url)"
M_HOST="$(yaml_get allowed_host)"
VERSION_CODE="$(yaml_get version_code)"
VERSION_NAME="$(yaml_get version_name)"

APPLICATION_ID="${APPLICATION_ID:-$M_APP_ID}"
START_URL="${START_URL:-$M_START}"
ALLOWED_HOST="${ALLOWED_HOST:-$M_HOST}"
PROJECT_NAME="${PROJECT_NAME:-$APP_ID}"
BRAND="${BRAND:-$LABEL}"
VERSION_CODE="${VERSION_CODE:-1}"
VERSION_NAME="${VERSION_NAME:-1.0.0}"

if [[ -z "$ALLOWED_HOST" && -n "$START_URL" ]]; then
  ALLOWED_HOST="$(python3 - <<PY
from urllib.parse import urlparse
print(urlparse("$START_URL").hostname or "")
PY
)"
fi

echo "==> X pack android"
echo "    app     : $APP_ID ($LABEL)"
echo "    package : $APPLICATION_ID"
echo "    start   : $START_URL"
echo "    host    : $ALLOWED_HOST"

missing=0
[[ -n "$APPLICATION_ID" ]] || { echo "MISSING application_id"; missing=1; }
[[ -n "$START_URL" ]] || { echo "MISSING start_url"; missing=1; }
[[ -n "$ALLOWED_HOST" ]] || { echo "MISSING allowed_host"; missing=1; }
[[ -f "$TEMPLATE/app/src/main/java/x/app/shell/MainActivity.java" ]] || { echo "MISSING template MainActivity"; missing=1; }
if [[ "$missing" -ne 0 ]]; then
  echo "ERROR: validation failed" >&2
  exit 1
fi
echo "    validate: OK"

if [[ "$VALIDATE_ONLY" == "1" ]]; then
  exit 0
fi

NAME="${APP_ID}-android-deploy-latest"
STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT
DEST="$STAGE/$NAME"
mkdir -p "$DEST"
rsync -a --delete \
  --exclude '.gradle' \
  --exclude 'build' \
  --exclude 'app/build' \
  --exclude '.idea' \
  "$TEMPLATE/" "$DEST/"

# Token replace across text files
python3 - "$DEST" "$PROJECT_NAME" "$APPLICATION_ID" "$BRAND" "$START_URL" "$ALLOWED_HOST" "$VERSION_CODE" "$VERSION_NAME" <<'PY'
import os, sys
root, project, app_id, brand, start, host, vcode, vname = sys.argv[1:9]
repls = {
    "__PROJECT_NAME__": project,
    "__APPLICATION_ID__": app_id,
    "__APP_NAME__": brand,
    "__START_URL__": start,
    "__ALLOWED_HOST__": host,
    "__VERSION_CODE__": str(vcode),
    "__VERSION_NAME__": str(vname),
}
skip_ext = {".png", ".jpg", ".jpeg", ".webp", ".jar", ".dex"}
for dirpath, _, files in os.walk(root):
    for name in files:
        path = os.path.join(dirpath, name)
        ext = os.path.splitext(name)[1].lower()
        if ext in skip_ext:
            continue
        try:
            text = open(path, encoding="utf-8").read()
        except Exception:
            continue
        orig = text
        for k, v in repls.items():
            text = text.replace(k, v)
        if text != orig:
            open(path, "w", encoding="utf-8").write(text)
print("tokens replaced")
PY

# Local config snapshot for ops
cat > "$DEST/x-app.json" <<EOF
{
  "app_id": "$APP_ID",
  "label": "$LABEL",
  "application_id": "$APPLICATION_ID",
  "start_url": "$START_URL",
  "allowed_host": "$ALLOWED_HOST",
  "version_code": $VERSION_CODE,
  "version_name": "$VERSION_NAME",
  "packed_at": "$STAMP"
}
EOF

mkdir -p "$OUT_DIR/archive" "$ROOT/upgrade/android"
rm -rf "$OUT_DIR/$NAME"
cp -a "$DEST" "$OUT_DIR/$NAME"
tar -C "$OUT_DIR" -czf "$OUT_DIR/$NAME.tar.gz" "$NAME"
cp -f "$OUT_DIR/$NAME.tar.gz" "$OUT_DIR/archive/${APP_ID}-android-${STAMP}.tar.gz"
rm -rf "$ROOT/upgrade/android/$NAME"
cp -a "$OUT_DIR/$NAME" "$ROOT/upgrade/android/$NAME"
cp -f "$OUT_DIR/$NAME.tar.gz" "$ROOT/upgrade/android/$NAME.tar.gz"

(
  cd "$OUT_DIR/$NAME"
  find . -type f | sed 's|^\./||' | sort > FILE-LIST.txt
)

APK_MSG="(project only — open in Android Studio to build APK)"
if [[ "$ASSEMBLE" == "1" ]]; then
  if [[ -z "${JAVA_HOME:-}" ]] || ! java -version 2>&1 | head -1 | grep -Eq 'version "1[7-9]|version "[2-9][0-9]'; then
    echo "WARN: --assemble needs JDK 17+ (set JAVA_HOME). Skipping build."
  elif [[ -z "${ANDROID_HOME:-}${ANDROID_SDK_ROOT:-}" ]]; then
    echo "WARN: --assemble needs ANDROID_HOME. Skipping build."
  else
    (
      cd "$OUT_DIR/$NAME"
      if [[ ! -f ./gradlew ]]; then
        echo "INFO: generating gradle wrapper…"
        gradle wrapper --gradle-version 8.2 || true
      fi
      if [[ -f ./gradlew ]]; then
        chmod +x ./gradlew
        ./gradlew :app:assembleDebug
        APK=$(find app/build/outputs/apk -name '*.apk' 2>/dev/null | head -1 || true)
        if [[ -n "$APK" ]]; then
          cp -f "$APK" "$OUT_DIR/${APP_ID}-debug.apk"
          APK_MSG="$OUT_DIR/${APP_ID}-debug.apk"
        fi
      fi
    ) || echo "WARN: assemble failed — project still packed for Android Studio"
  fi
fi

echo "==> Package ready"
echo "    dir : $OUT_DIR/$NAME"
echo "    tar : $OUT_DIR/$NAME.tar.gz"
echo "    apk : $APK_MSG"
echo "    open with Android Studio → Sync → Run/Build APK"
echo "    mirror: $ROOT/upgrade/android/$NAME"
