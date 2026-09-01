#!/usr/bin/env bash
# L3 handoff todo lifecycle on the live dx_delivery API:
# open -> list -> complete (drush) -> persisted on the blueprint acceptance JSON.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
DRUSH=(vendor/bin/drush)

echo "== dx_delivery L3 handoff todo smoke =="
# Pure logic first: no site, no database, fails before anything is written.
php web/modules/custom/dx_delivery/tests/pure-assertions.php | tail -1
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

# ---------------------------------------------------------------------------
# F3 - --batch sign-off, SLA fields and idempotent replay.
# ---------------------------------------------------------------------------
ALL_IDS="$("${DRUSH[@]}" php:eval '
$svc = \Drupal::service("dx_delivery.handoff_todos");
$bp = \Drupal::entityTypeManager()->getStorage("dx_blueprint")->load((int) $argv[1]);
echo implode(",", array_column($svc->listFromBlueprint($bp), "id"));
' "$ID")"
[[ -n "$ALL_IDS" ]]
echo "batch ids=$ALL_IDS"

DONE_AT_BEFORE="$("${DRUSH[@]}" php:eval '
$svc = \Drupal::service("dx_delivery.handoff_todos");
$bp = \Drupal::entityTypeManager()->getStorage("dx_blueprint")->load((int) $argv[1]);
foreach ($svc->listFromBlueprint($bp) as $todo) {
  if (($todo["id"] ?? "") === $argv[2]) {
    echo (string) ($todo["done_at"] ?? "");
    exit(0);
  }
}
exit(1);
' "$ID" "$TODO_ID")"
[[ -n "$DONE_AT_BEFORE" ]]

# First batch: the already signed todo is skipped, the rest get completed.
"${DRUSH[@]}" dx:delivery-todo-done "$ID" --batch="$ALL_IDS" --owner=ops-lead --due=2026-12-31 --note=批量演练 >/tmp/dx-todo-batch1.out
grep -q '"ok":true' /tmp/dx-todo-batch1.out
grep -q '"mode":"batch"' /tmp/dx-todo-batch1.out
grep -qF "\"skipped\":[\"$TODO_ID\"]" /tmp/dx-todo-batch1.out
grep -q '"owner":"ops-lead"' /tmp/dx-todo-batch1.out
grep -q '"due":"2026-12-31"' /tmp/dx-todo-batch1.out

