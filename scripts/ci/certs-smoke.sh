#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
DRUSH=(vendor/bin/drush)

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
"${DRUSH[@]}" dx:certs-register demo_android ~/staging/drupalX/certs/android/demo.keystore --platform=android --label=Demo >/tmp/dx-certs-reg.out
grep -q 'demo_android' /tmp/dx-certs-reg.out
"${DRUSH[@]}" dx:certs-status >/tmp/dx-certs-status.out
grep -q 'demo_android' /tmp/dx-certs-status.out
"${DRUSH[@]}" dx:certs-packer-env android >/tmp/dx-certs-env.out
grep -q 'DX_CERT_PATH=' /tmp/dx-certs-env.out
echo "OK"
