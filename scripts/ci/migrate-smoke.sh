#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
DRUSH=(vendor/bin/drush)

echo "== dx_migrate smoke =="
"${DRUSH[@]}" pm:enable dx_channel dx_migrate -y >/dev/null
"${DRUSH[@]}" cr >/dev/null

"${DRUSH[@]}" dx:migrate-l1 --dry-run >/tmp/dx-migrate-l1.out
grep -q '"ok": true' /tmp/dx-migrate-l1.out
grep -q '"imported":' /tmp/dx-migrate-l1.out
IMPORTED="$(python3 -c 'import json,sys; print(json.load(open("/tmp/dx-migrate-l1.out"))["imported"])')"
[[ "$IMPORTED" -ge 1 ]]

"${DRUSH[@]}" dx:migrate-l1 --template=gov_news --dry-run >/tmp/dx-migrate-l1-gov.out
grep -q '"ok": true' /tmp/dx-migrate-l1-gov.out

echo "OK imported=$IMPORTED (dry-run fixture + gov_news template)"
