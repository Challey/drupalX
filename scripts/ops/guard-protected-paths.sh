#!/usr/bin/env bash
# R3 guard: force live-protected paths to stay byte-identical to pre-merge master.
set -uo pipefail
cd "$(dirname "$0")/../.." || exit 1
BASE=${1:-pre-merge-20260830}
DROPOUT="${GUARD_DROPOUT:-.worktrees/guard-dropped.txt}"
: > "$DROPOUT"

PROTECTED_PATHS=(
  web/modules/custom/dx_auth
  web/modules/custom/dx_payment
  web/modules/custom/dx_ai_gateway
  web/modules/custom/dx_ecosystem
  web/modules/custom/dx_delivery
  web/modules/custom/dx_migrate
  setup/nginx
  setup/ha
  web/themes/custom/dx_portal_theme/css/login.css
  web/themes/custom/dx_portal_theme/js/login.js
  web/themes/custom/dx_portal_theme/js/portal.js
  web/themes/custom/dx_portal_theme/includes/login_i18n.php
  web/themes/custom/dx_portal_theme/templates/page--user--login.html.twig
  web/themes/custom/dx_portal_theme/templates/page--ai--chat.html.twig
  web/themes/custom/dx_portal_theme/templates/includes/dx-legal-footer.html.twig
  web/themes/custom/dx_portal_theme/css/skins
)

added=$(git diff --diff-filter=A --name-only "$BASE" HEAD -- "${PROTECTED_PATHS[@]}")
for f in $added; do
  git rm -q -f -- "$f" 2>/dev/null || rm -f "$f"
  echo "added-then-dropped: $f" >> "$DROPOUT"
done

git checkout "$BASE" -- "${PROTECTED_PATHS[@]}" 2>/dev/null
git add -A -- "${PROTECTED_PATHS[@]}"

if git diff --cached --quiet; then
  echo "guard: protected paths already identical to $BASE"
  exit 0
fi

git commit -q -m "Guard: pin live-protected paths to the pre-merge master state

Branches carried older duplicates of work already squashed into master
(dx_auth login providers, dx_delivery blueprint entity, live theme login and
chat assets, nginx and HA vhost templates). Keeping them would declare the
dx_blueprint entity type twice and re-introduce superseded classes, so the
protected tree is pinned to $BASE and every dropped file is listed in the
merge disposition report." || { echo "guard: commit failed"; exit 1; }

echo "guard: pinned to $BASE; dropped $(grep -c . "$DROPOUT" 2>/dev/null || echo 0) added files"
