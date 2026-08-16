#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
DRUSH=(vendor/bin/drush)

echo "== dx_trust smoke =="
"${DRUSH[@]}" pm:enable dx_trust -y >/dev/null
"${DRUSH[@]}" cr >/dev/null
"${DRUSH[@]}" dx:trust-apply government >/tmp/dx-trust-apply.out
grep -q 'government_default' /tmp/dx-trust-apply.out
"${DRUSH[@]}" dx:trust-check community >/tmp/dx-trust-check.out
grep -q '"allowed": false' /tmp/dx-trust-check.out
"${DRUSH[@]}" dx:trust-check security >/tmp/dx-trust-check2.out
grep -q '"allowed": true' /tmp/dx-trust-check2.out
echo "OK"
