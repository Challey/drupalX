#!/usr/bin/env bash
# X pack Flutter shell: inject tenant config into clients/flutter_shell copy.
#
# Usage:
#   bash scripts/x-pack-flutter.sh --list
#   bash scripts/x-pack-flutter.sh --validate --app=demo
#   bash scripts/x-pack-flutter.sh --app=demo --api-base=https://demo.example.com --token=dxc_...
#   # H4 起：清单先过 DX-PACK-MANIFEST schema，注入的 shell.json 带 catalog_version
#   bash scripts/x-pack-flutter.sh --app=demo --layout-fixture=assets/fixtures/app_layout_v2.json
#   bash scripts/x-pack-flutter.sh --app=demo --mirror-dir=/tmp/flutter-mirror --out=/tmp/flutter-out
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TEMPLATE="$ROOT/clients/flutter_shell"
APPS="$ROOT/tools/flutter-packer/apps"
MANIFEST_TOOL="$ROOT/tools/packer/validate_manifest.py"
OUT_ROOT="${X_FLUTTER_OUT_DIR:-$HOME/staging/drupalX/flutter}"
MIRROR_DIR="${X_FLUTTER_MIRROR_DIR:-$ROOT/upgrade/flutter}"

APP=""
API_BASE=""
TOKEN=""
TENANT=""
LAYOUT_FIXTURE=""
LIST=0
VALIDATE=0

usage() {
  sed -n '3,12p' "$0" | sed 's/^# \{0,1\}//'
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --list) LIST=1; shift ;;
    --validate) VALIDATE=1; shift ;;
    --app=*) APP="${1#*=}"; shift ;;
    --api-base=*) API_BASE="${1#*=}"; shift ;;
    --token=*) TOKEN="${1#*=}"; shift ;;
    --tenant=*) TENANT="${1#*=}"; shift ;;
    --layout-fixture=*) LAYOUT_FIXTURE="${1#*=}"; shift ;;
    --shell-version=*) SHELL_VERSION_OVERRIDE="${1#*=}"; shift ;;
    --mirror-dir=*) MIRROR_DIR="${1#*=}"; shift ;;
    --out=*) OUT_ROOT="${1#*=}"; shift ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown arg: $1" >&2; usage; exit 1 ;;
  esac
done

if [[ "$LIST" -eq 1 ]]; then
  # H4: same registry as the gate and the docs - tools/packer/manifest-schema.json.
  echo "Registered X Flutter apps:"
  python3 "$MANIFEST_TOOL" --list --platform=flutter --names-only
  exit 0
fi

if [[ -z "$APP" ]]; then
  echo "--app= is required" >&2
  exit 1
fi

MANIFEST="$APPS/$APP.manifest.yml"
if [[ ! -f "$MANIFEST" ]]; then
  echo "Missing manifest: $MANIFEST" >&2
  exit 1
fi
if [[ ! -f "$MANIFEST_TOOL" ]]; then
  echo "ERROR: manifest schema gate missing: $MANIFEST_TOOL" >&2
  exit 1
fi

# ---------------------------------------------------------------------------
# H4 gate: validate the manifest against the shared three-end schema, then
# resolve one flat config (schema defaults <- manifest <- CLI overrides).
# ---------------------------------------------------------------------------
echo "    schema  : DX-PACK-MANIFEST / flutter"
python3 "$MANIFEST_TOOL" --platform=flutter --file="$MANIFEST"

CFG="$(mktemp)"
trap 'rm -f "$CFG"' EXIT
RESOLVE=(--resolve --platform=flutter --file="$MANIFEST")
[[ -n "$TENANT" ]] && RESOLVE+=(--override "app_id=$TENANT")
[[ -n "${SHELL_VERSION_OVERRIDE:-}" ]] && RESOLVE+=(--override "shell_version=$SHELL_VERSION_OVERRIDE")
if ! python3 "$MANIFEST_TOOL" "${RESOLVE[@]}" > "$CFG"; then
  echo "ERROR: could not resolve the pack config for $APP" >&2
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

DISPLAY_NAME="$(cfg_get display_name)"
SHELL_VER="$(cfg_get shell_version)"
CATALOG_VER="$(cfg_get catalog_version)"
LAYOUT_PROFILE="$(cfg_get layout_profile)"
APPLICATION_ID="$(cfg_get application_id)"
BUNDLE_ID="$(cfg_get bundle_id)"
TENANT_ID="${TENANT:-$(cfg_get app_id)}"
DISPLAY_NAME="${DISPLAY_NAME:-DrupalX}"
SHELL_VER="${SHELL_VER:-1.0.0}"
LAYOUT_FIXTURE="${LAYOUT_FIXTURE:-assets/fixtures/app_layout_gov.json}"

