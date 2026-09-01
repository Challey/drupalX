#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
DRUSH=(vendor/bin/drush)

echo "== dx_delivery smoke =="
"${DRUSH[@]}" pm:enable dx_delivery dx_migrate dx_opinion dx_trust dx_health -y >/dev/null
"${DRUSH[@]}" cr >/dev/null

UNIQUE="dxsmoke$(date +%s | tail -c 5)"
MSG='gov portal, steady theme, need miniprogram and opinion'
"${DRUSH[@]}" dx:delivery-from-chat "$MSG" --machine-name="$UNIQUE" >/tmp/dx-delivery-from-chat.out
ID="$("${DRUSH[@]}" dx:delivery-list 2>/dev/null | awk -v m="$UNIQUE" '$0 ~ m {print $1; exit}')"
if [[ -z "$ID" ]]; then
  echo "Failed to resolve blueprint id" >&2
  cat /tmp/dx-delivery-from-chat.out >&2
  exit 1
fi
echo "blueprint id=$ID machine=$UNIQUE"

# ---------------------------------------------------------------------------
# F4 - the v3 export must describe an undelivered item instead of hiding it. The
# same blueprint is exported before and after the run: the draft pass proves
# "all four deliverable keys present, none resolved", the executed pass (below)
# proves the ops links became a real block while any unregistered site path
# stays explicitly missing.
# ---------------------------------------------------------------------------
check_v3_export() {
  # $1 = export file, $2 = expected status segment, $3 = expected resolved count or "any"
  php -r '
$fail = static function (string $message): void {
  fwrite(STDERR, "v3 export check: " . $message . "\n");
  exit(1);
};
$file = $argv[1];
$segment = $argv[2];
$expectOk = $argv[3];
$raw = (string) file_get_contents($file);
if (function_exists("json_validate") && !json_validate($raw)) {
  $fail("$file is not parseable JSON");
}
$data = json_decode($raw, TRUE);
if (!is_array($data)) {
  $fail("$file does not decode into an object");
}
if (($data["spec"] ?? "") !== "DX-ACCEPTANCE" || ($data["spec_version"] ?? "") !== "3.0") {
  $fail("$file spec header changed");
}
if (($data["status_segment"] ?? "") !== $segment) {
  $fail("$file status_segment is " . var_export($data["status_segment"] ?? NULL, TRUE) . ", expected $segment");
}
$block = $data["deliverables"] ?? NULL;
if (!is_array($block) || ($block["spec"] ?? "") !== "DX-DELIVERABLES") {
  $fail("$file lost the deliverables block");
}
$items = $block["items"] ?? [];
$keys = array_keys($items);
sort($keys);
if ($keys !== ["api_docs", "certs", "l3_source", "ops_handbook"] || $block["total"] !== 4) {
  $fail("$file deliverables are " . implode(",", $keys) . " total=" . var_export($block["total"] ?? NULL, TRUE));
}
$missing = 0;
foreach ($items as $key => $item) {
  foreach (["key", "label", "type", "value", "url", "path", "status", "note"] as $col) {
    if (!array_key_exists($col, $item)) {
      $fail("$file item $key lost column $col");
    }
  }
  if (($item["key"] ?? "") !== $key) {
    $fail("$file item $key carries key " . var_export($item["key"] ?? NULL, TRUE));
  }
  if ($item["label"] === "" || $item["type"] === "") {
    $fail("$file item $key has no label or type");
  }
  if (!in_array($item["status"], ["ok", "missing"], TRUE)) {
    $fail("$file item $key status is " . var_export($item["status"], TRUE));
  }
  if ($item["note"] === "") {
    $fail("$file item $key gives no explanation");
  }
  if ($item["status"] === "missing") {
    $missing++;
  }
}
if ($block["ok"] !== 4 - $missing || count($block["missing"]) !== $missing) {
  $fail("$file ok/missing counters disagree");
}
if ($expectOk !== "any" && $block["ok"] !== (int) $expectOk) {
  $fail("$file resolved " . $block["ok"] . " deliverables, expected $expectOk");
}
echo "OK v3 segment=$segment ok=" . $block["ok"] . " missing=$missing\n";
' "$1" "$2" "$3"
}

"${DRUSH[@]}" dx:delivery-export "$ID" /tmp/dx-delivery-draft.json >/tmp/dx-delivery-draft.out
grep -qE '"spec_version": ?"3\.0"' /tmp/dx-delivery-draft.out
grep -qE '"deliverables_total": ?4' /tmp/dx-delivery-draft.out
grep -qE '"deliverables_ok": ?0' /tmp/dx-delivery-draft.out
test -s /tmp/dx-delivery-draft.json
check_v3_export /tmp/dx-delivery-draft.json draft 0

"${DRUSH[@]}" dx:delivery-run "$ID" --skip-provision --skip-pack >/tmp/dx-delivery-run.out
grep -q '"passed": true' /tmp/dx-delivery-run.out
grep -q '"id": "capabilities"' /tmp/dx-delivery-run.out
grep -q '"id": "trust_policy"' /tmp/dx-delivery-run.out
STATUS="$("${DRUSH[@]}" dx:delivery-list 2>/dev/null | awk -v id="$ID" '$1==id {print $2; exit}')"
echo "status=$STATUS"
[[ "$STATUS" == "completed" ]]

"${DRUSH[@]}" dx:delivery-export "$ID" /tmp/dx-delivery-executed.json >/tmp/dx-delivery-executed.out
grep -qE '"ok": ?true' /tmp/dx-delivery-executed.out
grep -qE '"bytes": ?[1-9][0-9]{2,}' /tmp/dx-delivery-executed.out
test -s /tmp/dx-delivery-executed.json
check_v3_export /tmp/dx-delivery-executed.json executed any
php -r '
$data = json_decode((string) file_get_contents("/tmp/dx-delivery-executed.json"), TRUE);
$items = $data["deliverables"]["items"];
if ($items["ops_handbook"]["value"] !== "docs/delivery.md") {
  fwrite(STDERR, "ops handbook does not point at docs/delivery.md\n");
  exit(1);
}
if ($items["ops_handbook"]["status"] !== "ok") {
  fwrite(STDERR, "ops handbook reported missing: " . $items["ops_handbook"]["note"] . "\n");
  exit(1);
}
if ($data["blueprint_id"] !== (int) $argv[1] || $data["passed"] !== TRUE) {
  fwrite(STDERR, "executed export lost blueprint_id/passed\n");
  exit(1);
}
foreach (["api_docs", "certs", "l3_source"] as $key) {
  $item = $items[$key];
  if ($item["status"] === "missing" && !str_contains($item["note"], "路由")) {
    fwrite(STDERR, "$key is missing without saying why\n");
    exit(1);
  }
}
echo "OK v3 executed handbook=" . $items["ops_handbook"]["path"] . "\n";
' "$ID"

echo "OK"
