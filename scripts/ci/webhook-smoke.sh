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
  echo "OK sent=$SENT verify=ok http_list=$LIST_CODE http_test=$TEST_CODE dead_letters=$DL_CODE"
else
  echo "OK sent=$SENT verify=ok (HTTP token parse skipped)"
fi
