#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
DRUSH=(vendor/bin/drush)
echo "== delivery desk smoke =="
"${DRUSH[@]}" pm:enable dx_delivery dx_health -y >/dev/null
"${DRUSH[@]}" cr >/dev/null
ROUTE="$("${DRUSH[@]}" php:eval 'echo \Drupal::service("router.route_provider")->getRouteByName("dx_delivery.desk")->getPath();')"
[[ "$ROUTE" == "/deliver" ]]
# Anonymous may 403; grant and recheck
"${DRUSH[@]}" php:eval 'user_role_grant_permissions("anonymous", ["access dx delivery desk"]);' >/dev/null || true
CODE="$("${DRUSH[@]}" php:eval 'echo \Drupal::service("http_kernel")->handle(\Symfony\Component\HttpFoundation\Request::create("/deliver"))->getStatusCode();')"
[[ "$CODE" == "200" ]]
echo "OK desk route=$ROUTE http=$CODE"
