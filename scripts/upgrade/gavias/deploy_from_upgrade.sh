#!/usr/bin/env bash
# Deploy from: $DRUPAL/upgrade/gavias/gavias-deploy-latest/
# Syncs Gavias/Kiamo custom modules + theme into DrupalX web/ layout.
set -euo pipefail

PKG="$(cd "$(dirname "$0")/.." && pwd)"
UPGRADE_DIR="$(cd "$PKG/.." && pwd)"
# upgrade/gavias → upgrade → project root (recommended-project)
DRUPAL="$(cd "$UPGRADE_DIR/../.." && pwd)"

if [[ "$(basename "$UPGRADE_DIR")" != "gavias" ]]; then
  echo "ERROR: package must live under \$DRUPAL/upgrade/gavias/" >&2
  exit 1
fi
if [[ "$(basename "$(cd "$UPGRADE_DIR/.." && pwd)")" != "upgrade" ]]; then
  echo "ERROR: expected path .../upgrade/gavias/" >&2
  exit 1
fi
if [[ ! -d "$DRUPAL/web" ]]; then
  echo "ERROR: expected Drupal recommended-project at $DRUPAL (missing web/)" >&2
  exit 1
fi

MODULES=(
  gva_blockbuilder
  gavias_kiamo_custom
  gavias_sliderlayer
  gavias_view
  gaviasthemer
  features_kiamo
)
THEME=gavias_kiamo

BK="$UPGRADE_DIR/backups/pre-deploy-$(date +%Y%m%d_%H%M%S)"
mkdir -p "$BK"
for m in "${MODULES[@]}"; do
  [[ -d "$DRUPAL/web/modules/custom/$m" ]] && rsync -a "$DRUPAL/web/modules/custom/$m/" "$BK/modules/$m/" || true
done
[[ -d "$DRUPAL/web/themes/custom/$THEME" ]] && rsync -a "$DRUPAL/web/themes/custom/$THEME/" "$BK/themes/$THEME/" || true
echo "Backup: $BK"

echo "=== rsync Gavias modules → $DRUPAL/web/modules/custom ==="
for m in "${MODULES[@]}"; do
  SRC="$PKG/web/modules/custom/$m"
  if [[ -d "$SRC" ]]; then
    mkdir -p "$DRUPAL/web/modules/custom/$m"
    rsync -av --delete --no-owner --no-group --chmod=Du=rwx,Dgo=rx,Fu=rw,Fgo=r \
      "$SRC/" "$DRUPAL/web/modules/custom/$m/"
  else
    echo "WARN: missing in package: $m" >&2
  fi
done

echo "=== rsync Gavias theme → $DRUPAL/web/themes/custom ==="
if [[ -d "$PKG/web/themes/custom/$THEME" ]]; then
  mkdir -p "$DRUPAL/web/themes/custom/$THEME"
  rsync -av --delete --no-owner --no-group --chmod=Du=rwx,Dgo=rx,Fu=rw,Fgo=r \
    "$PKG/web/themes/custom/$THEME/" "$DRUPAL/web/themes/custom/$THEME/"
else
  echo "WARN: theme $THEME missing in package" >&2
fi

chmod -R a+rX \
  "$DRUPAL/web/modules/custom/gva_blockbuilder" \
  "$DRUPAL/web/modules/custom/gavias_kiamo_custom" \
  "$DRUPAL/web/modules/custom/gavias_sliderlayer" \
  "$DRUPAL/web/modules/custom/gavias_view" \
  "$DRUPAL/web/modules/custom/gaviasthemer" \
  "$DRUPAL/web/modules/custom/features_kiamo" \
  "$DRUPAL/web/themes/custom/$THEME" 2>/dev/null || true

if id www >/dev/null 2>&1; then
  chown -R www:www \
    "$DRUPAL/web/modules/custom/gva_blockbuilder" \
    "$DRUPAL/web/modules/custom/gavias_kiamo_custom" \
    "$DRUPAL/web/modules/custom/gavias_sliderlayer" \
    "$DRUPAL/web/modules/custom/gavias_view" \
    "$DRUPAL/web/modules/custom/gaviasthemer" \
    "$DRUPAL/web/modules/custom/features_kiamo" \
    "$DRUPAL/web/themes/custom/$THEME" 2>/dev/null || true
fi

# Clear compiled Twig / PHP storage
rm -rf "$DRUPAL/web/sites/default/files/php/twig/"* 2>/dev/null || true
rm -rf "$DRUPAL"/web/sites/*/files/php/twig/* 2>/dev/null || true
rm -rf "$DRUPAL/web/sites/default/files/php/"* 2>/dev/null || true

run_drush() {
  local -a cmd=("$@")
  if [[ -x "$DRUPAL/vendor/bin/drush.php" ]]; then
    (cd "$DRUPAL" && php -d memory_limit=512M "$DRUPAL/vendor/bin/drush.php" "${cmd[@]}") && return 0
  fi
  if [[ -x "$DRUPAL/vendor/bin/drush" ]]; then
    (cd "$DRUPAL" && php -d memory_limit=512M "$DRUPAL/vendor/bin/drush" "${cmd[@]}") && return 0
  fi
  return 1
}

echo "=== drush cr ==="
run_drush cr || echo "NOTE: cache rebuild skipped/failed"

echo "=== quick module file check ==="
MISSING=0
for f in \
  web/modules/custom/gva_blockbuilder/gavias_blockbuilder.module \
  web/modules/custom/gavias_kiamo_custom/gavias_hook_themer.module \
  web/modules/custom/gavias_sliderlayer/gavias_sliderlayer.module \
  web/modules/custom/gavias_view/gavias_view.module \
  web/modules/custom/gaviasthemer/gaviasthemer.module \
  web/modules/custom/gva_blockbuilder/gva_render_shortcode/gva_render_shortcode.module
do
  if [[ -f "$DRUPAL/$f" ]]; then
    echo "OK  $f"
  else
    echo "MISS $f" >&2
    MISSING=1
  fi
done

if [[ "$MISSING" -eq 1 ]]; then
  echo "ERROR: some module files still missing after deploy" >&2
  exit 1
fi

echo "Deploy done for Gavias/Kiamo at $DRUPAL"
echo "Verify: cd $DRUPAL && vendor/bin/drush status"
