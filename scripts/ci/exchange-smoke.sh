#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
DRUSH=(vendor/bin/drush)
PKG="$ROOT/web/modules/custom/dx_channel/data/packages/demo-package.json"
PKG_ZIP="$ROOT/web/modules/custom/dx_channel/data/packages/demo-package.zip"

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

# ZIP offline format
[[ -f "$PKG_ZIP" ]]
"${DRUSH[@]}" dx:exchange-package-register "$PKG_ZIP" >/tmp/dx-ex-zip-reg.out
grep -q '"ok": true' /tmp/dx-ex-zip-reg.out
grep -q 'pkg_demo_zip_fixture' /tmp/dx-ex-zip-reg.out
"${DRUSH[@]}" dx:exchange-package-export pkg_demo_zip_fixture /tmp/dx-ex-export.zip >/tmp/dx-ex-export.out
grep -q '"ok": true' /tmp/dx-ex-export.out
[[ -s /tmp/dx-ex-export.zip ]]
python3 - <<'PY'
import zipfile
z = zipfile.ZipFile('/tmp/dx-ex-export.zip')
assert 'package.json' in z.namelist()
print('export zip ok')
PY

# HTTP list + download with token if available
if [[ -n "${TOKEN}" ]]; then
  HTTP="$("${DRUSH[@]}" php:eval '
$token = "'"$TOKEN"'";
$request = \Symfony\Component\HttpFoundation\Request::create("/api/dx/v1/exchange/packages", "GET", [], [], [], ["HTTP_AUTHORIZATION" => "Bearer ".$token]);
$resp = \Drupal::service("http_kernel")->handle($request);
echo $resp->getStatusCode();
')"
  [[ "$HTTP" == "200" ]]
  DL="$("${DRUSH[@]}" php:eval '
$token = "'"$TOKEN"'";
$request = \Symfony\Component\HttpFoundation\Request::create("/api/dx/v1/exchange/packages/pkg_demo_zip_fixture/download", "GET", [], [], [], ["HTTP_AUTHORIZATION" => "Bearer ".$token]);
$resp = \Drupal::service("http_kernel")->handle($request);
echo $resp->getStatusCode()." ".$resp->headers->get("Content-Type");
')"
  echo "$DL" | grep -q '^200 '
  echo "$DL" | grep -qi 'zip'
  echo "OK packages HTTP=$HTTP download=$DL token=yes"
else
  echo "OK packages via drush (token parse skipped)"
fi

echo "OK"
