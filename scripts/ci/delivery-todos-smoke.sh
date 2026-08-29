#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
DRUSH=(vendor/bin/drush)

echo "== dx_delivery L3 todos smoke =="
"${DRUSH[@]}" php:eval '
$cfg=\Drupal::configFactory()->getEditable("core.extension");
$mods=$cfg->get("module")?:[];
$changed=FALSE;
foreach(array_keys($mods) as $name){
  if(!\Drupal::service("extension.list.module")->exists($name)){unset($mods[$name]);$changed=TRUE;}
}
if($changed){$cfg->set("module",$mods)->save();}
' >/dev/null || true

"${DRUSH[@]}" pm:enable dx_delivery dx_migrate dx_health -y >/dev/null
"${DRUSH[@]}" cr >/dev/null
"${DRUSH[@]}" php:eval 'if (\Drupal::hasService("dx_delivery.todo")) { \Drupal::service("dx_delivery.todo")->ensureTable(); }' >/dev/null

UNIQUE="l3todo$(date +%s | tail -c 5)"
MSG='政府门户，要把原办事系统和审批流迁过来，还要安卓APP'
"${DRUSH[@]}" dx:delivery-from-chat "$MSG" --machine-name="$UNIQUE" >/tmp/dx-todo-from.out
grep -q 'l3' /tmp/dx-todo-from.out
ID="$("${DRUSH[@]}" dx:delivery-list 2>/dev/null | awk -v m="$UNIQUE" '$0 ~ m {print $1; exit}')"
[[ -n "$ID" ]]

"${DRUSH[@]}" dx:delivery-run "$ID" --skip-provision --skip-pack >/tmp/dx-todo-run.out
grep -q '"id": "todos"' /tmp/dx-todo-run.out
grep -q '"pending_todos"' /tmp/dx-todo-run.out
grep -q 'l3_integration' /tmp/dx-todo-run.out
grep -q 'app_signing' /tmp/dx-todo-run.out
grep -q '"passed": true' /tmp/dx-todo-run.out

"${DRUSH[@]}" dx:delivery-todos --blueprint="$ID" --status=open >/tmp/dx-todo-list.out
grep -q '"ok": true' /tmp/dx-todo-list.out
OPEN="$(python3 -c 'import json; print(json.load(open("/tmp/dx-todo-list.out"))["counts"]["open"])')"
[[ "$OPEN" -ge 2 ]]
TID="$(python3 -c 'import json; print(json.load(open("/tmp/dx-todo-list.out"))["items"][0]["id"])')"
"${DRUSH[@]}" dx:delivery-todo-done "$TID" >/tmp/dx-todo-done.out
grep -q '"ok": true' /tmp/dx-todo-done.out

ORDER="$("${DRUSH[@]}" php:eval 'echo \Drupal::service("router.route_provider")->getRouteByName("dx_delivery.order")->getPath();')"
[[ "$ORDER" == "/order" ]]
TODOS="$("${DRUSH[@]}" php:eval 'echo \Drupal::service("router.route_provider")->getRouteByName("dx_delivery.todos")->getPath();')"
[[ "$TODOS" == "/admin/dx/delivery/todos" ]]

"${DRUSH[@]}" php:eval 'user_role_grant_permissions("anonymous", ["access dx delivery desk"]);' >/dev/null || true
CODE="$("${DRUSH[@]}" php:eval 'echo \Drupal::service("http_kernel")->handle(\Symfony\Component\HttpFoundation\Request::create("/order"))->getStatusCode();')"
[[ "$CODE" == "200" ]]

echo "OK blueprint=$ID open_before=$OPEN order=$ORDER todos=$TODOS http=$CODE"
