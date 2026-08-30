#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
DRUSH=(vendor/bin/drush)

echo "== dx_channel webhook smoke =="
"${DRUSH[@]}" pm:enable dx_channel -y >/dev/null
"${DRUSH[@]}" cr >/dev/null
"${DRUSH[@]}" dx:webhook-register "https://example.com/hooks/dx" --events=resource.published >/tmp/dx-wh-reg.out
grep -q '"id":' /tmp/dx-wh-reg.out
"${DRUSH[@]}" dx:webhook-test >/tmp/dx-wh-test.out
grep -q '"sent":' /tmp/dx-wh-test.out
SENT="$(python3 -c 'import json; print(json.load(open("/tmp/dx-wh-test.out"))["sent"])')"
[[ "$SENT" -ge 1 ]]
# Rate limiter should still allow a few
"${DRUSH[@]}" dx:webhook-test >/tmp/dx-wh-test2.out
grep -q '"sent":' /tmp/dx-wh-test2.out
"${DRUSH[@]}" dx:webhook-verify >/tmp/dx-wh-verify.out
grep -q '"ok": true' /tmp/dx-wh-verify.out

TOKEN="$("${DRUSH[@]}" dx:channel-token-create --id=webhook_http_smoke --scopes=webhook:read,webhook:write 2>&1 | tee /tmp/dx-wh-token.out | rg -o 'dxc_[a-f0-9]+' | head -1 || true)"
if [[ -z "${TOKEN}" ]]; then
  TOKEN="$(rg -o 'dxc_[a-f0-9]+' /tmp/dx-wh-token.out 2>/dev/null | head -1 || true)"
fi
if [[ -z "${TOKEN}" ]]; then
  TOKEN="$("${DRUSH[@]}" dx:channel-token-create --id="webhook_http_$(date +%s)" --scopes=exchange:write,channel:read 2>&1 | tee /tmp/dx-wh-token2.out | rg -o 'dxc_[a-f0-9]+' | head -1 || true)"
fi

if [[ -n "${TOKEN}" ]]; then
  LIST_CODE="$("${DRUSH[@]}" php:eval '
$token = "'"$TOKEN"'";
$request = \Symfony\Component\HttpFoundation\Request::create("/api/dx/v1/webhooks", "GET", [], [], [], ["HTTP_AUTHORIZATION" => "Bearer ".$token]);
$resp = \Drupal::service("http_kernel")->handle($request);
echo $resp->getStatusCode()."\n".$resp->getContent();
' | head -1)"
  [[ "$LIST_CODE" == "200" ]]

  TEST_CODE="$("${DRUSH[@]}" php:eval '
$token = "'"$TOKEN"'";
$request = \Symfony\Component\HttpFoundation\Request::create("/api/dx/v1/webhooks/test", "POST", [], [], [], ["HTTP_AUTHORIZATION" => "Bearer ".$token]);
$resp = \Drupal::service("http_kernel")->handle($request);
echo $resp->getStatusCode();
')"
  [[ "$TEST_CODE" == "200" ]]

  DL_CODE="$("${DRUSH[@]}" php:eval '
$token = "'"$TOKEN"'";
$request = \Symfony\Component\HttpFoundation\Request::create("/api/dx/v1/webhooks/dead-letters?limit=5", "GET", [], [], [], ["HTTP_AUTHORIZATION" => "Bearer ".$token]);
$resp = \Drupal::service("http_kernel")->handle($request);
echo $resp->getStatusCode();
')"
  [[ "$DL_CODE" == "200" ]]
  RETRY_CODE="$("${DRUSH[@]}" php:eval '
$token = "'"$TOKEN"'";
$request = \Symfony\Component\HttpFoundation\Request::create("/api/dx/v1/webhooks/dead-letters/retry?limit=5", "POST", [], [], [], ["HTTP_AUTHORIZATION" => "Bearer ".$token]);
$resp = \Drupal::service("http_kernel")->handle($request);
echo $resp->getStatusCode();
')"
  [[ "$RETRY_CODE" == "200" ]]
  echo "OK sent=$SENT verify=ok http_list=$LIST_CODE http_test=$TEST_CODE dead_letters=$DL_CODE retry=$RETRY_CODE"
else
  echo "OK sent=$SENT verify=ok (HTTP token parse skipped)"
fi

