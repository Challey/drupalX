#!/usr/bin/env bash
# L3 handoff todo lifecycle on the live dx_delivery API:
# open -> list -> complete (drush) -> persisted on the blueprint acceptance JSON.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
DRUSH=(vendor/bin/drush)

echo "== dx_delivery L3 handoff todo smoke =="
"${DRUSH[@]}" pm:enable dx_delivery dx_health -y >/dev/null
"${DRUSH[@]}" cr >/dev/null

UNIQUE="l3todo$(date +%s | tail -c 5)"
MSG='政府门户，要把原办事系统和审批流迁过来，还要安卓APP'
"${DRUSH[@]}" dx:delivery-from-chat "$MSG" --machine-name="$UNIQUE" >/tmp/dx-todo-from.out
ID="$("${DRUSH[@]}" dx:delivery-list 2>/dev/null | awk -v m="$UNIQUE" '$0 ~ m {print $1; exit}')"
if [[ -z "$ID" ]]; then
  echo "Failed to resolve blueprint id" >&2
  cat /tmp/dx-todo-from.out >&2
  exit 1
fi
echo "blueprint id=$ID machine=$UNIQUE"

"${DRUSH[@]}" dx:delivery-run "$ID" --skip-provision --skip-pack >/tmp/dx-todo-run.out
grep -q '"passed": true' /tmp/dx-todo-run.out
grep -q 'handoff_todos' /tmp/dx-todo-run.out

# The orchestrator must leave at least one open L3 todo for the operator.
"${DRUSH[@]}" php:eval '
$svc = \Drupal::service("dx_delivery.handoff_todos");
$bp = \Drupal::entityTypeManager()->getStorage("dx_blueprint")->load((int) $argv[1]);
$todos = $svc->listFromBlueprint($bp);
$open = array_values(array_filter($todos, static fn (array $t): bool => ($t["status"] ?? "open") !== "done"));
if (!$open) {
  fwrite(STDERR, "no open handoff todos\n");
  exit(1);
}
echo $open[0]["id"];
' "$ID" >/tmp/dx-todo-open.out
TODO_ID="$(cat /tmp/dx-todo-open.out)"
echo "open todo=$TODO_ID"

"${DRUSH[@]}" dx:delivery-todo-done "$ID" "$TODO_ID" >/tmp/dx-todo-done.out
grep -q '"ok":true' /tmp/dx-todo-done.out

# Completion has to survive on the blueprint, and unknown ids must be rejected.
"${DRUSH[@]}" php:eval '
$svc = \Drupal::service("dx_delivery.handoff_todos");
$bp = \Drupal::entityTypeManager()->getStorage("dx_blueprint")->load((int) $argv[1]);
foreach ($svc->listFromBlueprint($bp) as $todo) {
  if (($todo["id"] ?? "") === $argv[2] && ($todo["status"] ?? "") === "done" && !empty($todo["done_at"])) {
    exit(0);
  }
}
fwrite(STDERR, "todo not marked done\n");
exit(1);
' "$ID" "$TODO_ID" >/dev/null

if "${DRUSH[@]}" dx:delivery-todo-done "$ID" "no-such-todo" >/dev/null 2>&1; then
  echo "unknown todo id was accepted" >&2
  exit 1
fi

echo "OK L3 handoff todos: $TODO_ID done on blueprint $ID"
