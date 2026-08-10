#!/usr/bin/env bash
set -euo pipefail
PKG="$(cd "$(dirname "$0")/.." && pwd)"
UPGRADE_DIR="$(cd "$PKG/.." && pwd)"
DRUPAL="$(cd "$UPGRADE_DIR/../.." && pwd)"

[[ "$(basename "$UPGRADE_DIR")" == "portal-theme" ]] || { echo "ERROR: put under upgrade/portal-theme/" >&2; exit 1; }
[[ -d "$DRUPAL/web" ]] || { echo "ERROR: missing $DRUPAL/web" >&2; exit 1; }

THEME=dx_portal_theme
BK="$UPGRADE_DIR/backups/pre-deploy-$(date +%Y%m%d_%H%M%S)"
mkdir -p "$BK"
[[ -d "$DRUPAL/web/themes/custom/$THEME" ]] && rsync -a "$DRUPAL/web/themes/custom/$THEME/" "$BK/$THEME/" || true
echo "Backup: $BK"

SRC="$PKG/web/themes/custom/$THEME"
[[ -d "$SRC" ]] || { echo "ERROR: theme missing in package" >&2; exit 1; }
mkdir -p "$DRUPAL/web/themes/custom/$THEME"
rsync -av --delete --no-owner --no-group --chmod=Du=rwx,Dgo=rx,Fu=rw,Fgo=r \
  "$SRC/" "$DRUPAL/web/themes/custom/$THEME/"

mkdir -p "$DRUPAL/web/sites/default/files/css" "$DRUPAL/web/sites/default/files/js" \
  "$DRUPAL/private/default"
if id www >/dev/null 2>&1; then
  chown -R www:www "$DRUPAL/web/themes/custom/$THEME" "$DRUPAL/web/sites/default/files" "$DRUPAL/private" 2>/dev/null || true
fi
chmod -R a+rX "$DRUPAL/web/themes/custom/$THEME"
chmod -R u+rwX "$DRUPAL/web/sites/default/files" "$DRUPAL/private" 2>/dev/null || true

run_drush() {
  if [[ -x "$DRUPAL/vendor/bin/drush.php" ]]; then
    (cd "$DRUPAL" && php -d memory_limit=512M "$DRUPAL/vendor/bin/drush.php" "$@") && return 0
  fi
  if [[ -x "$DRUPAL/vendor/bin/drush" ]]; then
    (cd "$DRUPAL" && php -d memory_limit=512M "$DRUPAL/vendor/bin/drush" "$@") && return 0
  fi
  return 1
}

run_drush config:set system.performance css.preprocess 0 -y || true
run_drush config:set system.performance js.preprocess 0 -y || true
run_drush theme:enable dx_portal_theme -y || true
run_drush config:set system.theme default dx_portal_theme -y || true
run_drush cr || true

echo "=== file check ==="
for f in \
  web/themes/custom/dx_portal_theme/css/style.css \
  web/themes/custom/dx_portal_theme/templates/page--front.html.twig \
  web/themes/custom/dx_portal_theme/templates/html.html.twig
do
  [[ -f "$DRUPAL/$f" ]] && echo "OK  $f" || echo "MISS $f"
done
echo "Deploy done. Hard-refresh the site."
