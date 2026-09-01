#!/usr/bin/env bash
# L0 public-tree acceptance. Two segments, on purpose:
#
#   段 1 (offline)  php scripts/lib/l0_publish.php only — no .env, no database,
#                   no drush. This is the part a CI runner without a site can
#                   (and must) execute: plan → gate → export → verify, plus the
#                   "unregistered document must fail the build" negative case.
#   段 2 (online)   the Drupal side: routes /dx/api/docs, the partner vault and
#                   the credential page. Needs a bootstrapped site + MySQL.
#
# Run only 段 1 anywhere:  bash scripts/ci/l0-publish-smoke.sh offline
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
DRUSH=(vendor/bin/drush)
MODE="${1:-all}"
DEST="${TMPDIR:-/tmp}/dx-l0-oe3-$$"
FIXTURE="${TMPDIR:-/tmp}/dx-l0-fixture-$$"

cleanup() { rm -rf "$DEST" "$FIXTURE"; }
trap cleanup EXIT

echo "== OE3/I4 L0 publish + public API docs smoke =="
# Only this script's own bit: the publish-l0-tree wrappers are not ours to touch.
chmod +x "$0" || true

# ── 段 1 · 离线门禁（无 .env、无数据库、不碰 drush）───────────────────────────
echo "-- 1/2 offline gate --"
php scripts/lib/l0_publish.php --gate

# The plan is machine readable so CI can assert on the buckets, not on prose.
php scripts/lib/l0_publish.php --plan --format=json > /tmp/dx-l0-plan.json
python3 - <<'PY'
import json
plan = json.load(open('/tmp/dx-l0-plan.json'))
published = {r['path'] for r in plan['entries'] if r['action'] == 'publish'}
stripped = {r['path']: r['visibility'] for r in plan['entries'] if r['action'] == 'strip'}
assert plan['code'] == 'DX.L0.OK', plan['code']
assert plan['counts']['unregistered'] == 0, plan['unregistered'][:5]
assert len(published) > 20, plan['counts']
assert 'docs/open-ecosystem.md' in published, 'public contract doc must stay public'
assert stripped.get('docs/DEPLOY.md') == 'internal', stripped.get('docs/DEPLOY.md')
# Lane reports quote production detail: never public, whatever else changed.
lane_docs = [p for p in published if p.startswith('docs/lanes/')]
assert lane_docs == [], lane_docs
PY

# Export the tree with the library alone (no DB, no repo writes).
php scripts/lib/l0_publish.php --publish="$DEST"
test -f "$DEST/docs/openapi/dxep-v1.yaml"
test -f "$DEST/docs/api/index.html"
test -f "$DEST/web/modules/custom/dx_auth/dx_auth.info.yml"
test -f "$DEST/web/themes/custom/dx_portal_theme/templates/includes/dx-legal-footer.html.twig"
test -f "$DEST/L0-README.md"
test -f "$DEST/L0-MANIFEST.txt"
test ! -e "$DEST/web/modules/custom/dx_ecosystem/data/partner"
# I1: the private Composer catalog is L2 content; it ships inside the module dir,
# so the visibility map has to take it out of the public tree.
test ! -e "$DEST/web/modules/custom/dx_ecosystem/data/composer"
test ! -e "$DEST/setup/ha"
test ! -e "$DEST/scripts/ops"
test ! -e "$DEST/docs/DEPLOY.md"
test ! -e "$DEST/docs/domain-cutover.md"
test ! -e "$DEST/docs/lanes"
test -f "$DEST/docs/visibility.yml"
grep -q 'swagger-ui' "$DEST/docs/api/index.html"
grep -q '^openapi:' "$DEST/docs/openapi/dxep-v1.yaml"
grep -q '^public docs/open-ecosystem.md$' "$DEST/L0-MANIFEST.txt"
grep -q '^excluded(internal) docs/DEPLOY.md$' "$DEST/L0-MANIFEST.txt"

