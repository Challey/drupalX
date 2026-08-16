#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
DRUSH=(vendor/bin/drush)

echo "== dx_certs smoke =="
"${DRUSH[@]}" pm:enable dx_certs -y >/dev/null
"${DRUSH[@]}" cr >/dev/null
"${DRUSH[@]}" dx:certs-register demo_android ~/staging/drupalX/certs/android/demo.keystore --platform=android --label=Demo >/tmp/dx-certs-reg.out
grep -q 'demo_android' /tmp/dx-certs-reg.out
"${DRUSH[@]}" dx:certs-status >/tmp/dx-certs-status.out
grep -q 'demo_android' /tmp/dx-certs-status.out
"${DRUSH[@]}" dx:certs-packer-env android >/tmp/dx-certs-env.out
grep -q 'DX_CERT_PATH=' /tmp/dx-certs-env.out
echo "OK"
