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

TOKEN="$("${DRUSH[@]}" dx:channel-token-create --id=exchange_smoke --scopes=exchange:read,exchange:write,ingest:write 2>/dev/null | tee /tmp/dx-ex-token.out | grep -oE 'dxc_[a-f0-9]+' | head -1 || true)"
if [[ -z "${TOKEN}" ]]; then
  TOKEN="$("${DRUSH[@]}" dx:channel-token-create --id="exchange_smoke_$(date +%s)" --scopes=exchange:write,ingest:write,channel:read 2>&1 | tee /tmp/dx-ex-token2.out | grep -oE 'dxc_[a-f0-9]+' | head -1 || true)"
fi
# Fallback: parse from logger success line / stdout
if [[ -z "${TOKEN}" ]]; then
  TOKEN="$(grep -hoE 'dxc_[a-f0-9]+' /tmp/dx-ex-token.out /tmp/dx-ex-token2.out 2>/dev/null | head -1 || true)"
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

# ---------------------------------------------------------------------------
# G3 offline package integrity (roadmap Phase G).
# Every exported ZIP must carry a sha256 ledger and verification must be
# enforceable both before registration and at apply time.
# ---------------------------------------------------------------------------
python3 -c "import zipfile;assert 'checksums.sha256' in zipfile.ZipFile('/tmp/dx-ex-export.zip').namelist();print('ledger shipped')"

"${DRUSH[@]}" dx:exchange-package-verify-archive /tmp/dx-ex-export.zip >/tmp/dx-ex-verify.out
grep -q '"ok": true' /tmp/dx-ex-verify.out
grep -q '"code": "DX.OK"' /tmp/dx-ex-verify.out
grep -q '"package_id": "pkg_demo_zip_fixture"' /tmp/dx-ex-verify.out

# Registered package: the sealed checksum must match the stored content.
"${DRUSH[@]}" dx:exchange-package-verify pkg_demo_zip_fixture >/tmp/dx-ex-verify-pkg.out
grep -q '"status": "verified"' /tmp/dx-ex-verify-pkg.out
grep -q '"apply_allowed": true' /tmp/dx-ex-verify-pkg.out
"${DRUSH[@]}" dx:exchange-package-verify pkg_demo_fixture >/tmp/dx-ex-verify-inline.out
grep -q '"apply_allowed": true' /tmp/dx-ex-verify-inline.out

# Tampered archive (one word changed, original ledger kept) must be refused at
# both the pre-flight check and registration, with a stable error code.
python3 - <<'PY'
import shutil, zipfile
shutil.copyfile('/tmp/dx-ex-export.zip', '/tmp/dx-ex-tampered.zip')
z = zipfile.ZipFile('/tmp/dx-ex-export.zip')
body = z.read('package.json').decode('utf-8')
ledger = z.read('checksums.sha256').decode('utf-8')
z.close()
out = zipfile.ZipFile('/tmp/dx-ex-tampered.zip', 'w', zipfile.ZIP_DEFLATED)
out.writestr('package.json', body.replace('pkg_demo_zip_fixture', 'pkg_demo_zip_tampered'))
out.writestr('checksums.sha256', ledger)
out.close()
print('tampered zip built')
PY
"${DRUSH[@]}" dx:exchange-package-verify-archive /tmp/dx-ex-tampered.zip >/tmp/dx-ex-tampered.out 2>&1 || true
grep -q '"ok": false' /tmp/dx-ex-tampered.out
grep -q 'DX.EXCHANGE.CHECKSUM_MISMATCH' /tmp/dx-ex-tampered.out
"${DRUSH[@]}" dx:exchange-package-register /tmp/dx-ex-tampered.zip >/tmp/dx-ex-tampered-reg.out 2>&1 || true
grep -q '"ok": false' /tmp/dx-ex-tampered-reg.out
grep -q 'DX.EXCHANGE.CHECKSUM_MISMATCH' /tmp/dx-ex-tampered-reg.out

