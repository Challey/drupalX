#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
DRUSH=(vendor/bin/drush)

echo "== dx_migrate L2 smoke =="
"${DRUSH[@]}" pm:enable dx_channel dx_migrate -y >/dev/null
"${DRUSH[@]}" cr >/dev/null

"${DRUSH[@]}" dx:migrate-l2 --template=gov_news --dry-run >/tmp/dx-migrate-l2-gov.out
grep -q '"ok": true' /tmp/dx-migrate-l2-gov.out
grep -q '"details":' /tmp/dx-migrate-l2-gov.out
DETAILS="$(python3 -c 'import json; print(json.load(open("/tmp/dx-migrate-l2-gov.out"))["details"])')"
[[ "$DETAILS" -ge 1 ]]

"${DRUSH[@]}" dx:migrate-l2 --template=ent_article --dry-run >/tmp/dx-migrate-l2-ent.out
grep -q '"ok": true' /tmp/dx-migrate-l2-ent.out
ENT_DETAILS="$(python3 -c 'import json; print(json.load(open("/tmp/dx-migrate-l2-ent.out"))["details"])')"
[[ "$ENT_DETAILS" -ge 1 ]]

echo "OK gov_details=$DETAILS ent_details=$ENT_DETAILS"
