#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
DRUSH=(vendor/bin/drush)

echo "== dx_channel webhook smoke =="
"${DRUSH[@]}" pm:enable dx_channel -y >/dev/null
"${DRUSH[@]}" cr >/dev/null
"${DRUSH[@]}" dx:webhook-register "https://example.com/hooks/dx" --events=resource.published >/tmp/dx-wh-reg.out
grep -q '"id":' /tmp/dx-wh-reg.out
"${DRUSH[@]}" dx:webhook-test >/tmp/dx-wh-test.out
grep -q '"sent":' /tmp/dx-wh-test.out
SENT="$(python3 -c 'import json; print(json.load(open("/tmp/dx-wh-test.out"))["sent"])')"
[[ "$SENT" -ge 1 ]]
# Rate limiter should still allow a few
"${DRUSH[@]}" dx:webhook-test >/tmp/dx-wh-test2.out
grep -q '"sent":' /tmp/dx-wh-test2.out
"${DRUSH[@]}" dx:webhook-verify >/tmp/dx-wh-verify.out
grep -q '"ok": true' /tmp/dx-wh-verify.out
echo "OK sent=$SENT verify=ok"
