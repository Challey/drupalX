#!/usr/bin/env bash
# Bootstrap the DrupalX platform site (database, settings, site:install, modules).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
WEB_ROOT="$PROJECT_ROOT/web"
export DCN_PROJECT_ROOT="$PROJECT_ROOT"

# shellcheck source=lib/env.sh
source "$SCRIPT_DIR/lib/env.sh"
dcn_load_env "$PROJECT_ROOT/.env"

DRUSH="$PROJECT_ROOT/vendor/bin/drush"
DB_NAME="${DCN_DB_PLATFORM:-dcn_platform}"
DB_HOST="${DCN_DB_HOST:-127.0.0.1}"
DB_PORT="${DCN_DB_PORT:-3306}"
DB_USER="${DCN_DB_USER:-root}"
DB_PASS="${DCN_DB_PASS:-}"

echo "==> Creating platform database via PHP PDO: $DB_NAME @ $DB_HOST"
php -r '
$h=getenv("DCN_DB_HOST") ?: "127.0.0.1";
$p=getenv("DCN_DB_PORT") ?: "3306";
$u=getenv("DCN_DB_USER") ?: "root";
$pw=getenv("DCN_DB_PASS") ?: "";
$db=getenv("DCN_DB_PLATFORM") ?: "dcn_platform";
$pdo=new PDO("mysql:host=$h;port=$p",$u,$pw,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$safe=str_replace("`","``",$db);
$pdo->exec("CREATE DATABASE IF NOT EXISTS `$safe` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
echo "Database ready: $db\n";
'

DEFAULT_SETTINGS="$WEB_ROOT/sites/default/settings.php"
if [[ ! -f "$DEFAULT_SETTINGS" ]]; then
  echo "==> Writing default settings.php"
  php -r '
$root = getenv("DCN_PROJECT_ROOT");
$tpl = $root . "/web/sites/example.tenant/settings.php";
$out = $root . "/web/sites/default/settings.php";
$c = file_get_contents($tpl);
$map = [
  "__DB_NAME__" => getenv("DCN_DB_PLATFORM") ?: "dcn_platform",
  "__DB_USER__" => getenv("DCN_DB_USER") ?: "root",
  "__DB_PASS__" => getenv("DCN_DB_PASS") ?: "",
  "__DB_HOST__" => getenv("DCN_DB_HOST") ?: "127.0.0.1",
  "__DB_PORT__" => getenv("DCN_DB_PORT") ?: "3306",
  "__HASH_SALT__" => bin2hex(random_bytes(32)),
];
$c = str_replace(array_keys($map), array_map("addslashes", $map), $c);
@mkdir($root . "/web/sites/default/files", 0775, true);
@mkdir($root . "/private/default", 0775, true);
@mkdir($root . "/config/sync/default", 0775, true);
file_put_contents($out, $c);
chmod($out, 0664);
echo "Wrote settings.php\n";
'
fi

DB_URL="$(php -r 'echo "mysql://".rawurlencode(getenv("DCN_DB_USER") ?: "root").":".rawurlencode(getenv("DCN_DB_PASS") ?: "")."@".(getenv("DCN_DB_HOST") ?: "127.0.0.1").":".(getenv("DCN_DB_PORT") ?: "3306")."/".(getenv("DCN_DB_PLATFORM") ?: "dcn_platform");')"

echo "==> Running site:install for platform (skip if already installed)"
cd "$PROJECT_ROOT"
if "$DRUSH" status --fields=bootstrap 2>/dev/null | grep -qi Successful; then
  echo "Platform already bootstrapped — skipping site:install"
else
  "$DRUSH" site:install standard --yes \
    --db-url="$DB_URL" \
    --account-name="${DCN_ADMIN_USER:-admin}" \
    --account-pass="${DCN_ADMIN_PASS:-admin}" \
    --account-mail="${DCN_ADMIN_MAIL:-admin@drupalx.local}" \
    --site-name="DrupalX Platform" \
    --site-mail="${DCN_ADMIN_MAIL:-admin@drupalx.local}"
fi

echo "==> Enabling platform modules"
"$DRUSH" pm:enable dcn_platform dcn_appstore dcn_ai_gateway admin_toolbar pathauto token metatag key --yes
"$DRUSH" theme:enable dcn_admin claro --yes
"$DRUSH" config:set system.theme admin dcn_admin -y || true

echo "==> Seeding app store catalog"
"$DRUSH" cr
"$DRUSH" dcn:appstore-seed || echo "Warning: app store seed skipped"

echo "==> Platform bootstrap complete"
echo "Admin: ${DCN_ADMIN_USER:-admin} / ${DCN_ADMIN_PASS:-admin}"
echo "Docroot: $WEB_ROOT"
