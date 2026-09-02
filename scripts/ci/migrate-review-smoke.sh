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

# ---------------------------------------------------------------------------
# G2 review-queue batch operations (roadmap Phase G).
# publish / discard / replay by external id: Drupal form-token guarded, capped
# per run, aggregated per item — a failing item never rolls back the successes.
# ---------------------------------------------------------------------------
BATCH_ROUTE="$("${DRUSH[@]}" php:eval 'echo \Drupal::service("router.route_provider")->getRouteByName("dx_migrate.review_batch")->getPath();')"
[[ "$BATCH_ROUTE" == "/admin/dx/migrate/review/batch" ]]
BATCH_FORM="$("${DRUSH[@]}" php:eval 'echo \Drupal::service("router.route_provider")->getRouteByName("dx_migrate.review_batch")->getDefault("_form");')"
# The form class is what gives the POST its form_id + form_token CSRF guard.
[[ "$BATCH_FORM" == "\Drupal\dx_migrate\Form\ReviewQueueBatchForm" ]]

# --dry-run plans a run and writes nothing: duplicates dropped, junk rejected,
# surplus reported instead of silently truncated.
"${DRUSH[@]}" dx:migrate-review-batch publish --dry-run "12,13,notanid,13" >/tmp/dx-mig-batch-plan.out
grep -q '"dry_run": true' /tmp/dx-mig-batch-plan.out
grep -q '"action": "publish"' /tmp/dx-mig-batch-plan.out
python3 - <<'PY'
import json
d = json.load(open('/tmp/dx-mig-batch-plan.out'))
assert d['limit'] == 50, d['limit']
assert d['would_process'] == ['12', '13'], d['would_process']
assert d['rejected'] == [{'input': 'notanid', 'reason': 'not a node id'}], d['rejected']
assert d['overflow'] == [], d['overflow']
print('batch plan ok')
PY
"${DRUSH[@]}" dx:migrate-review-batch publish --dry-run --limit=2 "1 2 3 4" >/tmp/dx-mig-batch-cap.out
python3 -c "import json;d=json.load(open('/tmp/dx-mig-batch-cap.out'));assert d['limit']==2 and d['would_process']==['1','2'] and d['overflow']==['3','4'];print('batch ceiling ok')"
if "${DRUSH[@]}" dx:migrate-review-batch explode --dry-run "1" >/tmp/dx-mig-batch-bad.out 2>&1; then
  echo "an unknown batch action must be refused"; exit 1
fi
grep -q 'Action must be one of' /tmp/dx-mig-batch-bad.out

# Partial failure: two real drafts + one missing node. The missing node is
# reported, the two published drafts stay published (no rollback).
"${DRUSH[@]}" dx:migrate-review-list >/tmp/dx-migrate-review-list2.out
PENDING_NOW="$(python3 -c 'import json; print(json.load(open("/tmp/dx-migrate-review-list2.out"))["pending"])')"
[[ "$PENDING_NOW" -eq "$((PENDING - 1))" ]] || { echo "discard above must shrink the queue"; exit 1; }
IDS="$(python3 -c 'import json; print(",".join(str(i["nid"]) for i in json.load(open("/tmp/dx-migrate-review-list2.out"))["items"][:2]))')"
[[ -n "$IDS" ]] || { echo "need two pending drafts"; exit 1; }
"${DRUSH[@]}" dx:migrate-review-batch publish "$IDS,99999999" >/tmp/dx-mig-batch-pub.out
sed -n '/^{/,/^}/p' /tmp/dx-mig-batch-pub.out > /tmp/dx-mig-batch-pub.json
python3 - <<'PY'
import json
d = json.load(open('/tmp/dx-mig-batch-pub.json'))
assert d['action'] == 'publish' and d['total'] == 3, d
assert d['succeeded'] == 2 and d['failed'] == 1, d
assert d['ok'] is False, d['ok']
assert len(d['succeeded_items']) == 2, d['succeeded_items']
assert d['failed_items'][0]['key'] == '99999999', d['failed_items']
assert len(d['results']) == 3, d['results']
assert [r['ok'] for r in d['results']] == [True, True, False], d['results']
print('partial failure reported, successes kept')
PY
PUBLISHED="$("${DRUSH[@]}" php:eval '
$ok = 0;
foreach (explode(",", "'"$IDS"'") as $nid) {
  $node = \Drupal::entityTypeManager()->getStorage("node")->load((int) $nid);
  if ($node && $node->isPublished()) { $ok++; }
}
echo $ok;')"
[[ "$PUBLISHED" == "2" ]] || { echo "published items must survive the failing item"; exit 1; }
"${DRUSH[@]}" dx:migrate-review-list >/tmp/dx-migrate-review-list3.out
PENDING_THEN="$(python3 -c 'import json; print(json.load(open("/tmp/dx-migrate-review-list3.out"))["pending"])')"
[[ "$PENDING_THEN" -eq "$((PENDING_NOW - 2))" ]] || { echo "queue must shrink by the 2 published drafts only"; exit 1; }

