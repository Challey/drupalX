#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
DRUSH=(vendor/bin/drush)
FIXTURE="$ROOT/web/modules/custom/dx_certs/data/fixtures/demo.keystore"

echo "== dx_certs smoke =="
"${DRUSH[@]}" php:eval '
$cfg = \Drupal::configFactory()->getEditable("core.extension");
$mods = $cfg->get("module") ?: [];
$changed = FALSE;
foreach (array_keys($mods) as $name) {
  if (!\Drupal::service("extension.list.module")->exists($name)) {
    unset($mods[$name]);
    $changed = TRUE;
  }
}
if ($changed) { $cfg->set("module", $mods)->save(); }
' >/dev/null || true

"${DRUSH[@]}" pm:enable dx_certs -y >/dev/null
"${DRUSH[@]}" cr >/dev/null

# Missing path still registers but not ready
"${DRUSH[@]}" dx:certs-register demo_android_missing ~/staging/drupalX/certs/android/missing.keystore --platform=android --label=Missing >/tmp/dx-certs-reg-miss.out
grep -q 'demo_android_missing' /tmp/dx-certs-reg-miss.out
grep -q '"ready": false' /tmp/dx-certs-reg-miss.out || grep -q '"ready":false' /tmp/dx-certs-reg-miss.out

# Readable fixture is ready
[[ -f "$FIXTURE" ]]
"${DRUSH[@]}" dx:certs-register demo_android "$FIXTURE" --platform=android --label=Demo >/tmp/dx-certs-reg.out
grep -q 'demo_android' /tmp/dx-certs-reg.out
grep -q '"ready": true' /tmp/dx-certs-reg.out || grep -q '"ready":true' /tmp/dx-certs-reg.out
grep -q 'sha256' /tmp/dx-certs-reg.out

"${DRUSH[@]}" dx:certs-status >/tmp/dx-certs-status.out
grep -q 'demo_android' /tmp/dx-certs-status.out
grep -q 'ready_count' /tmp/dx-certs-status.out

"${DRUSH[@]}" dx:certs-check --id=demo_android >/tmp/dx-certs-check.out
grep -q '"ok": true' /tmp/dx-certs-check.out || grep -q '"ok":true' /tmp/dx-certs-check.out

"${DRUSH[@]}" dx:certs-packer-env android >/tmp/dx-certs-env.out
grep -q 'DX_CERT_PATH=' /tmp/dx-certs-env.out
grep -q 'DX_CERT_READY=1' /tmp/dx-certs-env.out
grep -q 'DX_CERT_SHA256=' /tmp/dx-certs-env.out

"${DRUSH[@]}" dx:certs-revoke demo_android_missing >/tmp/dx-certs-rev.out
grep -q '"ok": true' /tmp/dx-certs-rev.out || grep -q '"ok":true' /tmp/dx-certs-rev.out

echo "OK"
