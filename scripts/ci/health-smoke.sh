#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
DRUSH=(vendor/bin/drush)
echo "== dx_health smoke =="
"${DRUSH[@]}" php:eval '
$cfg=\Drupal::configFactory()->getEditable("core.extension");
$mods=$cfg->get("module")?:[];$c=FALSE;
foreach(array_keys($mods) as $n){if(!\Drupal::service("extension.list.module")->exists($n)){unset($mods[$n]);$c=TRUE;}}
if($c){$cfg->set("module",$mods)->save();}
' >/dev/null || true
"${DRUSH[@]}" pm:enable dx_health -y >/dev/null
"${DRUSH[@]}" cr >/dev/null
"${DRUSH[@]}" dx:health >/tmp/dx-health.out
grep -q '"ok": true' /tmp/dx-health.out
"${DRUSH[@]}" dx:health-tenant missingtenant >/tmp/dx-health-t.out
grep -q 'tenant_exists' /tmp/dx-health-t.out
echo "OK"