# Replaying the very same batch must be a no-op that still succeeds.
"${DRUSH[@]}" dx:delivery-todo-done "$ID" --batch="$ALL_IDS" >/tmp/dx-todo-batch2.out
grep -q '"ok":true' /tmp/dx-todo-batch2.out
grep -qF '"completed":[]' /tmp/dx-todo-batch2.out
grep -qF '"skipped":[' /tmp/dx-todo-batch2.out
for TODO in ${ALL_IDS//,/ }; do
  grep -q "\"$TODO\"" /tmp/dx-todo-batch2.out
done

# Idempotency also means the original sign-off timestamp is never rewritten.
DONE_AT_AFTER="$("${DRUSH[@]}" php:eval '
$svc = \Drupal::service("dx_delivery.handoff_todos");
$bp = \Drupal::entityTypeManager()->getStorage("dx_blueprint")->load((int) $argv[1]);
foreach ($svc->listFromBlueprint($bp) as $todo) {
  if (($todo["id"] ?? "") === $argv[2]) {
    echo (string) ($todo["done_at"] ?? "");
    exit(0);
  }
}
exit(1);
' "$ID" "$TODO_ID")"
[[ "$DONE_AT_BEFORE" == "$DONE_AT_AFTER" ]] || { echo "batch replay rewrote done_at" >&2; exit 1; }

# --sla-only edits the SLA columns without touching any status, and accepts a
# loose due date spelling.
"${DRUSH[@]}" dx:delivery-todo-done "$ID" --batch="$TODO_ID" --owner=外包同学 --due="2026/12/31" --sla-only >/tmp/dx-todo-sla.out
grep -q '"ok":true' /tmp/dx-todo-sla.out
grep -q '"mode":"sla"' /tmp/dx-todo-sla.out
grep -qF '"completed":[]' /tmp/dx-todo-sla.out
"${DRUSH[@]}" php:eval '
$svc = \Drupal::service("dx_delivery.handoff_todos");
$bp = \Drupal::entityTypeManager()->getStorage("dx_blueprint")->load((int) $argv[1]);
foreach ($svc->listFromBlueprint($bp) as $todo) {
  if (($todo["id"] ?? "") !== $argv[2]) {
    continue;
  }
  if (($todo["status"] ?? "") !== "done" || ($todo["due"] ?? "") !== "2026-12-31" || ($todo["owner"] ?? "") !== "外包同学") {
    fwrite(STDERR, "sla-only changed the wrong thing: " . json_encode($todo, JSON_UNESCAPED_UNICODE) . "\n");
    exit(1);
  }
  exit(0);
}
fwrite(STDERR, "todo vanished\n");
exit(1);
' "$ID" "$TODO_ID" >/dev/null

# A batch that mentions an unknown id fails loudly but keeps the known ones.
if "${DRUSH[@]}" dx:delivery-todo-done "$ID" --batch="$TODO_ID,ghost-todo" >/tmp/dx-todo-unknown.out 2>&1; then
  echo "unknown id inside a batch was accepted" >&2
  exit 1
fi
grep -qF '"unknown":["ghost-todo"]' /tmp/dx-todo-unknown.out
grep -qF "\"skipped\":[\"$TODO_ID\"]" /tmp/dx-todo-unknown.out

# ---------------------------------------------------------------------------
# F2 - /deliver/todos board: routing, permission gate, HTML states.
# ---------------------------------------------------------------------------
BOARD_ROUTE="$("${DRUSH[@]}" php:eval 'echo \Drupal::service("router.route_provider")->getRouteByName("dx_delivery.todo_board")->getPath();')"
[[ "$BOARD_ROUTE" == "/deliver/todos" ]]
DONE_ROUTE="$("${DRUSH[@]}" php:eval 'echo \Drupal::service("router.route_provider")->getRouteByName("dx_delivery.todo_complete")->getPath();')"
[[ "$DONE_ROUTE" == "/deliver/todos/{dx_blueprint}/{todo_id}/complete" ]]

# The board is gated by its own permission: the desk permission must not leak in.
BOARD_PERM="$("${DRUSH[@]}" php:eval 'echo \Drupal::service("router.route_provider")->getRouteByName("dx_delivery.todo_board")->getRequirement("_permission");')"
[[ "$BOARD_PERM" == "access dx delivery todos+administer dx delivery" ]] || { echo "board permission string changed: $BOARD_PERM" >&2; exit 1; }
BOARD_ANON="$("${DRUSH[@]}" php:eval '
$account = \Drupal\user\Entity\User::load(0);
$access = \Drupal::service("access_manager")->checkNamedRoute("dx_delivery.todo_board", [], $account, TRUE);
echo $access->isAllowed() ? "allowed" : "forbidden";
')"
[[ "$BOARD_ANON" == "forbidden" ]] || { echo "anonymous can reach the board" >&2; exit 1; }
BOARD_ADMIN="$("${DRUSH[@]}" php:eval '
$account = \Drupal\user\Entity\User::load(1);
$access = \Drupal::service("access_manager")->checkNamedRoute("dx_delivery.todo_board", [], $account, TRUE);
echo $access->isAllowed() ? "allowed" : "forbidden";
')"
[[ "$BOARD_ADMIN" == "allowed" ]] || { echo "administrator cannot reach the board" >&2; exit 1; }

render_board() {
  "${DRUSH[@]}" php:eval '
$account = \Drupal\user\Entity\User::load(1);
if (!$account instanceof \Drupal\user\AccountInterface) {
  fwrite(STDERR, "uid 1 not found\n");
  exit(1);
}
\Drupal::service("current_user")->setAccount($account);
$query = [];
parse_str((string) $argv[1], $query);
$request = \Symfony\Component\HttpFoundation\Request::create("/deliver/todos", "GET", $query);
$controller = \Drupal\dx_delivery\Controller\DeliveryTodoBoardController::create(\Drupal::getContainer());
$build = $controller->board($request);
echo (string) \Drupal::service("renderer")->renderInIsolation($build);
' "$1"
}

render_board "blueprint=$ID&status=all" >/tmp/dx-todo-board-all.html
render_board "blueprint=$ID&status=open" >/tmp/dx-todo-board-open.html
render_board "blueprint=999999&status=open" >/tmp/dx-todo-board-missing.html

# A stale ?blueprint= must fall back to the aggregated board with a readable
# notice instead of a blank page or a 404.
grep -q '找不到蓝图' /tmp/dx-todo-board-missing.html
grep -q 'dx-deliver dx-deliver--todos' /tmp/dx-todo-board-missing.html

# Everything is signed off right now: the open tab is an empty state, the all
# tab still lists both todos with their SLA columns and no stale sign-off link.
grep -q 'dx-deliver-board__row--done' /tmp/dx-todo-board-all.html
grep -q "drush dx:delivery-todo-done $ID" /tmp/dx-todo-board-all.html
grep -q '外包同学' /tmp/dx-todo-board-all.html
grep -q '2026-12-31' /tmp/dx-todo-board-all.html
if grep -q 'href="/deliver/todos/' /tmp/dx-todo-board-all.html; then
  echo "signed off todo still offers a sign-off link" >&2
  exit 1
fi
if grep -q 'href="/deliver/todos/' /tmp/dx-todo-board-open.html; then
  echo "open tab advertises a sign-off link with nothing to sign" >&2
  exit 1
fi
grep -q 'dx-deliver-board__empty' /tmp/dx-todo-board-open.html

# Re-open one todo to prove the board exposes the sign-off form and the
# overdue highlight, then close it again through the batch command.
"${DRUSH[@]}" php:eval '
$svc = \Drupal::service("dx_delivery.handoff_todos");
$bp = \Drupal::entityTypeManager()->getStorage("dx_blueprint")->load((int) $argv[1]);
$todos = $svc->listFromBlueprint($bp);
foreach ($todos as &$todo) {
  if (($todo["id"] ?? "") === $argv[2]) {
    $todo["status"] = "open";
    $todo["due"] = "2000-01-01";
    unset($todo["done_at"]);
  }
}
unset($todo);
$svc->saveOnBlueprint($bp, $todos);
' "$ID" "$TODO_ID" >/dev/null

render_board "blueprint=$ID&status=overdue" >/tmp/dx-todo-board-overdue.html
render_board "blueprint=$ID&status=open" >/tmp/dx-todo-board-open2.html
grep -q "is-overdue" /tmp/dx-todo-board-overdue.html
grep -q "/deliver/todos/$ID/$TODO_ID/complete" /tmp/dx-todo-board-overdue.html
grep -q "<code class=\"dx-deliver-board__id\">$TODO_ID</code>" /tmp/dx-todo-board-open2.html
if grep -q 'dx-deliver-board__empty' /tmp/dx-todo-board-open2.html; then
  echo "open todo missing from the open tab" >&2
  exit 1
fi

"${DRUSH[@]}" dx:delivery-todo-done "$ID" --batch="$TODO_ID" --due=2026-12-31 --note=看板回测 >/tmp/dx-todo-final.out
grep -q '"ok":true' /tmp/dx-todo-final.out
grep -qF "\"completed\":[\"$TODO_ID\"]" /tmp/dx-todo-final.out

# The administrator has to own the new permission; on sites that still have a
# pending dx_delivery update this only warns, the maintenance window fixes it.
"${DRUSH[@]}" php:eval '
$role = \Drupal::entityTypeManager()->getStorage("user_role")->loadOverrideFree("administrator");
if (!$role instanceof \Drupal\user\RoleInterface) {
  echo "no-role";
  exit(0);
}
echo $role->hasPermission("access dx delivery todos") ? "granted" : "pending";
' >/tmp/dx-todo-perm.out
if grep -q 'pending' /tmp/dx-todo-perm.out; then
  echo "warn: run drush updatedb -y to grant 'access dx delivery todos' to administrator"
fi

echo "OK L3 handoff todos: $ALL_IDS done on blueprint $ID board=$BOARD_ROUTE anon=$BOARD_ANON admin=$BOARD_ADMIN"
