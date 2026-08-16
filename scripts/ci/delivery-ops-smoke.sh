#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
DRUSH=(vendor/bin/drush)

echo "== delivery ops smoke =="
"${DRUSH[@]}" php:eval '
$cfg=\Drupal::configFactory()->getEditable("core.extension");
$mods=$cfg->get("module")?:[];
$changed=FALSE;
foreach(array_keys($mods) as $name){
  if(!\Drupal::service("extension.list.module")->exists($name)){unset($mods[$name]);$changed=TRUE;}
}
if($changed){$cfg->set("module",$mods)->save();}
' >/dev/null || true

"${DRUSH[@]}" pm:enable dx_delivery dx_channel dx_ai_gateway -y >/dev/null
"${DRUSH[@]}" cr >/dev/null

MSG='gov portal need opinion'
UNIQUE="ops$(date +%s | tail -c 5)"
"${DRUSH[@]}" dx:delivery-from-chat "$MSG" --machine-name="$UNIQUE" >/tmp/dx-ops-from.out
ID="$("${DRUSH[@]}" dx:delivery-list 2>/dev/null | awk -v m="$UNIQUE" '$0 ~ m {print $1; exit}')"
[[ -n "$ID" ]]
"${DRUSH[@]}" dx:delivery-run "$ID" --skip-provision --skip-pack >/tmp/dx-ops-run.out
"${DRUSH[@]}" dx:delivery-report "$ID" >/tmp/dx-ops-report.out
grep -q '"acceptance"' /tmp/dx-ops-report.out
grep -q 'trust_policy\|capabilities\|migrate' /tmp/dx-ops-report.out

ROUTE="$("${DRUSH[@]}" php:eval 'echo \Drupal::service("router.route_provider")->getRouteByName("dx_channel.audit")->getPath();')"
[[ "$ROUTE" == "/admin/dx/channel/audit" ]]

"${DRUSH[@]}" dx:ai-status >/tmp/dx-ai-status.out
grep -q 'ready_count' /tmp/dx-ai-status.out

"${DRUSH[@]}" dx:delivery-export "$ID" /tmp/dx-acceptance-export.json >/tmp/dx-export.out
grep -q '"ok": true' /tmp/dx-export.out
test -s /tmp/dx-acceptance-export.json
DL="$("${DRUSH[@]}" php:eval 'echo \Drupal::service("router.route_provider")->getRouteByName("dx_delivery.acceptance_download")->getPath();')"
[[ "$DL" == *acceptance.json ]]

echo "OK id=$ID audit_route=$ROUTE export=ok"
