#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
DRUSH=(vendor/bin/drush)
echo "== appstore trust filter smoke =="
"${DRUSH[@]}" php:eval '
$cfg=\Drupal::configFactory()->getEditable("core.extension");
$mods=$cfg->get("module")?:[];$c=FALSE;
foreach(array_keys($mods) as $n){if(!\Drupal::service("extension.list.module")->exists($n)){unset($mods[$n]);$c=TRUE;}}
if($c){$cfg->set("module",$mods)->save();}
' >/dev/null || true
"${DRUSH[@]}" pm:enable dx_appstore dx_trust -y >/dev/null
"${DRUSH[@]}" dx:trust-apply government >/dev/null
"${DRUSH[@]}" cr >/dev/null
"${DRUSH[@]}" dx:appstore-seed -y >/dev/null 2>&1 || true
CODE="$("${DRUSH[@]}" php:eval 'echo \Drupal::service("http_kernel")->handle(\Symfony\Component\HttpFoundation\Request::create("/appstore?policy=gov"))->getStatusCode();')"
[[ "$CODE" == "200" || "$CODE" == "403" ]]
ROUTE="$("${DRUSH[@]}" php:eval 'echo \Drupal::service("router.route_provider")->getRouteByName("dx_appstore.catalog")->getPath();')"
echo "OK catalog_route=$ROUTE http=$CODE"
