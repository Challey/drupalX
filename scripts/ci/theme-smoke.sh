#!/usr/bin/env bash
# Theme Studio smoke: catalog, skins, module wiring, optional live apply.
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

echo "==> DrupalX Theme Studio smoke"

[[ -f "${MOD}/dx_theme.info.yml" ]] || fail "dx_theme.info.yml missing"
[[ -f "${MOD}/data/catalog.yml" ]] || fail "catalog.yml missing"
[[ -f "${MOD}/src/Service/ThemeStudio.php" ]] || fail "ThemeStudio.php missing"
[[ -f "${MOD}/src/Form/ThemeStudioForm.php" ]] || fail "ThemeStudioForm.php missing"
[[ -f "${MOD}/css/studio.css" ]] || fail "studio.css missing"
ok "dx_theme module files present"

for skin in slate harbor ember midnight minimal; do
  [[ -f "${THEME}/css/skins/${skin}.css" ]] || fail "skin CSS missing: ${skin}"
  grep -q "skin_${skin}" "${THEME}/dx_portal_theme.libraries.yml" || fail "library skin_${skin} missing"
done
ok "five skin packs + libraries registered"

grep -q 'portal:' "${MOD}/data/catalog.yml" || fail "portal skin missing from catalog"
grep -q 'harbor:' "${MOD}/data/catalog.yml" || fail "harbor skin missing from catalog"
grep -q 'dx_theme.studio' "${MOD}/dx_theme.routing.yml" || fail "studio route missing"
grep -q '/dx/themes' "${MOD}/dx_theme.routing.yml" || fail "partner route missing"
grep -q 'dx:theme-list' "${MOD}/src/Commands/ThemeCommands.php" || fail "dx:theme-list missing"
grep -q 'dx:theme-apply' "${MOD}/src/Commands/ThemeCommands.php" || fail "dx:theme-apply missing"
ok "routes · commands wired"

# Soft checks — present when platform WIP / onboarding hub lands.
if grep -q "dx_theme" "${ROOT}/web/modules/custom/dx_platform/src/Service/TenantProvisioner.php" 2>/dev/null; then
  ok "provisioner enables dx_theme"
else
  echo "WARN  TenantProvisioner does not yet enable dx_theme (enable manually: drush en dx_theme)"
fi
if grep -q 'themeStudioRow' "${ROOT}/web/modules/custom/dx_tenant/src/Service/OnboardingHub.php" 2>/dev/null; then
  ok "onboarding hub theme row present"
else
  echo "WARN  OnboardingHub theme row not present yet"
fi

if [[ ! -x "$DRUSH" ]]; then
  echo "WARN  drush missing - skipped live checks"
  echo "OK  theme-smoke complete"
  echo ok
  exit 0
fi

# Live checks only when a Drupal site boots.
if ! "$DRUSH" "${URI_ARG[@]}" status --fields=bootstrap 2>/dev/null | grep -qi 'Successful'; then
  echo "WARN  Drupal not bootstrapped - skipped live theme commands"
  echo "OK  theme-smoke complete (files only)"
  echo ok
  exit 0
fi

"$DRUSH" "${URI_ARG[@]}" pm:enable dx_theme -y >/dev/null 2>&1 || fail "pm:enable dx_theme failed"
ok "dx_theme enabled"

"$DRUSH" "${URI_ARG[@]}" dx:theme-list --format=json 2>/dev/null | grep -q '"id": "portal"' \
  || fail "dx:theme-list missing portal"
ok "dx:theme-list"

"$DRUSH" "${URI_ARG[@]}" dx:theme-apply harbor >/dev/null 2>&1 || fail "dx:theme-apply harbor failed"
"$DRUSH" "${URI_ARG[@]}" dx:theme-status --format=json 2>/dev/null | grep -q '"active_skin": "harbor"' \
  || fail "active_skin not harbor after apply"
ok "apply harbor"

"$DRUSH" "${URI_ARG[@]}" dx:theme-apply portal >/dev/null 2>&1 || fail "restore portal failed"
ok "restore portal"

code=$("$DRUSH" "${URI_ARG[@]}" php:eval "echo \\Drupal::service('http_kernel')->handle(\\Symfony\\Component\\HttpFoundation\\Request::create('/admin/dx/themes'))->getStatusCode();" 2>/dev/null || echo "000")
# 200 or 403 both prove route exists; 404 is failure.
[[ "$code" == "200" || "$code" == "403" ]] || fail "/admin/dx/themes expected 200/403 got ${code:-empty}"
ok "studio route responds ($code)"

echo "OK  theme-smoke complete"
echo ok
