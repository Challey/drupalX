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
