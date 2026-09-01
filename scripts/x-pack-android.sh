#!/usr/bin/env bash
# X 项目核心工具：将已登记应用打包为 Android WebView 工程（可导入 Android Studio）
#
# Usage:
#   bash scripts/x-pack-android.sh --app=car_hailing_assistant
#   bash scripts/x-pack-android.sh --app=car_hailing_assistant --start-url=https://www.topstar.run/driver
#   bash scripts/x-pack-android.sh --list
#   bash scripts/x-pack-android.sh --validate --app=car_hailing_assistant
#   bash scripts/x-pack-android.sh --app=car_hailing_assistant --assemble   # needs JDK17 + SDK
#   # shell 1.3.0 增量（默认值 = 清单 / DX-PACK-MANIFEST schema = 今天线上行为）：
#   bash scripts/x-pack-android.sh --app=demo --shell-version=1.3.0
#   bash scripts/x-pack-android.sh --app=demo --capability=location,photo_upload
#   bash scripts/x-pack-android.sh --app=demo --payment-host=pay.demo.com:domain   # 可重复，追加不删除
#   bash scripts/x-pack-android.sh --app=demo --mirror-dir=/tmp/android-mirror     # CI / 演练用
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PACKER="$ROOT/tools/android-packer"
APPS_DIR="$PACKER/apps"
TEMPLATE="$PACKER/template"
CODEGEN="$PACKER/lib/shell_codegen.py"
MANIFEST_TOOL="$ROOT/tools/packer/validate_manifest.py"
OUT_DIR="${X_ANDROID_OUT_DIR:-$HOME/staging/drupalX/android}"
MIRROR_DIR="${X_ANDROID_MIRROR_DIR:-$ROOT/upgrade/android}"
APP_ID=""
START_URL=""
ALLOWED_HOST=""
APPLICATION_ID=""
SHELL_VERSION=""
CAPABILITIES=""
VALIDATE_ONLY=0
LIST_ONLY=0
ASSEMBLE=0
ADD_PAYMENT_HOSTS=()
STAMP="$(date +%Y%m%d_%H%M%S)"

usage() {
  sed -n '2,19p' "$0" | sed 's/^# \{0,1\}//'
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --app=*) APP_ID="${1#*=}" ;;
    --app) APP_ID="${2:-}"; shift ;;
    --start-url=*) START_URL="${1#*=}" ;;
    --allowed-host=*) ALLOWED_HOST="${1#*=}" ;;
    --application-id=*) APPLICATION_ID="${1#*=}" ;;
    --shell-version=*) SHELL_VERSION="${1#*=}" ;;
    --capability=*) CAPABILITIES="${CAPABILITIES:+$CAPABILITIES,}${1#*=}" ;;
    --capability) CAPABILITIES="${CAPABILITIES:+$CAPABILITIES,}${2:-}"; shift ;;
    --payment-host=*) ADD_PAYMENT_HOSTS+=("${1#*=}") ;;
    --payment-host) ADD_PAYMENT_HOSTS+=("${2:-}"); shift ;;
    --mirror-dir=*) MIRROR_DIR="${1#*=}" ;;
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
  # H4: one source of truth for the app registry - tools/packer/manifest-schema.json.
  echo "Registered X Android apps:"
  python3 "$MANIFEST_TOOL" --list --platform=android --names-only
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

# ---------------------------------------------------------------------------
# H4 gate: the manifest must satisfy DX-PACK-MANIFEST (tools/packer/) first.
# ---------------------------------------------------------------------------
if [[ ! -f "$MANIFEST_TOOL" ]]; then
  echo "ERROR: manifest schema gate missing: $MANIFEST_TOOL" >&2
  exit 1
fi
echo "    schema  : DX-PACK-MANIFEST / android"
python3 "$MANIFEST_TOOL" --platform=android --file="$MANIFEST"

STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT
CFG="$STAGE/pack-config.json"
RESOLVE=(--resolve --platform=android --file="$MANIFEST")
[[ -n "$START_URL" ]] && RESOLVE+=(--override "start_url=$START_URL")
[[ -n "$ALLOWED_HOST" ]] && RESOLVE+=(--override "allowed_host=$ALLOWED_HOST")
[[ -n "$APPLICATION_ID" ]] && RESOLVE+=(--override "application_id=$APPLICATION_ID")
[[ -n "$SHELL_VERSION" ]] && RESOLVE+=(--override "shell_version=$SHELL_VERSION")
[[ -n "$CAPABILITIES" ]] && RESOLVE+=(--override "capabilities=$CAPABILITIES")
for extra_host in "${ADD_PAYMENT_HOSTS[@]}"; do
  RESOLVE+=(--add-payment-host "$extra_host")
done
if ! python3 "$MANIFEST_TOOL" "${RESOLVE[@]}" > "$CFG"; then
  echo "ERROR: could not resolve the pack config for $APP_ID" >&2
  exit 1
fi
cfg_get() {
  python3 -c '
import json, sys
value = json.load(open(sys.argv[1])).get(sys.argv[2], "")
if isinstance(value, list):
    value = ",".join(str(v) for v in value)
print("" if value is None else value)
' "$CFG" "$1"
}

LABEL="$(cfg_get label)"
M_APP_ID="$(cfg_get application_id)"
M_START="$(cfg_get start_url)"
M_HOST="$(cfg_get allowed_host)"
VERSION_CODE="$(cfg_get version_code)"
VERSION_NAME="$(cfg_get version_name)"
SHELL_VERSION="$(cfg_get shell_version)"
CAP_LIST="$(cfg_get capabilities)"