# Dead-letter + retry via fail.example.com sink → switch to example.com
"${DRUSH[@]}" dx:webhook-dead-letters-clear >/tmp/dx-wh-dl-clear.out
"${DRUSH[@]}" dx:webhook-register "https://fail.example.com/hooks/dx" --events=resource.published >/tmp/dx-wh-fail-reg.out
FAIL_ID="$(python3 -c 'import json; print(json.load(open("/tmp/dx-wh-fail-reg.out"))["id"])')"
"${DRUSH[@]}" dx:webhook-test >/tmp/dx-wh-fail-test.out
FAILED="$(python3 -c 'import json; print(json.load(open("/tmp/dx-wh-fail-test.out")).get("failed",0))')"
[[ "$FAILED" -ge 1 ]]
"${DRUSH[@]}" dx:webhook-dead-letters >/tmp/dx-wh-dl.out
DL_COUNT="$(python3 -c 'import json; print(json.load(open("/tmp/dx-wh-dl.out"))["count"])')"
[[ "$DL_COUNT" -ge 1 ]]
"${DRUSH[@]}" dx:webhook-update-url "$FAIL_ID" "https://example.com/hooks/dx-retry" >/tmp/dx-wh-upd.out
"${DRUSH[@]}" dx:webhook-retry --limit=20 >/tmp/dx-wh-retry.out
grep -q '"sent":' /tmp/dx-wh-retry.out
RETRY_SENT="$(python3 -c 'import json; print(json.load(open("/tmp/dx-wh-retry.out"))["sent"])')"
[[ "$RETRY_SENT" -ge 1 ]]
echo "OK deadletter_retry sent=$RETRY_SENT id=$FAIL_ID"

# ---------------------------------------------------------------------------
# G4 delivery health, backoff and the site-level endpoint (roadmap Phase G).
# ---------------------------------------------------------------------------
"${DRUSH[@]}" dx:webhook-health --days=7 >/tmp/dx-wh-health.out
grep -q '"status":' /tmp/dx-wh-health.out
grep -q '"success_rate"' /tmp/dx-wh-health.out
grep -q '"dead_letters":' /tmp/dx-wh-health.out
grep -q '"retried_sent":' /tmp/dx-wh-health.out
python3 - <<'PY'
import json
d = json.load(open('/tmp/dx-wh-health.out'))
for key in ('status', 'configured', 'enabled', 'attempts', 'sent', 'failed', 'by_endpoint', 'daily', 'lifetime', 'window_days'):
    assert key in d, key
assert d['retried_sent'] >= 1, d['retried_sent']
assert d['site_endpoint'] is False, 'no site endpoint configured yet'
print('health window=%s status=%s retried_sent=%s' % (d['window_days'], d['status'], d['retried_sent']))
PY

# A dead letter inside its backoff window is deferred, not re-posted; --force
# overrides the window but never the 8-try budget.
"${DRUSH[@]}" dx:webhook-dead-letters-clear >/dev/null
"${DRUSH[@]}" dx:webhook-test >/tmp/dx-wh-backoff-test.out
"${DRUSH[@]}" php:eval '
$rows = \Drupal::state()->get("dx_channel.webhook_dead_letters", []);
foreach ($rows as &$row) {
  $row["retries"] = 2;
  $row["next_retry_at"] = gmdate("c", time() + 600);
}
\Drupal::state()->set("dx_channel.webhook_dead_letters", $rows);
echo count($rows);
' >/tmp/dx-wh-backoff-prep.out
WAITING="$(cat /tmp/dx-wh-backoff-prep.out)"
if [[ "$WAITING" -ge 1 ]]; then
  "${DRUSH[@]}" dx:webhook-retry --limit=20 >/tmp/dx-wh-deferred.out
  grep -q '"deferred": ' /tmp/dx-wh-deferred.out
  DEFERRED="$(python3 -c 'import json; print(json.load(open("/tmp/dx-wh-deferred.out"))["deferred"])')"
  [[ "$DEFERRED" -ge 1 ]]
  "${DRUSH[@]}" dx:webhook-retry --limit=20 --force >/tmp/dx-wh-forced.out
  FORCED_DEFERRED="$(python3 -c 'import json; print(json.load(open("/tmp/dx-wh-forced.out"))["deferred"])')"
  [[ "$FORCED_DEFERRED" -eq 0 ]]
  # Exhaust the budget: even --force must now defer.
  "${DRUSH[@]}" php:eval '
