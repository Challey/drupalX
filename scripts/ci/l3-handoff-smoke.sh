#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
DRUSH=(vendor/bin/drush)

echo "== L3 handoff + /order smoke =="
"${DRUSH[@]}" pm:enable dx_delivery dx_health -y >/dev/null
"${DRUSH[@]}" cr >/dev/null

UNIQUE="dxl3$(date +%s | tail -c 5)"
"${DRUSH[@]}" dx:delivery-from-chat "做政府门户，L3 人工集成审批流" --machine-name="$UNIQUE" >/tmp/dx-l3-chat.out
ID="$("${DRUSH[@]}" dx:delivery-list 2>/dev/null | awk -v m="$UNIQUE" '$0 ~ m {print $1; exit}')"
[[ -n "$ID" ]]
echo "blueprint id=$ID"

"${DRUSH[@]}" dx:delivery-run "$ID" --skip-provision --skip-pack >/tmp/dx-l3-run.out
grep -q '"passed": true' /tmp/dx-l3-run.out
grep -q 'l3-integration' /tmp/dx-l3-run.out
grep -q 'handoff_todos' /tmp/dx-l3-run.out

"${DRUSH[@]}" dx:delivery-todo-done "$ID" l3-integration | tee /tmp/dx-l3-todo.out
grep -q '"ok":true' /tmp/dx-l3-todo.out
grep -q '"status":"done"' /tmp/dx-l3-todo.out

ORDER="$("${DRUSH[@]}" php:eval 'echo \Drupal::service("http_kernel")->handle(\Symfony\Component\HttpFoundation\Request::create("/order"))->getStatusCode();')"
[[ "$ORDER" == "200" || "$ORDER" == "403" ]]
DELIVER="$("${DRUSH[@]}" php:eval 'echo \Drupal::service("http_kernel")->handle(\Symfony\Component\HttpFoundation\Request::create("/deliver"))->getStatusCode();')"
[[ "$DELIVER" == "200" || "$DELIVER" == "403" ]]

echo "OK L3 handoff id=$ID /order=$ORDER /deliver=$DELIVER"
