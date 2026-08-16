#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
DRUSH=(vendor/bin/drush)
echo "== stack status smoke =="
"${DRUSH[@]}" pm:enable dx_health dx_channel -y >/dev/null
"${DRUSH[@]}" cr >/dev/null
"${DRUSH[@]}" dx:stack-status >/tmp/dx-stack.out
grep -q '"ready"' /tmp/dx-stack.out
grep -q 'dx_delivery' /tmp/dx-stack.out
"${DRUSH[@]}" dx:channel-audit-stats >/tmp/dx-audit-stats.out
grep -q 'rate_limit' /tmp/dx-audit-stats.out
echo "OK"
