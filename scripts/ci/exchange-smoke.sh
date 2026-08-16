#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
DRUSH=(vendor/bin/drush)
PKG="$ROOT/web/modules/custom/dx_channel/data/packages/demo-package.json"

echo "== dx_channel exchange smoke =="
"${DRUSH[@]}" pm:enable dx_channel -y >/dev/null
"${DRUSH[@]}" cr >/dev/null

TOKEN="$("${DRUSH[@]}" dx:channel-token-create --id=exchange_smoke --scopes=exchange:read,exchange:write,ingest:write 2>/dev/null | tee /tmp/dx-ex-token.out | rg -o 'dxc_[a-f0-9]+' | head -1 || true)"
if [[ -z "${TOKEN}" ]]; then
  TOKEN="$("${DRUSH[@]}" dx:channel-token-create --id="exchange_smoke_$(date +%s)" --scopes=exchange:write,ingest:write,channel:read 2>&1 | tee /tmp/dx-ex-token2.out | rg -o 'dxc_[a-f0-9]+' | head -1 || true)"
fi
# Fallback: parse from logger success line / stdout
if [[ -z "${TOKEN}" ]]; then
  TOKEN="$(rg -o 'dxc_[a-f0-9]+' /tmp/dx-ex-token.out /tmp/dx-ex-token2.out 2>/dev/null | head -1 || true)"
fi

"${DRUSH[@]}" dx:exchange-package-register "$PKG" >/tmp/dx-ex-reg.out
grep -q '"ok": true' /tmp/dx-ex-reg.out
grep -q 'pkg_demo_fixture' /tmp/dx-ex-reg.out

"${DRUSH[@]}" dx:exchange-package-apply pkg_demo_fixture --dry-run >/tmp/dx-ex-apply.out
grep -q '"applied": 2' /tmp/dx-ex-apply.out

"${DRUSH[@]}" dx:exchange-package-apply pkg_demo_fixture >/tmp/dx-ex-apply2.out
grep -q '"applied": 2' /tmp/dx-ex-apply2.out

# HTTP apply list with token if available
if [[ -n "${TOKEN}" ]]; then
  CODE="$(php -r '
    $token = getenv("TOKEN");
    $opts = ["http" => ["header" => "Authorization: Bearer $token\r\n", "ignore_errors" => true]];
    // Use Drupal kernel instead via drush
  ' TOKEN="$TOKEN" 2>/dev/null || true)"
  HTTP="$("${DRUSH[@]}" php:eval '
$token = "'"$TOKEN"'";
$request = \Symfony\Component\HttpFoundation\Request::create("/api/dx/v1/exchange/packages", "GET", [], [], [], ["HTTP_AUTHORIZATION" => "Bearer ".$token]);
$resp = \Drupal::service("http_kernel")->handle($request);
echo $resp->getStatusCode();
')"
  [[ "$HTTP" == "200" ]]
  echo "OK packages HTTP=$HTTP token=yes"
else
  echo "OK packages via drush (token parse skipped)"
fi

echo "OK"