# Batch discard also clears the external map, so the node id can be reused.
"${DRUSH[@]}" dx:migrate-review-list >/tmp/dx-migrate-review-list4.out
DNID="$(python3 -c 'import json; d=json.load(open("/tmp/dx-migrate-review-list4.out"))["items"]; print(d[0]["nid"] if d else "")')"
if [[ -n "$DNID" ]]; then
  "${DRUSH[@]}" dx:migrate-review-batch discard "$DNID" >/tmp/dx-mig-batch-dis.out
  grep -q '"succeeded": 1' /tmp/dx-mig-batch-dis.out
  grep -q '"ok": true' /tmp/dx-mig-batch-dis.out
  "${DRUSH[@]}" php:eval '
$node = \Drupal::entityTypeManager()->getStorage("node")->load('"$DNID"');
echo $node ? "still-there" : "gone";' >/tmp/dx-mig-batch-dis-check.out
  grep -q "gone" /tmp/dx-mig-batch-dis-check.out
  # The external map must not keep pointing at a deleted node, or a re-import
  # of the same external id would be swallowed by the stale entry.
  ORPHAN="$("${DRUSH[@]}" php:eval '
$map = \Drupal::service("dx_channel.ingest")->getExternalMap();
echo count(array_filter($map, static fn($nid): bool => (int) $nid === '"$DNID"'));')"
  [[ "$ORPHAN" == "0" ]] || { echo "batch discard left a stale external mapping"; exit 1; }
fi

# Replay rebuilds a queue item from the stored payload snapshot and stays
# idempotent: the same external id maps to the same node, never a second copy.
"${DRUSH[@]}" dx:migrate-l2 --template=gov_news >/tmp/dx-mig-l2-real.out
grep -q '"ok": true' /tmp/dx-mig-l2-real.out
EXT="$("${DRUSH[@]}" php:eval '
$all = \Drupal::service("dx_migrate.review_payloads")->all();
krsort($all);
foreach ($all as $key => $row) {
  if (str_starts_with((string) $key, "article:")) {
    echo substr((string) $key, strlen("article:"));
    break;
  }
}')"
[[ -n "$EXT" ]] || { echo "a real L2 run must store a payload snapshot"; exit 1; }
NID_BEFORE="$(DX_EXT="$EXT" "${DRUSH[@]}" php:eval '
$map = \Drupal::service("dx_channel.ingest")->getExternalMap();
echo (int) ($map["article:" . getenv("DX_EXT")] ?? 0);')"
[[ "$NID_BEFORE" -gt 0 ]] || { echo "snapshot $EXT has no node behind it"; exit 1; }
"${DRUSH[@]}" dx:migrate-review-batch replay "$EXT,$EXT" >/tmp/dx-mig-batch-replay.out
grep -q '"succeeded": 1' /tmp/dx-mig-batch-replay.out
grep -q '"ok": true' /tmp/dx-mig-batch-replay.out
NID_AFTER="$(DX_EXT="$EXT" "${DRUSH[@]}" php:eval '
$map = \Drupal::service("dx_channel.ingest")->getExternalMap();
echo (int) ($map["article:" . getenv("DX_EXT")] ?? 0);')"
[[ "$NID_BEFORE" == "$NID_AFTER" ]] || { echo "replay must not create a second node"; exit 1; }
"${DRUSH[@]}" dx:migrate-review-batch replay "l1_not_a_snapshot" >/tmp/dx-mig-batch-replay-bad.out 2>&1 || true
grep -q '"failed": 1' /tmp/dx-mig-batch-replay-bad.out
grep -q '"succeeded": 0' /tmp/dx-mig-batch-replay-bad.out
grep -q '"ok": false' /tmp/dx-mig-batch-replay-bad.out
grep -q '快照' /tmp/dx-mig-batch-replay-bad.out

echo "OK review route=$ROUTE batch=$BATCH_ROUTE pending_before=$PENDING replay=$EXT/$NID_AFTER"
