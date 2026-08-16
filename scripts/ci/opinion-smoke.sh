#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
DRUSH=(vendor/bin/drush)
echo "== dx_opinion smoke =="
"${DRUSH[@]}" pm:enable dx_opinion -y >/dev/null
"${DRUSH[@]}" php:eval 'user_role_grant_permissions("anonymous", ["access dx opinion"]); user_role_grant_permissions("authenticated", ["access dx opinion"]);' >/dev/null
"${DRUSH[@]}" cr >/dev/null
CODE="$("${DRUSH[@]}" php:eval 'echo \Drupal::service("http_kernel")->handle(\Symfony\Component\HttpFoundation\Request::create("/opinion"))->getStatusCode();')"
[[ "$CODE" == "200" ]]
# Licensed mode sink (example.com)
"${DRUSH[@]}" php:eval '\Drupal::configFactory()->getEditable("dx_opinion.settings")->set("data_source_mode","licensed")->set("licensed_endpoint","https://example.com/opinion.json")->save();' >/dev/null
CODE2="$("${DRUSH[@]}" php:eval 'echo \Drupal::service("http_kernel")->handle(\Symfony\Component\HttpFoundation\Request::create("/opinion"))->getStatusCode();')"
[[ "$CODE2" == "200" ]]

# Local fixture:// file source
"${DRUSH[@]}" php:eval '\Drupal::configFactory()->getEditable("dx_opinion.settings")->set("data_source_mode","licensed")->set("licensed_endpoint","fixture://licensed-sample.json")->save();' >/dev/null
"${DRUSH[@]}" dx:opinion-status >/tmp/dx-opinion-status.out
grep -q '"licensed_ok": true' /tmp/dx-opinion-status.out
grep -q '文件源' /tmp/dx-opinion-status.out
COUNT="$(python3 -c 'import json; print(json.load(open("/tmp/dx-opinion-status.out"))["item_count"])')"
[[ "$COUNT" -ge 3 ]]

echo "OK /opinion status=$CODE licensed=$CODE2 fixture_items=$COUNT"
