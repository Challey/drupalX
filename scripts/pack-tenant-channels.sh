#!/usr/bin/env bash
# FS5 MVP: pack Flutter + portal mini-program for one tenant Channel.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"

APP="demo"
API_BASE=""
TOKEN=""
TENANT="demo"
NO_CERTS="${X_PACK_NO_CERTS:-0}"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --app=*) APP="${1#*=}"; shift ;;
    --api-base=*) API_BASE="${1#*=}"; shift ;;
    --token=*) TOKEN="${1#*=}"; shift ;;
    --tenant=*) TENANT="${1#*=}"; shift ;;
    --no-certs) NO_CERTS=1; shift ;;
    -h|--help)
      sed -n '2,3p' "$0"
      echo "Usage: bash scripts/pack-tenant-channels.sh --api-base=URL --token=dxc_... [--tenant=demo] [--app=demo] [--no-certs]"
      echo "Rehearsal: X_FLUTTER_OUT_DIR / X_FLUTTER_MIRROR_DIR / X_MP_OUT_DIR redirect the products away from ~/staging and upgrade/"
      exit 0 ;;
    *) echo "Unknown $1" >&2; exit 1 ;;
  esac
done

if [[ -z "$API_BASE" || -z "$TOKEN" ]]; then
  cat <<EOF
Usage:
  bash scripts/pack-tenant-channels.sh \\
    --api-base=https://demo.example.com \\
    --token=dxc_... \\
    --tenant=demo \\
    --app=demo

Creates Flutter shell pack + WeChat mini-program pack for the tenant.
EOF
  exit 1
fi

# Optional cert env from dx_certs. CI rehearsals pass --no-certs (or X_PACK_NO_CERTS=1)
# so that a smoke run never shells out to drush against the live site.
if [[ "$NO_CERTS" != "1" && -x "$ROOT/vendor/bin/drush" ]]; then
  while IFS= read -r line; do
    [[ "$line" == *=* ]] || continue
    export "$line"
  done < <("$ROOT/vendor/bin/drush" dx:certs-packer-env android 2>/dev/null || true)
fi

echo "== pack Flutter =="
bash "$ROOT/scripts/x-pack-flutter.sh" --app="$APP" --api-base="$API_BASE" --token="$TOKEN" --tenant="$TENANT"

echo "== pack mini-program =="
bash "$ROOT/scripts/x-pack-miniprogram-portal.sh" "$API_BASE" "$TOKEN" "$TENANT"

echo "FS5 pack complete."
