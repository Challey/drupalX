#!/usr/bin/env bash
# Pack portal WeChat mini-program template with injected config.
#
# Usage: bash scripts/x-pack-miniprogram-portal.sh <api_base> [token] [tenant]
#        bash scripts/x-pack-miniprogram-portal.sh --list | --help
# Any `--flag` other than --list/--help is rejected instead of being packed as
# an api_base (H4: an unknown option must never produce a deliverable).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SRC="$ROOT/clients/wechat-miniprogram"
OUT_ROOT="${X_MP_OUT_DIR:-$HOME/staging/drupalX/miniprogram}"
API_BASE="${1:-}"
TOKEN="${2:-}"
TENANT="${3:-demo}"

usage() {
  cat <<'EOF'
Usage: bash scripts/x-pack-miniprogram-portal.sh <api_base> [token] [tenant]
Example: bash scripts/x-pack-miniprogram-portal.sh https://demo.example.com dxc_xxx demo
  --list   list the source files that would be packed, then exit
  --help   show this help
EOF
}

case "$API_BASE" in
  --help|-h) usage; exit 0 ;;
  --list)
    # Same registry surface as the other packers; the portal template is not a
    # tenant manifest, it is the in-repo shell, so list its files.
    echo "Portal mini-program source: $SRC"
    (cd "$SRC" && find . -type f | sed 's|^\./||' | sort)
    exit 0
    ;;
  -*)
    echo "Unknown arg: $API_BASE" >&2
    usage >&2
    exit 1
    ;;
esac

if [[ -z "$API_BASE" ]]; then
  usage >&2
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

# H4: every packer ships the same FILE-LIST.txt manifest of what went into the
# deliverable, generated inside $DEST so it also lands in the "latest" copy.
(
  cd "$DEST"
  find . -type f | sed 's|^\./||' | grep -v '^FILE-LIST.txt$' | sort > FILE-LIST.txt
) || true

rm -rf "$LATEST"
cp -a "$DEST" "$LATEST"
echo "Packed: $DEST"
echo "Latest: $LATEST"
echo "File list: $DEST/FILE-LIST.txt"
echo "Import Latest folder into WeChat DevTools."
