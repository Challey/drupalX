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
echo "OK package=$PID"