APPLICATION_ID="$M_APP_ID"
START_URL="$M_START"
ALLOWED_HOST="$M_HOST"

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
echo "    shell   : $SHELL_VERSION (app $VERSION_NAME / code $VERSION_CODE)"
echo "    caps    : $CAP_LIST"
echo "    payhosts: $(python3 "$CODEGEN" payment-csv --config "$CFG")"

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
DEST="$STAGE/$NAME"
mkdir -p "$DEST"
rsync -a --delete \
  --exclude '.gradle' \
  --exclude 'build' \
  --exclude 'app/build' \
  --exclude '.idea' \
  "$TEMPLATE/" "$DEST/"

# Tokens + payment whitelist wiring + capability block + x-app.json snapshot
python3 "$CODEGEN" apply --dest "$DEST" --config "$CFG" --stamp "$STAMP"

# Per-app launcher icons (apps/<id>/res or apps/<id>/icon.png)
apply_app_icons() {
  local dest_app="$1/app/src/main/res"
  local app_res="$APPS_DIR/${APP_ID}/res"
  local app_icon="$APPS_DIR/${APP_ID}/icon.png"
  if [[ -d "$app_res" ]]; then
    echo "    icons   : $app_res"
    # Drop template adaptive vector so brand mipmaps win when densities differ.
    rm -f "$dest_app/drawable/ic_launcher_fg.xml" "$dest_app/drawable/ic_launcher_bg.xml" \
      "$dest_app/mipmap-anydpi-v26/ic_launcher.xml" 2>/dev/null || true
    rsync -a "$app_res/" "$dest_app/"
    # Incomplete brand res (drawable only) leaves blank/default icons — fall through to icon.png.
    if [[ -f "$dest_app/mipmap-anydpi-v26/ic_launcher.xml" ]] \
      || [[ -f "$dest_app/mipmap-mdpi/ic_launcher.png" ]] \
      || [[ -f "$dest_app/mipmap-hdpi/ic_launcher.png" && ! -f "$app_icon" ]]; then
      # Keep brand mipmaps when present; if only template hdpi remains and icon.png exists, regenerate.
      if [[ -f "$dest_app/mipmap-anydpi-v26/ic_launcher.xml" ]] \
        || [[ -f "$dest_app/mipmap-mdpi/ic_launcher.png" ]]; then
        return 0
      fi
    fi
    echo "    icons   : brand res incomplete; will generate from icon.png if available"
  fi
  if [[ -f "$app_icon" ]] && command -v convert >/dev/null 2>&1; then
    echo "    icons   : generating from $app_icon"
    local dens px
    for dens in mdpi:48 hdpi:72 xhdpi:96 xxhdpi:144 xxxhdpi:192; do
      px="${dens##*:}"
      dens="${dens%%:*}"
      mkdir -p "$dest_app/mipmap-$dens"
      convert "$app_icon" -resize "${px}x${px}" -background none -gravity center \
        -extent "${px}x${px}" "$dest_app/mipmap-$dens/ic_launcher.png"
      cp -f "$dest_app/mipmap-$dens/ic_launcher.png" "$dest_app/mipmap-$dens/ic_launcher_round.png"
    done
    mkdir -p "$dest_app/drawable" "$dest_app/mipmap-anydpi-v26"
    rm -f "$dest_app/drawable/ic_launcher_fg.xml"
    convert "$app_icon" -resize 648x648 -background none -gravity center \
      -extent 1080x1080 "$dest_app/drawable/ic_launcher_fg.png"
    local bg
    bg="#$(convert "$app_icon" -format '%[hex:u.p{20,20}]' info: | cut -c1-6)"
    cat > "$dest_app/drawable/ic_launcher_bg.xml" <<XMLEOF
<?xml version="1.0" encoding="utf-8"?>
<shape xmlns:android="http://schemas.android.com/apk/res/android" android:shape="rectangle">
    <solid android:color="$bg" />
</shape>
XMLEOF
    cat > "$dest_app/mipmap-anydpi-v26/ic_launcher.xml" <<'XMLEOF'
<?xml version="1.0" encoding="utf-8"?>
<adaptive-icon xmlns:android="http://schemas.android.com/apk/res/android">
    <background android:drawable="@drawable/ic_launcher_bg" />
    <foreground android:drawable="@drawable/ic_launcher_fg" />
</adaptive-icon>
XMLEOF
    cp -f "$dest_app/mipmap-anydpi-v26/ic_launcher.xml" "$dest_app/mipmap-anydpi-v26/ic_launcher_round.xml"
    return 0
  fi
  echo "    icons   : template default (place apps/${APP_ID}/icon.png to override)"
}
apply_app_icons "$DEST"

# Local config snapshot is written by shell_codegen (x-app.json).

mkdir -p "$OUT_DIR/archive" "$MIRROR_DIR"
# FILE-LIST.txt is written before the copies so the directory, the tar and the
# mirror all ship the identical manifest (previously only the loose dir had it).
(
  cd "$DEST"
  find . -type f ! -name FILE-LIST.txt | sed 's|^\./||' | sort > FILE-LIST.txt
)
rm -rf "$OUT_DIR/$NAME"
cp -a "$DEST" "$OUT_DIR/$NAME"
tar -C "$OUT_DIR" -czf "$OUT_DIR/$NAME.tar.gz" "$NAME"
cp -f "$OUT_DIR/$NAME.tar.gz" "$OUT_DIR/archive/${APP_ID}-android-${STAMP}.tar.gz"
rm -rf "$MIRROR_DIR/$NAME"
cp -a "$OUT_DIR/$NAME" "$MIRROR_DIR/$NAME"
cp -f "$OUT_DIR/$NAME.tar.gz" "$MIRROR_DIR/$NAME.tar.gz"

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
echo "    mirror: $MIRROR_DIR/$NAME"
