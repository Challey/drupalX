#!/usr/bin/env bash
# X pack Flutter shell: inject tenant config into clients/flutter_shell copy.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TEMPLATE="$ROOT/clients/flutter_shell"
APPS="$ROOT/tools/flutter-packer/apps"
OUT_ROOT="${X_FLUTTER_OUT_DIR:-$HOME/staging/drupalX/flutter}"

APP=""
API_BASE=""
TOKEN=""
TENANT="demo"
LIST=0
VALIDATE=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --list) LIST=1; shift ;;
    --validate) VALIDATE=1; shift ;;
    --app=*) APP="${1#*=}"; shift ;;
    --api-base=*) API_BASE="${1#*=}"; shift ;;
    --token=*) TOKEN="${1#*=}"; shift ;;
    --tenant=*) TENANT="${1#*=}"; shift ;;
    -h|--help)
      cat <<EOF
Usage:
  bash scripts/x-pack-flutter.sh --list
  bash scripts/x-pack-flutter.sh --validate --app=demo
  bash scripts/x-pack-flutter.sh --app=demo --api-base=https://demo.example.com --token=dxc_...
EOF
      exit 0
      ;;
    *) echo "Unknown arg: $1" >&2; exit 1 ;;
  esac
done

if [[ "$LIST" -eq 1 ]]; then
  ls -1 "$APPS"/*.manifest.yml 2>/dev/null | xargs -n1 basename | sed 's/\.manifest\.yml$//'
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

if [[ "$VALIDATE" -eq 1 ]]; then
  test -f "$TEMPLATE/pubspec.yaml"
  test -f "$TEMPLATE/lib/main.dart"
  echo "OK validate $APP"
  exit 0
fi

if [[ -z "$API_BASE" ]]; then
  echo "--api-base= is required for pack" >&2
  exit 1
fi

DISPLAY_NAME="$(grep -E '^display_name:' "$MANIFEST" | head -1 | sed 's/^display_name:[[:space:]]*//' | tr -d '"')"
SHELL_VER="$(grep -E '^shell_version:' "$MANIFEST" | head -1 | sed 's/^shell_version:[[:space:]]*//' | tr -d '"')"
DISPLAY_NAME="${DISPLAY_NAME:-DrupalX}"
SHELL_VER="${SHELL_VER:-1.0.0}"
TENANT_ID="$(grep -E '^id:' "$MANIFEST" | head -1 | sed 's/^id:[[:space:]]*//' | tr -d '"')"
TENANT_ID="${TENANT:-$TENANT_ID}"

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
  "poll_seconds": 60
}
EOF

# Prefer injected config over example in pubspec assets (already listed).
cat > "$DEST/PACK.md" <<EOF
# $DISPLAY_NAME Flutter pack

1. Install Flutter SDK 3.16+
2. \`cd\` this directory
3. If android/ios folders missing: \`flutter create --project-name dx_flutter_shell --org com.drupalx --platforms=android,ios .\`
4. \`flutter pub get\`
5. \`flutter run\` or \`flutter build apk\` / \`flutter build ipa\` (iOS needs Apple account)

Injected: api_base=$API_BASE tenant=$TENANT_ID
Token is in assets/config/shell.json — treat as secret.
EOF

rm -rf "$LATEST"
cp -a "$DEST" "$LATEST"
# Mirror into repo staging path (gitignored typically)
MIRROR="$ROOT/upgrade/flutter/${APP}-flutter-deploy-latest"
mkdir -p "$(dirname "$MIRROR")"
rm -rf "$MIRROR"
cp -a "$DEST" "$MIRROR" 2>/dev/null || true

TAR="$OUT_ROOT/${APP}-flutter-$STAMP.tar.gz"
tar -C "$OUT_ROOT" -czf "$TAR" "$(basename "$DEST")"
echo "Packed: $DEST"
echo "Latest: $LATEST"
echo "Archive: $TAR"
