#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
DRUSH=(vendor/bin/drush)
echo "== delivery desk smoke =="
# Pure logic first: no site, no database, fails before anything is written.
php web/modules/custom/dx_delivery/tests/pure-assertions.php | tail -1
"${DRUSH[@]}" pm:enable dx_delivery dx_health -y >/dev/null
"${DRUSH[@]}" cr >/dev/null
ROUTE="$("${DRUSH[@]}" php:eval 'echo \Drupal::service("router.route_provider")->getRouteByName("dx_delivery.desk")->getPath();')"
[[ "$ROUTE" == "/deliver" ]]
ORDER="$("${DRUSH[@]}" php:eval 'echo \Drupal::service("router.route_provider")->getRouteByName("dx_delivery.order")->getPath();')"
[[ "$ORDER" == "/order" ]]
# Anonymous may 403; grant and recheck
"${DRUSH[@]}" php:eval 'user_role_grant_permissions("anonymous", ["access dx delivery desk"]);' >/dev/null || true
CODE="$("${DRUSH[@]}" php:eval 'echo \Drupal::service("http_kernel")->handle(\Symfony\Component\HttpFoundation\Request::create("/deliver"))->getStatusCode();')"
[[ "$CODE" == "200" ]]
ORDER_CODE="$("${DRUSH[@]}" php:eval 'echo \Drupal::service("http_kernel")->handle(\Symfony\Component\HttpFoundation\Request::create("/order"))->getStatusCode();')"
[[ "$ORDER_CODE" == "200" ]]

# ---------------------------------------------------------------------------
# F1 - blueprint list partitions: 草稿 / 已确认 / 已执行 / 失败重试.
# The partitions are plain ?status= links on /admin/dx/delivery (no Views).
# Assertions below are HTTP (route + access) and HTML (rendered listing).
# The listing is rendered through the real list builder as uid 1, so this
# script never grants an admin permission to anonymous on the live site.
# ---------------------------------------------------------------------------
ADMIN="$("${DRUSH[@]}" php:eval 'echo \Drupal::service("router.route_provider")->getRouteByName("dx_delivery.admin")->getPath();')"
[[ "$ADMIN" == "/admin/dx/delivery" ]]

# Permission semantics must not drift: anonymous still cannot see the list.
ADMIN_ACCESS="$("${DRUSH[@]}" php:eval '
  $access = \Drupal::service("access_manager")->checkNamedRoute("dx_delivery.admin", [], NULL, TRUE);
  echo $access->isAllowed() ? "allowed" : "forbidden";
')"
[[ "$ADMIN_ACCESS" == "forbidden" ]] || { echo "F1: anonymous can now reach /admin/dx/delivery" >&2; exit 1; }

SEG_FILE="/tmp/dx-desk-segments.out"
"${DRUSH[@]}" php:eval '
$segs = ["all", "draft", "confirmed", "executed", "failed", "bogus"];
$stamp = substr((string) time(), -5);
$storage = \Drupal::entityTypeManager()->getStorage("dx_blueprint");
$specs = [
  "draft" => "desk partition draft",
  "confirmed" => "desk partition confirmed",
  "completed" => "desk partition executed",
  "failed" => "desk partition failed",
];
$created = [];
foreach ($specs as $status => $label) {
  $blueprint = $storage->create([
    "label" => $label . " " . $stamp,
    "machine_name" => "deskseg" . $status . $stamp,
    "site_type" => "government",
  ]);
  $blueprint->set("status", $status);
  $blueprint->save();
  $created[$status] = (int) $blueprint->id();
}
$pairs = [];
foreach ($created as $status => $id) {
  $pairs[] = $status . "=" . $id;
}
echo "IDS " . implode(" ", $pairs) . "\n";

$account = \Drupal\user\Entity\User::load(1);
if (!$account instanceof \Drupal\user\AccountInterface) {
  fwrite(STDERR, "uid 1 not found\n");
  exit(1);
}
\Drupal::service("current_user")->setAccount($account);
// Lift the 50-row pager so the seeded rows are always inside the page.
$limit = new \ReflectionProperty(\Drupal\Core\Entity\EntityListBuilder::class, "limit");
try {
  foreach ($segs as $seg) {
    \Drupal::service("request_stack")->push(\Symfony\Component\HttpFoundation\Request::create("/admin/dx/delivery?status=" . $seg));
    try {
      $builder = \Drupal::entityTypeManager()->getListBuilder("dx_blueprint");
      $limit->setValue($builder, 0);
      $build = $builder->render();
      $html = (string) \Drupal::service("renderer")->renderInIsolation($build);
    }
    finally {
      \Drupal::service("request_stack")->pop();
    }
    echo "<<<" . $seg . ">>>\n" . $html . "\n";
  }
}
finally {
  $storage->delete($storage->loadMultiple(array_values($created)));
}
' >"$SEG_FILE"

