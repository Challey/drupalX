#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
DRUSH=(vendor/bin/drush)

echo "== dx_channel audit smoke =="
"${DRUSH[@]}" pm:enable dx_channel -y >/dev/null
"${DRUSH[@]}" cr >/dev/null

ID="audit_smoke_$(date +%s)"
"${DRUSH[@]}" dx:channel-token-create --id="$ID" --scopes=channel:read >/tmp/dx-audit-token.out 2>&1
TOKEN="$(grep -Eo 'dxc_[a-f0-9]+' /tmp/dx-audit-token.out | head -1)"
if [[ -z "${TOKEN}" ]]; then
  echo "Failed to create token" >&2
  cat /tmp/dx-audit-token.out >&2
  exit 1
fi

CODE="$("${DRUSH[@]}" php:eval "echo \\Drupal::service('http_kernel')->handle(\\Symfony\\Component\\HttpFoundation\\Request::create('/api/dx/v1/channel/site', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer ${TOKEN}']))->getStatusCode();")"
[[ "$CODE" == "200" ]]

"${DRUSH[@]}" dx:channel-audit --limit=10 >/tmp/dx-audit.out
# JSON may escape slashes as \/
grep -Eq 'channel(\\?/|\\\\/)site' /tmp/dx-audit.out || grep -q 'channel' /tmp/dx-audit.out
python3 -c 'import json; rows=json.load(open("/tmp/dx-audit.out")); assert any("channel/site" in (r.get("route") or "").replace("\\/","/") for r in rows)'

echo "OK audit logged status=$CODE"
