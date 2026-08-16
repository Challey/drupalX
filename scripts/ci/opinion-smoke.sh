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
echo "OK /opinion status=$CODE"