must() { if ! grep -qF -- "$2" "$1"; then echo "F1 FAIL: missing [$2] in $1" >&2; exit 1; fi; }
must_not() { if grep -qF -- "$2" "$1"; then echo "F1 FAIL: unexpected [$2] in $1" >&2; exit 1; fi; }

for SEG in all draft confirmed executed failed bogus; do
  F="/tmp/dx-desk-seg-$SEG.html"
  awk -v seg="$SEG" 'BEGIN { p = "<<<" seg ">>>" } $0 == p { f = 1; next } /^<<<.*>>>$/ { f = 0 } f' "$SEG_FILE" >"$F"
  [[ -s "$F" ]] || { echo "F1 FAIL: segment $SEG rendered nothing" >&2; exit 1; }
  # Every partition advertises all four state links plus 全部.
  must "$F" "dx-deliver-tabs"
  for KEY in all draft confirmed executed failed; do
    if [[ "$KEY" == "all" ]]; then
      must "$F" "href=\"/admin/dx/delivery\""
    else
      must "$F" "status=$KEY"
    fi
  done
  # Exactly one tab is the current page.
  [[ "$(grep -oF 'aria-current="page"' "$F" | wc -l)" -eq 1 ]] || { echo "F1 FAIL: active tab count wrong in $SEG" >&2; exit 1; }
  # The default five columns stay in place on every partition.
  for COL in Label Tenant Status Type; do
    must "$F" "$COL"
  done
done

ID_DRAFT="$(sed -n 's/.*draft=\([0-9]\+\).*/\1/p' "$SEG_FILE" | head -1)"
ID_CONFIRMED="$(sed -n 's/.*confirmed=\([0-9]\+\).*/\1/p' "$SEG_FILE" | head -1)"
ID_COMPLETED="$(sed -n 's/.*completed=\([0-9]\+\).*/\1/p' "$SEG_FILE" | head -1)"
ID_FAILED="$(sed -n 's/.*failed=\([0-9]\+\).*/\1/p' "$SEG_FILE" | head -1)"
[[ -n "$ID_DRAFT$ID_CONFIRMED$ID_COMPLETED$ID_FAILED" ]]

# all / bogus (unknown value must behave like all) list every seeded blueprint.
for SEG in all bogus; do
  F="/tmp/dx-desk-seg-$SEG.html"
  must "$F" "data-status-filter=\"all\""
  for ID in "$ID_DRAFT" "$ID_CONFIRMED" "$ID_COMPLETED" "$ID_FAILED"; do
    must "$F" "/deliver/blueprint/$ID\""
  done
done

# Each partition only carries its own state; executed = running + completed.
declare -A EXPECT=(
  [draft]="$ID_DRAFT:$ID_CONFIRMED,$ID_COMPLETED,$ID_FAILED"
  [confirmed]="$ID_CONFIRMED:$ID_DRAFT,$ID_COMPLETED,$ID_FAILED"
  [executed]="$ID_COMPLETED:$ID_DRAFT,$ID_CONFIRMED,$ID_FAILED"
  [failed]="$ID_FAILED:$ID_DRAFT,$ID_CONFIRMED,$ID_COMPLETED"
)
for SEG in draft confirmed executed failed; do
  F="/tmp/dx-desk-seg-$SEG.html"
  must "$F" "data-status-filter=\"$SEG\""
  YES="${EXPECT[$SEG]%%:*}"
  NO="${EXPECT[$SEG]#*:}"
  must "$F" "/deliver/blueprint/$YES\""
  IFS=',' read -r -a ABSENT <<< "$NO"
  for ID in "${ABSENT[@]}"; do
    must_not "$F" "/deliver/blueprint/$ID\""
  done
done

# Only the failure partition offers the retry shortcut, and it is added next to
# the operations core already renders (the canonical admin link stays).
must "/tmp/dx-desk-seg-failed.html" "/deliver/blueprint/$ID_FAILED/confirm?status=failed"
must "/tmp/dx-desk-seg-failed.html" "href=\"/admin/dx/delivery/$ID_FAILED\""
for SEG in all draft confirmed executed bogus; do
  must_not "/tmp/dx-desk-seg-$SEG.html" "/confirm?status=failed"
done

echo "OK desk route=$ROUTE order=$ORDER http=$CODE/$ORDER_CODE admin=$ADMIN admin_access=$ADMIN_ACCESS partitions=5"
