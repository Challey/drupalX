#!/usr/bin/env bash
# X 项目核心工具：将已登记应用打包为微信小程序目录 + tar.gz
#
# Usage:
#   bash scripts/x-pack-miniprogram.sh --app=car_hailing_assistant
#   bash scripts/x-pack-miniprogram.sh --app=car_hailing_assistant --api-base=https://www.topstar.run
#   bash scripts/x-pack-miniprogram.sh --list
#   bash scripts/x-pack-miniprogram.sh --validate --app=car_hailing_assistant
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PACKER="$ROOT/tools/miniprogram-packer"
APPS_DIR="$PACKER/apps"
OUT_DIR="${X_MP_OUT_DIR:-/home/challey/staging/drupalX/miniprogram}"
APP_ID=""
API_BASE=""
TRAFFIC_API=""
VALIDATE_ONLY=0
LIST_ONLY=0
STAMP="$(date +%Y%m%d_%H%M%S)"

usage() {
  sed -n '2,12p' "$0" | sed 's/^# \{0,1\}//'
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --app=*) APP_ID="${1#*=}" ;;
    --app) APP_ID="${2:-}"; shift ;;
    --api-base=*) API_BASE="${1#*=}" ;;
    --traffic-api=*) TRAFFIC_API="${1#*=}" ;;
    --out=*) OUT_DIR="${1#*=}" ;;
    --validate) VALIDATE_ONLY=1 ;;
    --list) LIST_ONLY=1 ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown arg: $1" >&2; usage; exit 1 ;;
  esac
  shift
done

if [[ "$LIST_ONLY" == "1" ]]; then
  echo "Registered X mini-program apps:"
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
  echo "Hint: bash scripts/x-pack-miniprogram.sh --list" >&2
  exit 1
fi

# Minimal YAML reads (no yq dependency)
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
SOURCE="$(yaml_get source)"
PROJECT_NAME="$(yaml_get project_name)"
APPID="$(yaml_get appid)"
CFG_API="$(yaml_get apiBase)"; CFG_API="${CFG_API:-$(awk '/^config:/{f=1;next} f&&/apiBase:/{sub(/^[^:]+:[[:space:]]*/,""); gsub(/["'\'']/,""); print; exit}' "$MANIFEST")}"
CFG_CLIENT="$(awk '/^config:/{f=1;next} f&&/clientId:/{sub(/^[^:]+:[[:space:]]*/,""); gsub(/["'\'']/,""); print; exit}' "$MANIFEST")"
CFG_TRAFFIC="$(awk '/^config:/{f=1;next} f&&/trafficApiBase:/{sub(/^[^:]+:[[:space:]]*/,""); gsub(/["'\'']/,""); print; exit}' "$MANIFEST")"