case "$LAYOUT_FIXTURE" in
  assets/fixtures/*.json) ;;
  *) echo "ERROR: --layout-fixture must be a path under assets/fixtures/, got $LAYOUT_FIXTURE" >&2; exit 1 ;;
esac

if [[ "$VALIDATE" -eq 1 ]]; then
  test -f "$TEMPLATE/pubspec.yaml"
  test -f "$TEMPLATE/lib/main.dart"
  test -f "$TEMPLATE/assets/config/component_catalog.json"
  test -f "$TEMPLATE/$LAYOUT_FIXTURE" || {
    echo "ERROR: layout fixture $LAYOUT_FIXTURE is not bundled in the shell" >&2
    exit 1
  }
  echo "OK validate $APP (shell $SHELL_VER / catalogue $CATALOG_VER / profile $LAYOUT_PROFILE)"
  exit 0
fi

if [[ -z "$API_BASE" ]]; then
  echo "--api-base= is required for pack" >&2
  exit 1
fi

STAMP="$(date +%Y%m%d%H%M%S)"
DEST="$OUT_ROOT/${APP}-flutter-$STAMP"
LATEST="$OUT_ROOT/${APP}-flutter-deploy-latest"
mkdir -p "$OUT_ROOT"
rm -rf "$DEST"
mkdir -p "$DEST"
# Copy sources (exclude build artifacts if any)
rsync -a --exclude='.dart_tool' --exclude='build' --exclude='.idea' \
  "$TEMPLATE/" "$DEST/"

mkdir -p "$DEST/assets/config"
cat > "$DEST/assets/config/shell.json" <<EOF
{
  "api_base": "${API_BASE%/}",
  "tenant_id": "$TENANT_ID",
  "bearer_token": "$TOKEN",
  "shell_version": "$SHELL_VER",
  "use_fixtures": false,
  "poll_seconds": 60,
  "layout_fixture": "$LAYOUT_FIXTURE"
}
EOF

# Prefer injected config over example in pubspec assets (already listed).
cat > "$DEST/PACK.md" <<EOF
# $DISPLAY_NAME Flutter pack

Shell $SHELL_VER · component catalogue v$CATALOG_VER · layout profile $LAYOUT_PROFILE
Application id $APPLICATION_ID · bundle $BUNDLE_ID · tenant $TENANT_ID

1. Install Flutter SDK 3.16+
2. \`cd\` this directory
3. If android/ios folders missing: \`flutter create --project-name dx_flutter_shell --org com.drupalx --platforms=android,ios .\`
4. \`flutter pub get\`
5. Offline gate before you build: \`flutter test\`
   (component_catalog_test.dart + layout_engine_test.dart + field_contract_test.dart;
   the last one skips when clients/field-contract.json is not in this pack)
6. \`flutter run\` or \`flutter build apk\` / \`flutter build ipa\` (iOS needs Apple account)

Injected: api_base=$API_BASE tenant=$TENANT_ID layout_fixture=$LAYOUT_FIXTURE
The rendered layout comes from /api/dx/v1/channel/app-layout; unknown component
-types degrade to the catalogue's fallback and never crash the shell.
Token is in assets/config/shell.json — treat as secret.
EOF

rm -rf "$LATEST"
# FILE-LIST.txt first: the delivered directory, the tar and the mirror stay
# byte-identical (same rule as the Android and mini-program packers).
(
  cd "$DEST"
  find . -type f ! -name FILE-LIST.txt \
    | sed 's|^\./||' | grep -v '^\.dart_tool/' | sort > FILE-LIST.txt
)
cp -a "$DEST" "$LATEST"
# Mirror into the repo staging path (gitignored); override with --mirror-dir.
MIRROR="$MIRROR_DIR/${APP}-flutter-deploy-latest"
mkdir -p "$(dirname "$MIRROR")"
rm -rf "$MIRROR"
cp -a "$DEST" "$MIRROR" 2>/dev/null || true

TAR="$OUT_ROOT/${APP}-flutter-$STAMP.tar.gz"
tar -C "$OUT_ROOT" -czf "$TAR" "$(basename "$DEST")"
echo "Packed: $DEST"
echo "Latest: $LATEST"
echo "Archive: $TAR"
echo "Mirror: $MIRROR"
