#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
DRUSH=(vendor/bin/drush)
echo "== migrate-package smoke =="
"${DRUSH[@]}" pm:enable dx_migrate dx_channel -y >/dev/null
"${DRUSH[@]}" cr >/dev/null
"${DRUSH[@]}" dx:migrate-package --template=gov_news >/tmp/dx-mig-pkg.out
grep -q '"ok": true' /tmp/dx-mig-pkg.out
grep -q 'pkg_mig_\|package_id' /tmp/dx-mig-pkg.out
PID="$(python3 -c 'import json; d=json.load(open("/tmp/dx-mig-pkg.out")); print(d.get("package",{}).get("package_id",""))')"
[[ -n "$PID" ]]
"${DRUSH[@]}" dx:exchange-package-apply "$PID" --dry-run >/tmp/dx-mig-pkg-apply.out
grep -q '"applied":' /tmp/dx-mig-pkg-apply.out

# ---------------------------------------------------------------------------
# G1 -> G3 hand-off.
# The template (data, not PHP) decides the DXEP resource type; the produced
# package is sealed with a content digest and exports as an offline ZIP whose
# ledger re-verifies, so a migrate package can cross a network gap safely.
# ---------------------------------------------------------------------------
grep -q '"status": "inline"' /tmp/dx-mig-pkg.out
python3 - <<'PY'
import json
d = json.load(open('/tmp/dx-mig-pkg.out'))['package']
assert d['resource_count'] > 0, d['resource_count']
assert d['manifest']['counts'] == {'article': d['resource_count']}, d['manifest']['counts']
assert len(d['integrity']['content_sha256']) == 64, d['integrity']
print('gov_news package sealed')
open('/tmp/dx-mig-pkg-gov-sha.txt', 'w').write(d['integrity']['content_sha256'])
PY

# Same command, different template: still no PHP involved (G1).
"${DRUSH[@]}" dx:migrate-package --template=hospital_notice --package-id=pkg_mig_notice >/tmp/dx-mig-pkg-notice.out
grep -q '"ok": true' /tmp/dx-mig-pkg-notice.out
grep -q 'pkg_mig_notice' /tmp/dx-mig-pkg-notice.out
python3 - <<'PY'
import json
d = json.load(open('/tmp/dx-mig-pkg-notice.out'))['package']
assert d['manifest']['source']['system'] == 'dx_migrate'
assert d['manifest']['require_review'] is True, d['manifest']
assert d['integrity']['content_sha256'] != open('/tmp/dx-mig-pkg-gov-sha.txt').read(), 'digest must follow the mapping'
print('hospital_notice package sealed from a data-only template')
PY

"${DRUSH[@]}" dx:exchange-package-verify pkg_mig_notice >/tmp/dx-mig-pkg-notice-verify.out
grep -q '"apply_allowed": true' /tmp/dx-mig-pkg-notice-verify.out
grep -q '"current_content_sha256": "' /tmp/dx-mig-pkg-notice-verify.out

"${DRUSH[@]}" dx:exchange-package-export pkg_mig_notice /tmp/dx-mig-pkg.zip >/tmp/dx-mig-pkg-export.out
grep -q '"ok": true' /tmp/dx-mig-pkg-export.out
python3 -c "import zipfile;n=zipfile.ZipFile('/tmp/dx-mig-pkg.zip').namelist();assert 'package.json' in n and 'checksums.sha256' in n, n;print('migrate zip carries a ledger')"
"${DRUSH[@]}" dx:exchange-package-verify-archive /tmp/dx-mig-pkg.zip >/tmp/dx-mig-pkg-zipverify.out
grep -q '"ok": true' /tmp/dx-mig-pkg-zipverify.out
grep -q '"code": "DX.OK"' /tmp/dx-mig-pkg-zipverify.out
grep -q '"package_id": "pkg_mig_notice"' /tmp/dx-mig-pkg-zipverify.out
# An offline ZIP may also be re-registered on the receiving side (G3 transport).
"${DRUSH[@]}" dx:exchange-package-register /tmp/dx-mig-pkg.zip >/tmp/dx-mig-pkg-zzipreg.out
grep -q '"ok": true' /tmp/dx-mig-pkg-zzipreg.out
grep -q '"status": "verified"' /tmp/dx-mig-pkg-zzipreg.out
"${DRUSH[@]}" dx:exchange-package-apply pkg_mig_notice --dry-run >/tmp/dx-mig-pkg-notice-apply.out
grep -q '"applied": 3' /tmp/dx-mig-pkg-notice-apply.out

echo "OK package=$PID notice=pkg_mig_notice"