resolve_source() {
  local cand
  # Absolute source
  if [[ -n "$SOURCE" && "$SOURCE" == /* && -d "$SOURCE" ]]; then
    echo "$SOURCE"; return
  fi
  # Relative to X (DrupalX) repo root only — never depend on caller cwd
  if [[ -n "$SOURCE" && "$SOURCE" != /* && -d "$ROOT/$SOURCE" ]]; then
    echo "$ROOT/$SOURCE"; return
  fi
  # Manifest fallbacks (absolute or relative to ROOT)
  while IFS= read -r cand; do
    cand="$(echo "$cand" | sed 's/^[[:space:]-]*//;s/[[:space:]]*$//;s/^["'\'']//;s/["'\'']$//')"
    [[ -z "$cand" ]] && continue
    if [[ "$cand" == /* && -d "$cand" ]]; then
      echo "$cand"; return
    fi
    if [[ "$cand" != /* && -d "$ROOT/$cand" ]]; then
      echo "$ROOT/$cand"; return
    fi
  done < <(awk '/^source_fallback:/{f=1;next} f&&/^[^[:space:]-]/{exit} f&&/^-/{print}' "$MANIFEST")
  # Well-known 跑车助手 path
  if [[ -d "${CAR_HAILING_ROOT:-/home/wwwroot/car_hailing}/clients/wechat-miniprogram" ]]; then
    echo "${CAR_HAILING_ROOT:-/home/wwwroot/car_hailing}/clients/wechat-miniprogram"; return
  fi
  return 1
}

SRC="$(resolve_source)" || {
  echo "ERROR: cannot resolve mini-program source for $APP_ID" >&2
  exit 1
}

API_BASE="${API_BASE:-$CFG_API}"
TRAFFIC_API="${TRAFFIC_API:-$CFG_TRAFFIC}"
PROJECT_NAME="${PROJECT_NAME:-$APP_ID}"
APPID="${APPID:-touristappid}"
BRAND="${BRAND:-$LABEL}"

validate_src() {
  local missing=0
  for f in app.js app.json project.config.json; do
    if [[ ! -f "$SRC/$f" ]]; then
      echo "MISSING: $f"; missing=1
    fi
  done
  if [[ ! -d "$SRC/pages" ]]; then
    echo "MISSING: pages/"; missing=1
  fi
  # pages_required from manifest
  while IFS= read -r p; do
    p="$(echo "$p" | sed 's/^[[:space:]-]*//;s/[[:space:]]*$//')"
    [[ -z "$p" ]] && continue
    if [[ ! -f "$SRC/${p}.js" && ! -f "$SRC/${p}.wxml" ]]; then
      # allow directory form pages/foo/foo
      if [[ ! -f "$SRC/${p}.js" ]]; then
        echo "MISSING page: $p"; missing=1
      fi
    fi
  done < <(awk '/^pages_required:/{f=1;next} f&&/^[^[:space:]-]/{exit} f&&/^-/{print}' "$MANIFEST")
  return $missing
}

echo "==> X pack miniprogram"
echo "    app    : $APP_ID ($LABEL)"
echo "    source : $SRC"
echo "    apiBase: ${API_BASE:-"(unchanged)"}"

if ! validate_src; then
  echo "ERROR: validation failed" >&2
  exit 1
fi
echo "    validate: OK"

if [[ "$VALIDATE_ONLY" == "1" ]]; then
  exit 0
fi

NAME="${APP_ID}-mp-deploy-latest"
STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT
DEST="$STAGE/$NAME"
mkdir -p "$DEST"
rsync -a --delete \
  --exclude '.git' \
  --exclude 'node_modules' \
  "$SRC/" "$DEST/"

# Inject config overrides when provided
if [[ -f "$DEST/config.js" ]]; then
  python3 - "$DEST/config.js" "${API_BASE}" "${TRAFFIC_API}" "${CFG_CLIENT}" "${BRAND}" <<'PY'
import re, sys
path, api, traffic, client, brand = sys.argv[1:6]
text = open(path, encoding="utf-8").read()
def repl(key, val, s):
    if val is None or val == "":
        return s
    # module.exports style: key: '...',
    pat = rf"({key}\s*:\s*)(['\"])(.*?)\2"
    if re.search(pat, s):
        return re.sub(pat, lambda m: f"{m.group(1)}{m.group(2)}{val}{m.group(2)}", s, count=1)
    return s
text = repl("apiBase", api, text)
text = repl("trafficApiBase", traffic, text)
text = repl("clientId", client, text)
text = repl("brandName", brand, text)
open(path, "w", encoding="utf-8").write(text)
PY
fi

# Refresh project.config.json name/appid lightly
if [[ -f "$DEST/project.config.json" ]]; then
  python3 - "$DEST/project.config.json" "$PROJECT_NAME" "$APPID" <<'PY'
import json, sys
path, name, appid = sys.argv[1:4]
data = json.load(open(path, encoding="utf-8"))
data["projectname"] = name
data["appid"] = appid
json.dump(data, open(path, "w", encoding="utf-8"), ensure_ascii=False, indent=2)
open(path, "a", encoding="utf-8").write("\n")
PY
fi

mkdir -p "$OUT_DIR/archive" "$ROOT/upgrade/miniprogram"
rm -rf "$OUT_DIR/$NAME"
cp -a "$DEST" "$OUT_DIR/$NAME"
tar -C "$OUT_DIR" -czf "$OUT_DIR/$NAME.tar.gz" "$NAME"
cp -f "$OUT_DIR/$NAME.tar.gz" "$OUT_DIR/archive/${APP_ID}-mp-${STAMP}.tar.gz"
# Mirror under X upgrade tree
rm -rf "$ROOT/upgrade/miniprogram/$NAME"
cp -a "$OUT_DIR/$NAME" "$ROOT/upgrade/miniprogram/$NAME"
cp -f "$OUT_DIR/$NAME.tar.gz" "$ROOT/upgrade/miniprogram/$NAME.tar.gz"

# FILE LIST
(
  cd "$OUT_DIR/$NAME"
  find . -type f | sed 's|^\./||' | sort > FILE-LIST.txt
)

echo "==> Package ready"
echo "    dir : $OUT_DIR/$NAME"
echo "    tar : $OUT_DIR/$NAME.tar.gz"
echo "    open with 微信开发者工具 → 导入该目录"
echo "    mirror: $ROOT/upgrade/miniprogram/$NAME"
