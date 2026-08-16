#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
DRUSH=(vendor/bin/drush)
echo "== delivery desk smoke =="
"${DRUSH[@]}" pm:enable dx_delivery dx_health -y >/dev/null
"${DRUSH[@]}" cr >/dev/null
CODE="$("${DRUSH[@]}" php:eval "echo \\Drupal::service('http_kernel')->handle(\\Symfony\\Component\\HttpFoundation\\Request::create('/deliver'))->getStatusCode();")"
# may be 200 or 403 depending on perms for anon
[[ "$CODE" == "200" || "$CODE" == "403" ]]
ROUTE="$("${DRUSH[@]}" php:eval 'echo \\Drupal::service("router.route_provider")->getRouteByName("dx_delivery.desk")->getPath();')"
[[ "$ROUTE" == "/deliver" ]]
echo "OK desk route=$ROUTE http=$CODE"
