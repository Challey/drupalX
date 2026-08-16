#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
DRUSH=(vendor/bin/drush)

echo "== dx_channel audit smoke =="
"${DRUSH[@]}" pm:enable dx_channel -y >/dev/null
# Rebuild container if needed
"${DRUSH[@]}" cr >/dev/null || php -r '
chdir("web");
$autoloader=require "autoload.php";
require "core/includes/bootstrap.inc";
\Drupal\Core\DrupalKernel::bootEnvironment();
$kernel=\Drupal\Core\DrupalKernel::createFromRequest(\Symfony\Component\HttpFoundation\Request::createFromGlobals(),$autoloader,"prod");
$kernel->setSitePath("sites/default");
$kernel->boot();
$kernel->invalidateContainer();
$kernel->rebuildContainer();
echo "rebuilt\n";
' >/dev/null

TOKEN="$("${DRUSH[@]}" dx:channel-token-create --id="audit_smoke_$(date +%s)" --scopes=channel:read 2>&1 | tee /tmp/dx-audit-token.out | rg -o 'dxc_[a-f0-9]+' | head -1)"
[[ -n "$TOKEN" ]]

CODE="$("${DRUSH[@]}" php:eval '
$token="'"$TOKEN"'";
$req=\Symfony\Component\HttpFoundation\Request::create("/api/dx/v1/channel/site","GET",[],[],[],["HTTP_AUTHORIZATION"=>"Bearer ".$token]);
echo \Drupal::service("http_kernel")->handle($req)->getStatusCode();
')"
[[ "$CODE" == "200" ]]

"${DRUSH[@]}" dx:channel-audit --limit=5 >/tmp/dx-audit.out
grep -q 'channel/site' /tmp/dx-audit.out || grep -q '/api/dx/v1/channel/site' /tmp/dx-audit.out
echo "OK audit logged status=$CODE"
