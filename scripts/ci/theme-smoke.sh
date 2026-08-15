#!/usr/bin/env bash
# Theme Studio smoke: catalog, gov/enterprise skins, optional live apply.
# Usage: ./scripts/ci/theme-smoke.sh [--uri=http://default]
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
DRUSH="${ROOT}/vendor/bin/drush"
URI_ARG=()
for arg in "$@"; do
  case "$arg" in
    --uri=*) URI_ARG=("$arg") ;;
  esac
done

MOD="${ROOT}/web/modules/custom/dx_theme"
THEME="${ROOT}/web/themes/custom/dx_portal_theme"
fail() { echo "FAIL  $*" >&2; exit 1; }
ok() { echo "OK    $*"; }

echo "==> DrupalX Theme Studio smoke (gov + enterprise)"

[[ -f "${MOD}/dx_theme.info.yml" ]] || fail "dx_theme.info.yml missing"
[[ -f "${MOD}/data/catalog.yml" ]] || fail "catalog.yml missing"
[[ -f "${MOD}/src/Service/ThemeStudio.php" ]] || fail "ThemeStudio.php missing"
[[ -f "${MOD}/src/Form/ThemeStudioForm.php" ]] || fail "ThemeStudioForm.php missing"
[[ -f "${MOD}/src/Form/ThemeGalleryTrait.php" ]] || fail "ThemeGalleryTrait.php missing"
[[ -f "${MOD}/css/studio.css" ]] || fail "studio.css missing"
ok "dx_theme module files present"

grep -q 'families:' "${MOD}/data/catalog.yml" || fail "families block missing"
grep -q 'government:' "${MOD}/data/catalog.yml" || fail "government family missing"
grep -q 'enterprise:' "${MOD}/data/catalog.yml" || fail "enterprise family missing"
ok "catalog families present"

for skin in gov_steady gov_passion gov_resolve gov_open gov_solemn \
            ent_drive ent_fashion ent_innovate ent_trust ent_warm \
            slate harbor ember midnight minimal; do
  [[ -f "${THEME}/css/skins/${skin}.css" ]] || fail "skin CSS missing: ${skin}"
  grep -q "skin_${skin}" "${THEME}/dx_portal_theme.libraries.yml" || fail "library skin_${skin} missing"
done
ok "gov + enterprise + classic skin packs registered"

grep -q 'gov_steady:' "${MOD}/data/catalog.yml" || fail "gov_steady missing from catalog"
grep -q 'ent_innovate:' "${MOD}/data/catalog.yml" || fail "ent_innovate missing from catalog"
grep -q 'persona:' "${MOD}/data/catalog.yml" || fail "persona field missing"
grep -q 'byFamily' "${MOD}/src/Service/ThemeCatalog.php" || fail "byFamily() missing"
grep -q 'ThemeGalleryTrait' "${MOD}/src/Form/ThemeStudioForm.php" || fail "gallery trait not used"
grep -q 'dx_theme.studio' "${MOD}/dx_theme.routing.yml" || fail "studio route missing"
grep -q '/dx/themes' "${MOD}/dx_theme.routing.yml" || fail "partner route missing"
ok "routes · catalog · gallery wired"

if [[ ! -x "$DRUSH" ]]; then
  echo "WARN  drush missing - skipped live checks"
  echo "OK  theme-smoke complete"
  echo ok
  exit 0
fi

if ! "$DRUSH" "${URI_ARG[@]}" status --fields=bootstrap 2>/dev/null | grep -qi 'Successful'; then
  echo "WARN  Drupal not bootstrapped - skipped live theme commands"
  echo "OK  theme-smoke complete (files only)"
  echo ok
  exit 0
fi

"$DRUSH" "${URI_ARG[@]}" pm:enable dx_theme -y >/dev/null 2>&1 || fail "pm:enable dx_theme failed"
ok "dx_theme enabled"

"$DRUSH" "${URI_ARG[@]}" dx:theme-list --format=json 2>/dev/null | grep -q '"id": "gov_steady"' \
  || fail "dx:theme-list missing gov_steady"
"$DRUSH" "${URI_ARG[@]}" dx:theme-list --format=json 2>/dev/null | grep -q '"family": "enterprise"' \
  || fail "dx:theme-list missing enterprise family"
ok "dx:theme-list families"

"$DRUSH" "${URI_ARG[@]}" dx:theme-apply gov_steady >/dev/null 2>&1 || fail "dx:theme-apply gov_steady failed"
"$DRUSH" "${URI_ARG[@]}" dx:theme-status --format=json 2>/dev/null | grep -q '"active_skin": "gov_steady"' \
  || fail "active_skin not gov_steady after apply"
ok "apply gov_steady"

"$DRUSH" "${URI_ARG[@]}" dx:theme-apply ent_innovate >/dev/null 2>&1 || fail "dx:theme-apply ent_innovate failed"
"$DRUSH" "${URI_ARG[@]}" dx:theme-status --format=json 2>/dev/null | grep -q '"active_skin": "ent_innovate"' \
  || fail "active_skin not ent_innovate after apply"
ok "apply ent_innovate"

"$DRUSH" "${URI_ARG[@]}" dx:theme-apply portal >/dev/null 2>&1 || fail "restore portal failed"
ok "restore portal"

code=$("$DRUSH" "${URI_ARG[@]}" php:eval "echo \\Drupal::service('http_kernel')->handle(\\Symfony\\Component\\HttpFoundation\\Request::create('/admin/dx/themes'))->getStatusCode();" 2>/dev/null || echo "000")
[[ "$code" == "200" || "$code" == "403" ]] || fail "/admin/dx/themes expected 200/403 got ${code:-empty}"
ok "studio route responds ($code)"

echo "OK  theme-smoke complete"
echo ok