# Negative case: a fresh document inside a require_explicit directory must stop
# the build with DX.L0.UNREGISTERED (exit 4). Built in /tmp, never in the repo.
mkdir -p "$FIXTURE/docs"
cat > "$FIXTURE/docs/l0-whitelist.yml" <<'YML'
version: 1
include:
  - docs
exclude: []
must_include: []
must_exclude: []
gate:
  enforce: true
  register:
    - '*.md'
  require_explicit:
    - docs
YML
printf 'default: public\npaths:\n  docs: public\n  docs/known.md: public\n' > "$FIXTURE/docs/visibility.yml"
printf '# known\n' > "$FIXTURE/docs/known.md"
printf '# surprise\n' > "$FIXTURE/docs/new-companion.md"
set +e
php scripts/lib/l0_publish.php --root="$FIXTURE" --gate > /tmp/dx-l0-gate.out 2>&1
UNREGISTERED_EXIT=$?
set -e
[[ "$UNREGISTERED_EXIT" == "4" ]]
grep -q 'DX.L0.UNREGISTERED' /tmp/dx-l0-gate.out
grep -q 'docs/new-companion.md' /tmp/dx-l0-gate.out
# `--plan` stays informational: same tree, exit 0, finding still printed.
php scripts/lib/l0_publish.php --root="$FIXTURE" --plan > /dev/null
# `gate.enforce: false` downgrades the same finding to a warning (exit 0) and
# keeps it visible in the log.
sed -i 's/^  enforce: true$/  enforce: false/' "$FIXTURE/docs/l0-whitelist.yml"
set +e
php scripts/lib/l0_publish.php --root="$FIXTURE" --gate > /tmp/dx-l0-lenient.out 2>&1
LENIENT_EXIT=$?
set -e
[[ "$LENIENT_EXIT" == "0" ]]
grep -q 'DX.L0.UNREGISTERED' /tmp/dx-l0-lenient.out
grep -q '不阻断' /tmp/dx-l0-lenient.out
rm -rf "$DEST" "$FIXTURE"

if [[ "$MODE" == "offline" ]]; then
  echo "OK I4 offline L0 gate (段 2 需要站点，已跳过)"
  exit 0
fi

# ── 段 2 · 在线（ Drupal 侧：路由与权限）────────────────────────────────────
echo "-- 2/2 online routes --"
"${DRUSH[@]}" pm:enable dx_ecosystem -y >/dev/null
"${DRUSH[@]}" cr >/dev/null

# The Drush wrapper reports the same plan without touching the database beyond
# config reads; --dry-run must never write an export tree.
"${DRUSH[@]}" dx:ecosystem-publish-l0 --dry-run | tee /tmp/dx-l0-drush.out
grep -q 'Dry run' /tmp/dx-l0-drush.out

CODE="$("${DRUSH[@]}" php:eval 'echo \Drupal::service("http_kernel")->handle(\Symfony\Component\HttpFoundation\Request::create("/dx/api/docs"))->getStatusCode();')"
[[ "$CODE" == "200" ]]

YAML="$("${DRUSH[@]}" php:eval 'echo \Drupal::service("http_kernel")->handle(\Symfony\Component\HttpFoundation\Request::create("/dx/api/openapi.yaml"))->getContent();')"
echo "$YAML" | grep -q '^openapi:'
echo "$YAML" | grep -q '/api/dx/v1/channel/site'

# Protected surface: still anonymous-denied (this is expected, not a bug).
PARTNER="$("${DRUSH[@]}" php:eval 'echo \Drupal::service("http_kernel")->handle(\Symfony\Component\HttpFoundation\Request::create("/dx/ecosystem/partner"))->getStatusCode();')"
[[ "$PARTNER" == "403" || "$PARTNER" == "302" ]]

CRED="$("${DRUSH[@]}" php:eval 'echo \Drupal::service("http_kernel")->handle(\Symfony\Component\HttpFoundation\Request::create("/dx/ecosystem/credentials"))->getStatusCode();')"
[[ "$CRED" == "403" || "$CRED" == "302" ]]

echo "OK OE3/I4 L0 publish offline=gate+export+negative /drush dry-run docs=$CODE partner=$PARTNER credentials=$CRED"
