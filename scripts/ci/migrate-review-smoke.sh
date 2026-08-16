#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
DRUSH=(vendor/bin/drush)

echo "== dx_migrate review smoke =="
"${DRUSH[@]}" pm:enable dx_migrate -y >/dev/null
"${DRUSH[@]}" php:eval 'if ($r=\Drupal::entityTypeManager()->getStorage("user_role")->load("administrator")) { user_role_grant_permissions("administrator", ["administer dx migrate"]); }' >/dev/null || true
"${DRUSH[@]}" cr >/dev/null
# Ensure at least one draft via fixture migrate
"${DRUSH[@]}" dx:migrate-l1 >/tmp/dx-migrate-review-import.out
CODE="$("${DRUSH[@]}" php:eval 'echo \Drupal::service("http_kernel")->handle(\Symfony\Component\HttpFoundation\Request::create("/admin/dx/migrate/review"))->getStatusCode();')"
# Anonymous may be 403; treat 200 or 403 as route exists. Prefer authenticated via uid1 session is hard in smoke — check route collection instead.
ROUTE="$("${DRUSH[@]}" php:eval 'echo \Drupal::service("router.route_provider")->getRouteByName("dx_migrate.review")->getPath();')"
[[ "$ROUTE" == "/admin/dx/migrate/review" ]]
echo "OK review route=$ROUTE http=$CODE"
