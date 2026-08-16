#!/usr/bin/env bash
# Pack portal WeChat mini-program template with injected config.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SRC="$ROOT/clients/wechat-miniprogram"
OUT_ROOT="${X_MP_OUT_DIR:-$HOME/staging/drupalX/miniprogram}"
API_BASE="${1:-}"
TOKEN="${2:-}"
TENANT="${3:-demo}"

if [[ -z "$API_BASE" ]]; then
  cat <<EOF
Usage: bash scripts/x-pack-miniprogram-portal.sh <api_base> [token] [tenant]
Example: bash scripts/x-pack-miniprogram-portal.sh https://demo.example.com dxc_xxx demo
EOF
  exit 1
fi

STAMP="$(date +%Y%m%d%H%M%S)"
DEST="$OUT_ROOT/portal-mp-$STAMP"
LATEST="$OUT_ROOT/portal-mp-deploy-latest"
mkdir -p "$OUT_ROOT"
rm -rf "$DEST"
mkdir -p "$DEST"
rsync -a "$SRC/" "$DEST/"
cat > "$DEST/config.js" <<EOF
module.exports = {
  apiBase: '${API_BASE%/}',
  token: '${TOKEN}',
  useFixtures: ${TOKEN:+false}${TOKEN:-true}
};
EOF
# Fix useFixtures when token empty -> true, when set -> false
if [[ -n "$TOKEN" ]]; then
  cat > "$DEST/config.js" <<EOF
module.exports = {
  apiBase: '${API_BASE%/}',
  token: '${TOKEN}',
  useFixtures: false
};
EOF
else
  cat > "$DEST/config.js" <<EOF
module.exports = {
  apiBase: '${API_BASE%/}',
  token: '',
  useFixtures: true
};
EOF
fi

rm -rf "$LATEST"
cp -a "$DEST" "$LATEST"
echo "Packed: $DEST"
echo "Latest: $LATEST"
echo "Import Latest folder into WeChat DevTools."
