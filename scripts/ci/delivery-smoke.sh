#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
DRUSH=(vendor/bin/drush)

echo "== dx_delivery smoke =="
"${DRUSH[@]}" pm:enable dx_delivery dx_migrate dx_opinion -y >/dev/null
"${DRUSH[@]}" cr >/dev/null

UNIQUE="dxsmoke$(date +%s | tail -c 5)"
MSG='gov portal, steady theme, need miniprogram and opinion'
"${DRUSH[@]}" dx:delivery-from-chat "$MSG" --machine-name="$UNIQUE" >/tmp/dx-delivery-from-chat.out
ID="$("${DRUSH[@]}" dx:delivery-list 2>/dev/null | awk -v m="$UNIQUE" '$0 ~ m {print $1; exit}')"
if [[ -z "$ID" ]]; then
  echo "Failed to resolve blueprint id" >&2
  cat /tmp/dx-delivery-from-chat.out >&2
  exit 1
fi
echo "blueprint id=$ID machine=$UNIQUE"

"${DRUSH[@]}" dx:delivery-run "$ID" --skip-provision --skip-pack >/tmp/dx-delivery-run.out
grep -q '"passed": true' /tmp/dx-delivery-run.out
grep -q '"id": "capabilities"' /tmp/dx-delivery-run.out
STATUS="$("${DRUSH[@]}" dx:delivery-list 2>/dev/null | awk -v id="$ID" '$1==id {print $2; exit}')"
echo "status=$STATUS"
[[ "$STATUS" == "completed" ]]

echo "OK"
