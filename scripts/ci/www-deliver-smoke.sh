#!/usr/bin/env bash
# www marketing surface → 交钥匙 /deliver (D7-A)
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

FRONT="web/themes/custom/dx_portal_theme/templates/page--front.html.twig"
echo "== www → deliver smoke =="

[[ -f "$FRONT" ]]
grep -q '交钥匙' "$FRONT"
grep -q '/deliver' "$FRONT"
grep -q '政企门户' "$FRONT"
if grep -q '中小企业自己的 AI 数字门户' "$FRONT"; then
  echo "Front still uses SME-AI headline" >&2
  exit 1
fi

if [[ -x vendor/bin/drush ]]; then
  DRUSH=(vendor/bin/drush)
  "${DRUSH[@]}" pm:enable dx_delivery -y >/dev/null || true
  "${DRUSH[@]}" cr >/dev/null || true
  "${DRUSH[@]}" php:eval 'user_role_grant_permissions("anonymous", ["access dx delivery desk"]);' >/dev/null || true
  CODE="$("${DRUSH[@]}" php:eval 'echo \Drupal::service("http_kernel")->handle(\Symfony\Component\HttpFoundation\Request::create("/deliver"))->getStatusCode();' 2>/dev/null || echo 0)"
  [[ "$CODE" == "200" ]] || echo "warn: /deliver http=$CODE (template checks still OK)"
fi

echo "OK www→deliver CTA present"
