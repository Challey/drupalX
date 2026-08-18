#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
DRUSH=(vendor/bin/drush)
DEST="${TMPDIR:-/tmp}/dx-l0-oe3-$$"

echo "== OE3 L0 publish + public API docs smoke =="
chmod +x "$ROOT/scripts/publish-l0-tree.sh" "$ROOT/scripts/publish-l0-tree.php" || true
"$ROOT/scripts/publish-l0-tree.sh" "$DEST"
test -f "$DEST/docs/openapi/dxep-v1.yaml"
test -f "$DEST/docs/api/index.html"
test -f "$DEST/web/modules/custom/dx_auth/dx_auth.info.yml"
test -f "$DEST/web/themes/custom/dx_portal_theme/templates/includes/dx-legal-footer.html.twig"
test ! -e "$DEST/web/modules/custom/dx_ecosystem/data/partner"
test ! -e "$DEST/setup/ha"
test ! -e "$DEST/scripts/ops"
test ! -e "$DEST/docs/DEPLOY.md"
test ! -e "$DEST/docs/domain-cutover.md"
test -f "$DEST/docs/visibility.yml"
grep -q 'swagger-ui' "$DEST/docs/api/index.html"
grep -q 'openapi:' "$DEST/docs/openapi/dxep-v1.yaml"
rm -rf "$DEST"

"${DRUSH[@]}" pm:enable dx_ecosystem -y >/dev/null
"${DRUSH[@]}" cr >/dev/null

CODE="$("${DRUSH[@]}" php:eval 'echo \Drupal::service("http_kernel")->handle(\Symfony\Component\HttpFoundation\Request::create("/dx/api/docs"))->getStatusCode();')"
[[ "$CODE" == "200" ]]

YAML="$("${DRUSH[@]}" php:eval 'echo \Drupal::service("http_kernel")->handle(\Symfony\Component\HttpFoundation\Request::create("/dx/api/openapi.yaml"))->getContent();')"
echo "$YAML" | grep -q '^openapi:'
echo "$YAML" | grep -q '/api/dx/v1/channel/site'

PARTNER="$("${DRUSH[@]}" php:eval 'echo \Drupal::service("http_kernel")->handle(\Symfony\Component\HttpFoundation\Request::create("/dx/ecosystem/partner"))->getStatusCode();')"
[[ "$PARTNER" == "403" || "$PARTNER" == "302" ]]

CRED="$("${DRUSH[@]}" php:eval 'echo \Drupal::service("http_kernel")->handle(\Symfony\Component\HttpFoundation\Request::create("/dx/ecosystem/credentials"))->getStatusCode();')"
[[ "$CRED" == "403" || "$CRED" == "302" ]]

echo "OK OE3 L0 publish + /dx/api/docs=$CODE partner=$PARTNER credentials=$CRED"
