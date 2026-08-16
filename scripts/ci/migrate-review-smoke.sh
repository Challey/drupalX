#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
DRUSH=(vendor/bin/drush)

echo "== dx_migrate review smoke =="
"${DRUSH[@]}" pm:enable dx_migrate dx_channel -y >/dev/null
"${DRUSH[@]}" php:eval 'if ($r=\Drupal::entityTypeManager()->getStorage("user_role")->load("administrator")) { user_role_grant_permissions("administrator", ["administer dx migrate"]); }' >/dev/null || true
"${DRUSH[@]}" cr >/dev/null
# Ensure at least one draft via fixture migrate
"${DRUSH[@]}" dx:migrate-l1 >/tmp/dx-migrate-review-import.out
"${DRUSH[@]}" dx:migrate-review-list >/tmp/dx-migrate-review-list.out
grep -q '"ok": true' /tmp/dx-migrate-review-list.out
PENDING="$(python3 -c 'import json; print(json.load(open("/tmp/dx-migrate-review-list.out"))["pending"])')"
[[ "$PENDING" -ge 1 ]]

ROUTE="$("${DRUSH[@]}" php:eval 'echo \Drupal::service("router.route_provider")->getRouteByName("dx_migrate.review")->getPath();')"
[[ "$ROUTE" == "/admin/dx/migrate/review" ]]
DISCARD="$("${DRUSH[@]}" php:eval 'echo \Drupal::service("router.route_provider")->getRouteByName("dx_migrate.review_discard")->getPath();')"
[[ "$DISCARD" == "/admin/dx/migrate/review/{node}/discard" ]]

# Discard one pending draft via service path
NID="$(python3 -c 'import json; print(json.load(open("/tmp/dx-migrate-review-list.out"))["items"][0]["nid"])')"
"${DRUSH[@]}" php:eval '
$nid = '"$NID"';
$node = \Drupal::entityTypeManager()->getStorage("node")->load($nid);
if ($node) {
  $node->delete();
  \Drupal::service("dx_channel.ingest")->unmapNid((int)$nid);
  echo "discarded ".$nid;
}
' >/tmp/dx-migrate-review-discard.out
grep -q "discarded $NID" /tmp/dx-migrate-review-discard.out

echo "OK review route=$ROUTE pending_before=$PENDING discard=$NID"