# An archive with no ledger at all is refused with the MISSING code.
python3 - <<'PY'
import json, zipfile
z = zipfile.ZipFile('/tmp/dx-ex-export.zip')
body = z.read('package.json').decode('utf-8')
z.close()
out = zipfile.ZipFile('/tmp/dx-ex-unsigned.zip', 'w', zipfile.ZIP_DEFLATED)
out.writestr('package.json', body)
out.close()
print('unsigned zip built')
PY
"${DRUSH[@]}" dx:exchange-package-verify-archive /tmp/dx-ex-unsigned.zip >/tmp/dx-ex-unsigned.out 2>&1 || true
grep -q '"ok": false' /tmp/dx-ex-unsigned.out
grep -q 'DX.EXCHANGE.CHECKSUM_MISSING' /tmp/dx-ex-unsigned.out
"${DRUSH[@]}" dx:exchange-package-register /tmp/dx-ex-unsigned.zip >/tmp/dx-ex-unsigned-reg.out 2>&1 || true
grep -q '"ok": false' /tmp/dx-ex-unsigned-reg.out
grep -q 'DX.EXCHANGE.CHECKSUM_MISSING' /tmp/dx-ex-unsigned-reg.out

# A ledger that cannot be read at all (short digest, path escaping the archive
# root) is a different fault than a content change and must carry its own code.
python3 - <<'PY'
import zipfile
z = zipfile.ZipFile('/tmp/dx-ex-export.zip')
body = z.read('package.json').decode('utf-8')
z.close()
bad = ("# DXEP sha256 ledger\n"
       + "0" * 63 + "  package.json\n"
       + "a" * 64 + "  ../escape.txt\n")
out = zipfile.ZipFile('/tmp/dx-ex-badledger.zip', 'w', zipfile.ZIP_DEFLATED)
out.writestr('package.json', body)
out.writestr('checksums.sha256', bad)
out.close()
print('bad ledger zip built')
PY
"${DRUSH[@]}" dx:exchange-package-verify-archive /tmp/dx-ex-badledger.zip >/tmp/dx-ex-badledger.out 2>&1 || true
grep -q '"ok": false' /tmp/dx-ex-badledger.out
grep -q 'DX.EXCHANGE.CHECKSUM_INVALID' /tmp/dx-ex-badledger.out
"${DRUSH[@]}" dx:exchange-package-register /tmp/dx-ex-badledger.zip >/tmp/dx-ex-badledger-reg.out 2>&1 || true
grep -q 'DX.EXCHANGE.CHECKSUM_INVALID' /tmp/dx-ex-badledger-reg.out

# G3 report paging: the per-item rows are sliceable, the counters are not.
"${DRUSH[@]}" dx:exchange-package-apply pkg_demo_fixture --page=1 --page-size=1 >/tmp/dx-ex-paged.out
grep -q '"page": 1' /tmp/dx-ex-paged.out
grep -q '"page_size": 1' /tmp/dx-ex-paged.out
grep -q '"total_pages": 2' /tmp/dx-ex-paged.out
grep -q '"total_items": 2' /tmp/dx-ex-paged.out
grep -q '"applied": 2' /tmp/dx-ex-paged.out
python3 -c "import json;d=json.load(open('/tmp/dx-ex-paged.out'));assert len(d['report']['items'])==1;print('paged report has 1 of 2 rows')"
"${DRUSH[@]}" dx:exchange-package-report pkg_demo_fixture --page=2 --page-size=1 >/tmp/dx-ex-report.out
grep -q '"page": 2' /tmp/dx-ex-report.out
grep -q '"package_status"' /tmp/dx-ex-report.out
"${DRUSH[@]}" dx:exchange-package-report pkg_demo_fixture --failed-only >/tmp/dx-ex-report-failed.out
grep -qF '"failed_items": []' /tmp/dx-ex-report-failed.out

# G3 retry is idempotent: nothing failed, so nothing is replayed and no second
# copy of any resource is produced.
BEFORE_NID="$("${DRUSH[@]}" php:eval '$m=\Drupal::state()->get("dx_channel.external_map",[]);$k=[];foreach($m as $key=>$nid){if(str_starts_with((string)$key,"article:")){$k[]=(string)$nid;}}sort($k);echo implode(",",$k);')"
"${DRUSH[@]}" dx:exchange-package-retry pkg_demo_fixture >/tmp/dx-ex-retry.out
grep -q '"ok": true' /tmp/dx-ex-retry.out
python3 -c "import json;d=json.load(open('/tmp/dx-ex-retry.out'));assert d['report'].get('replayed',0)==0;print('retry replayed 0 items')"
AFTER_NID="$("${DRUSH[@]}" php:eval '$m=\Drupal::state()->get("dx_channel.external_map",[]);$k=[];foreach($m as $key=>$nid){if(str_starts_with((string)$key,"article:")){$k[]=(string)$nid;}}sort($k);echo implode(",",$k);')"
[[ "$BEFORE_NID" == "$AFTER_NID" ]]