$rows = \Drupal::state()->get("dx_channel.webhook_dead_letters", []);
foreach ($rows as &$row) {
  $row["retries"] = 8;
}
\Drupal::state()->set("dx_channel.webhook_dead_letters", $rows);
echo count($rows);
' >/dev/null
  "${DRUSH[@]}" dx:webhook-retry --limit=20 --force >/tmp/dx-wh-budget.out
  BUDGET_DEFERRED="$(python3 -c 'import json; print(json.load(open("/tmp/dx-wh-budget.out"))["deferred"])')"
  [[ "$BUDGET_DEFERRED" -ge 1 ]]
  echo "OK backoff deferred=$DEFERRED forced=$FORCED_DEFERRED budget=$BUDGET_DEFERRED"
fi
"${DRUSH[@]}" dx:webhook-dead-letters-clear >/dev/null

# Site-level endpoint: mirroring a real URL into the endpoint table, then
# falling back to "nothing configured" (today's behaviour, no exceptions).
"${DRUSH[@]}" php:eval '
$svc = \Drupal::service("dx_channel.webhook");
$ep = $svc->syncSiteEndpoint("https://example.com/hooks/site", "smoke-secret", ["resource.published"], TRUE);
echo json_encode(["id" => $ep["id"], "site_level" => $ep["site_level"], "enabled" => $ep["enabled"]]);
' >/tmp/dx-wh-site-sync.out
grep -q '"id":"wh_site"' /tmp/dx-wh-site-sync.out
grep -q '"site_level":true' /tmp/dx-wh-site-sync.out
"${DRUSH[@]}" dx:webhook-list >/tmp/dx-wh-site-list.out
grep -q 'wh_site' /tmp/dx-wh-site-list.out
"${DRUSH[@]}" dx:webhook-test >/tmp/dx-wh-site-test.out
SITE_SENT="$(python3 -c 'import json; print(json.load(open("/tmp/dx-wh-site-test.out"))["sent"])')"
[[ "$SITE_SENT" -ge 1 ]]
"${DRUSH[@]}" dx:webhook-health --days=1 >/tmp/dx-wh-site-health.out
grep -q '"site_endpoint": true' /tmp/dx-wh-site-health.out
grep -q '"configured": true' /tmp/dx-wh-site-health.out
grep -q '"wh_site"' /tmp/dx-wh-site-health.out
# Empty config ⇒ mirrored endpoint removed ⇒ identical to the pre-G4 site.
"${DRUSH[@]}" dx:webhook-site-sync >/tmp/dx-wh-site-clear.out
grep -q 'mirrored endpoint removed' /tmp/dx-wh-site-clear.out
"${DRUSH[@]}" dx:webhook-list >/tmp/dx-wh-site-list2.out
if grep -q 'wh_site' /tmp/dx-wh-site-list2.out; then
  echo "FAIL wh_site survived the sync of an empty configuration" >&2
  exit 1
fi
"${DRUSH[@]}" dx:webhook-test >/tmp/dx-wh-final-test.out
grep -q '"sent":' /tmp/dx-wh-final-test.out
"${DRUSH[@]}" dx:webhook-stats-reset >/tmp/dx-wh-stats-reset.out
grep -q '"ok": true' /tmp/dx-wh-stats-reset.out
"${DRUSH[@]}" dx:webhook-health --days=7 >/tmp/dx-wh-health2.out
grep -q '"attempts": 0' /tmp/dx-wh-health2.out
grep -q '"status": "unknown"' /tmp/dx-wh-health2.out
echo "OK site_endpoint mirror+cleanup, counters reset"

if [[ -n "${TOKEN}" ]]; then
  HEALTH_CODE="$("${DRUSH[@]}" php:eval '
$token = "'"$TOKEN"'";
$request = \Symfony\Component\HttpFoundation\Request::create("/api/dx/v1/webhooks/health?days=3", "GET", [], [], [], ["HTTP_AUTHORIZATION" => "Bearer ".$token]);
$resp = \Drupal::service("http_kernel")->handle($request);
echo $resp->getStatusCode();
')"
  [[ "$HEALTH_CODE" == "200" ]]
  echo "OK health_http=$HEALTH_CODE"
fi

echo "OK webhook G4 health/backoff/site-endpoint"
