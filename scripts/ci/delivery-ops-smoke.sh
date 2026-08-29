#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
DRUSH=(vendor/bin/drush)

echo "== delivery ops smoke =="
# Pure logic first: no site, no database, fails before anything is written.
php web/modules/custom/dx_delivery/tests/pure-assertions.php | tail -1
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

# ---------------------------------------------------------------------------
# F4 - acceptance report v3: the four operator deliverables (ops handbook, API
# docs, certificate vault, L3 source bundle) come out as one block, the
# artifact is one parseable JSON file, and an unavailable item is marked
# "missing" instead of being dropped.
# ---------------------------------------------------------------------------
"${DRUSH[@]}" dx:delivery-export "$ID" /tmp/dx-acceptance-export.json >/tmp/dx-export.out
DL="$("${DRUSH[@]}" php:eval 'echo \Drupal::service("router.route_provider")->getRouteByName("dx_delivery.acceptance_download")->getPath();')"
[[ "$DL" == *acceptance.json ]]

grep -qE '"ok": ?true' /tmp/dx-export.out
test -s /tmp/dx-acceptance-export.json
grep -qE '"spec_version": ?"3\.0"' /tmp/dx-export.out
grep -qE '"deliverables_total": ?4' /tmp/dx-export.out

php -r '
$fail = static function (string $message): void {
  fwrite(STDERR, "v3 export: " . $message . "\n");
  exit(1);
};
$raw = (string) file_get_contents($argv[1]);
if (function_exists("json_validate") && !json_validate($raw)) {
  $fail("file is not parseable JSON");
}
$data = json_decode($raw, TRUE);
if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
  $fail("JSON does not decode into an object: " . json_last_error_msg());
}
foreach (["spec", "spec_version", "exported_at", "blueprint_id", "label", "status", "status_segment",
  "machine_name", "site_type", "channels", "capabilities", "migrate_level", "portal_url", "passed",
  "steps", "acceptance", "deliverables", "handoff_todos", "log"] as $key) {
  if (!array_key_exists($key, $data)) {
    $fail("key $key dropped from the payload");
  }
}
if ($data["spec"] !== "DX-ACCEPTANCE" || $data["spec_version"] !== "3.0") {
  $fail("spec header changed");
}
$block = $data["deliverables"];
foreach (["spec", "total", "ok", "missing", "items"] as $key) {
  if (!array_key_exists($key, $block)) {
    $fail("deliverables block lost $key");
  }
}
foreach (["ops_handbook", "api_docs", "certs", "l3_source"] as $key) {
  if (!isset($block["items"][$key]) || !is_array($block["items"][$key])) {
    $fail("deliverable $key dropped instead of marked");
  }
  foreach (["key", "label", "type", "value", "url", "path", "status", "note"] as $col) {
    if (!array_key_exists($col, $block["items"][$key])) {
      $fail("deliverable $key lost column $col");
    }
  }
  if (!in_array($block["items"][$key]["status"], ["ok", "missing"], TRUE)) {
    $fail("deliverable $key status is " . var_export($block["items"][$key]["status"], TRUE));
  }
}
if (count($block["items"]) !== 4 || $block["total"] !== 4) {
  $fail("deliverables block is not exactly four items");
}
if (count($block["missing"]) !== 4 - (int) $block["ok"]) {
  $fail("ok/missing counters disagree");
}
foreach ($block["missing"] as $key) {
  if (($block["items"][$key]["status"] ?? "") !== "missing") {
    $fail("$key is listed as missing but not marked");
  }
}
$items = $data["handoff_todos"]["items"] ?? [];
foreach (["total", "open", "done", "overdue", "missing_sla"] as $col) {
  if (!array_key_exists($col, $data["handoff_todos"]["stats"] ?? [])) {
    $fail("handoff todo stats lost $col");
  }
}
if ($items !== []) {
  foreach (["id", "title", "status", "kind", "owner", "due", "remark", "notes", "done_at", "sla_updated_at", "open", "overdue"] as $col) {
    if (!array_key_exists($col, $items[0])) {
      $fail("todo row lost SLA column $col");
    }
  }
}
if (($data["status_segment"] ?? "") === "") {
  $fail("status_segment is empty");
}
echo "OK v3 deliverables=" . implode(",", array_keys($block["items"]))
  . " missing=" . count($block["missing"])
  . " segment=" . $data["status_segment"] . "\n";
' /tmp/dx-acceptance-export.json

"${DRUSH[@]}" dx:delivery-report "$ID" >/tmp/dx-ops-report-v3.out
grep -q '"spec_version"' /tmp/dx-ops-report-v3.out
grep -q '"deliverables"' /tmp/dx-ops-report-v3.out
grep -q '"todo_stats"' /tmp/dx-ops-report-v3.out
grep -q '"ops_handbook"' /tmp/dx-ops-report-v3.out
grep -q '"l3_source"' /tmp/dx-ops-report-v3.out

# The blueprint acceptance download keeps its historical payload: the new
# deliverables block is opt-in through ?deliverables=1.
"${DRUSH[@]}" php:eval '
$bp = \Drupal::entityTypeManager()->getStorage("dx_blueprint")->load((int) $argv[1]);
if (!$bp instanceof \Drupal\dx_delivery\Entity\DeliveryBlueprint) {
  fwrite(STDERR, "blueprint vanished\n");
  exit(1);
}
$controller = \Drupal\dx_delivery\Controller\DeliveryDeskController::create(\Drupal::getContainer());
echo "<<<default>>>\n" . $controller->acceptanceDownload($bp, \Symfony\Component\HttpFoundation\Request::create("/x"))->getContent() . "\n";
echo "<<<withblock>>>\n" . $controller->acceptanceDownload($bp, \Symfony\Component\HttpFoundation\Request::create("/x", "GET", ["deliverables" => 1]))->getContent() . "\n";
' "$ID" >/tmp/dx-ops-acceptance.out
for VARIANT in default withblock; do
  awk -v seg="$VARIANT" 'BEGIN { p = "<<<" seg ">>>" } $0 == p { f = 1; next } /^<<<.*>>>$/ { f = 0 } f' /tmp/dx-ops-acceptance.out >/tmp/dx-ops-acceptance-$VARIANT.json
  php -r 'exit(json_decode((string) file_get_contents($argv[1]), TRUE) === NULL ? 1 : 0);' "/tmp/dx-ops-acceptance-$VARIANT.json" \
    || { echo "acceptance download ($VARIANT) is not parseable JSON" >&2; exit 1; }
done
grep -q '"ops_handbook"' /tmp/dx-ops-acceptance-withblock.json
grep -q '"spec_version"' /tmp/dx-ops-acceptance-withblock.json
if grep -q 'deliverables' /tmp/dx-ops-acceptance-default.json; then
  echo "acceptance download default output changed" >&2
  exit 1
fi

echo "OK id=$ID audit_route=$ROUTE download_route=$DL export=v3 acceptance_block=opt-in"