# G3 content tampered *after* registration (a hacked key_value row) must be
# refused at apply time, and re-registering the pristine file must heal it.
"${DRUSH[@]}" php:eval '
$key = "dx_channel.exchange_packages";
$all = \Drupal::state()->get($key, []);
$all["pkg_demo_fixture"]["resources"][0]["payload"]["title"] = "TAMPERED BY SMOKE";
\Drupal::state()->set($key, $all);
echo "tampered";
' >/tmp/dx-ex-tamper-state.out
grep -q "tampered" /tmp/dx-ex-tamper-state.out
"${DRUSH[@]}" dx:exchange-package-verify pkg_demo_fixture >/tmp/dx-ex-verify-tampered.out 2>&1 || true
grep -q '"apply_allowed": false' /tmp/dx-ex-verify-tampered.out
grep -q 'DX.EXCHANGE.CHECKSUM_MISMATCH' /tmp/dx-ex-verify-tampered.out
"${DRUSH[@]}" dx:exchange-package-apply pkg_demo_fixture >/tmp/dx-ex-apply-tampered.out 2>&1 || true
grep -q '"ok": false' /tmp/dx-ex-apply-tampered.out
grep -q 'DX.EXCHANGE.CHECKSUM_MISMATCH' /tmp/dx-ex-apply-tampered.out
"${DRUSH[@]}" dx:exchange-package-register "$PKG" >/tmp/dx-ex-reg-restore.out
grep -q '"ok": true' /tmp/dx-ex-reg-restore.out
"${DRUSH[@]}" dx:exchange-package-apply pkg_demo_fixture >/tmp/dx-ex-apply-restore.out
grep -q '"applied": 2' /tmp/dx-ex-apply-restore.out

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

  # G3 report + retry endpoints over HTTP.
  REPORT_OUT="$("${DRUSH[@]}" php:eval '
$token = "'"$TOKEN"'";
$request = \Symfony\Component\HttpFoundation\Request::create("/api/dx/v1/exchange/packages/pkg_demo_fixture/report?page=1&page_size=1", "GET", [], [], [], ["HTTP_AUTHORIZATION" => "Bearer ".$token]);
$resp = \Drupal::service("http_kernel")->handle($request);
echo $resp->getStatusCode()."\n".$resp->getContent();
')"
  echo "$REPORT_OUT" | head -1 | grep -q '^200$'
  # The HTTP envelope is compact JSON ("page_size":1) while the drush formatter
  # pretty-prints it ("page_size": 1); match either spacing.
  echo "$REPORT_OUT" | grep -qE '"page_size":[[:space:]]*1'
  # GET .../report returns the paged ApplyReport (docs/openapi/dxep-v1.yaml);
  # integrity is exposed by GET .../{package_id} (packageGet), not by /report.
  echo "$REPORT_OUT" | grep -q '"package_status"'
  RETRY_OUT="$("${DRUSH[@]}" php:eval '
$token = "'"$TOKEN"'";
$request = \Symfony\Component\HttpFoundation\Request::create("/api/dx/v1/exchange/packages/pkg_demo_fixture/retry", "POST", [], [], [], ["HTTP_AUTHORIZATION" => "Bearer ".$token]);
$resp = \Drupal::service("http_kernel")->handle($request);
echo $resp->getStatusCode()."\n".$resp->getContent();
')"
  echo "$RETRY_OUT" | head -1 | grep -q '^200$'
  echo "$RETRY_OUT" | grep -q '"replayed"'
  echo "OK packages HTTP=$HTTP download=$DL token=yes integrity=verified"
else
  echo "OK packages via drush (token parse skipped)"
fi

echo "OK"
