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
            ent_drive ent_fashion ent_apple ent_innovate ent_trust ent_warm \
            slate harbor ember midnight minimal; do
  [[ -f "${THEME}/css/skins/${skin}.css" ]] || fail "skin CSS missing: ${skin}"
  grep -q "skin_${skin}" "${THEME}/dx_portal_theme.libraries.yml" || fail "library skin_${skin} missing"
done
ok "gov + enterprise + apple + classic skin packs registered"

grep -q 'gov_steady:' "${MOD}/data/catalog.yml" || fail "gov_steady missing from catalog"
grep -q 'ent_apple:' "${MOD}/data/catalog.yml" || fail "ent_apple missing from catalog"
grep -q 'ent_innovate:' "${MOD}/data/catalog.yml" || fail "ent_innovate missing from catalog"
grep -q 'dx-nav-toggle' "${THEME}/templates/page--front.html.twig" || fail "mobile nav toggle missing on front"
grep -q 'dxPortalNav' "${THEME}/js/portal.js" || fail "dxPortalNav behavior missing"
grep -q 'max-width: 380px' "${THEME}/css/skins/ent_apple.css" || fail "ent_apple small-phone breakpoint missing"
grep -q 'persona:' "${MOD}/data/catalog.yml" || fail "persona field missing"
grep -q 'byFamily' "${MOD}/src/Service/ThemeCatalog.php" || fail "byFamily() missing"
grep -q 'ThemeGalleryTrait' "${MOD}/src/Form/ThemeStudioForm.php" || fail "gallery trait not used"
grep -q 'dx_theme.studio' "${MOD}/dx_theme.routing.yml" || fail "studio route missing"
grep -q '/dx/themes' "${MOD}/dx_theme.routing.yml" || fail "partner route missing"
ok "routes · catalog · gallery wired"

# --- R4 (lane L5) · OSS 皮肤 oss_base / oss_flame 关键 CSS 变量静态断言 ---
# OFFLINE / 文件层面即可跑：皮肤文件、库注册、catalog 条目、body class、13 个
# --dx-* 令牌，以及 catalog.yml(config 口径) 的 accent 与 css(skins 口径) 的
# --dx-teal 一致。HTTP 层「应用皮肤后计算样式不丢变量」需站点，见下方 SITE 段。
OSS_VARS="--dx-ink --dx-charcoal --dx-teal --dx-teal-bright --dx-teal-deep \
--dx-cinnabar --dx-cinnabar-deep --dx-paper --dx-paper-2 --dx-muted \
--dx-line --dx-white --dx-radius"
for oss in oss_base oss_flame; do
  skin="${THEME}/css/skins/${oss}.css"
  [[ -f "$skin" ]] || fail "OSS skin CSS missing: ${oss}"
  grep -q "skin_${oss}" "${THEME}/dx_portal_theme.libraries.yml" || fail "library skin_${oss} missing"
  grep -q "${oss}:" "${MOD}/data/catalog.yml" || fail "catalog entry ${oss} missing"
done
ok "oss_base + oss_flame skin packs registered (css + library + catalog)"

# body class hooks: catalog.body_class must be the selector the CSS keys off.
grep -q 'dx-skin--oss-base' "${THEME}/css/skins/oss_base.css" || fail "oss_base body class hook missing"
grep -q 'dx-skin--oss-flame' "${THEME}/css/skins/oss_flame.css" || fail "oss_flame body class hook missing"
ok "oss body-class hooks present"

# The 13 token custom properties every OSS skin must define; a missing one
# silently drops styling once the skin library is attached.
for oss in oss_base oss_flame; do
  skin="${THEME}/css/skins/${oss}.css"
  for v in $OSS_VARS; do
    grep -q -- "${v}:" "$skin" || fail "OSS skin ${oss} missing CSS variable ${v}"
  done
done
ok "oss_base + oss_flame define all 13 key --dx-* variables"

# The two skins share a base but diverge on the accent ramp: cyan vs flame.
grep -qF -- '--dx-teal: #00c2b8' "${THEME}/css/skins/oss_base.css" || fail "oss_base --dx-teal anchor changed (expected #00c2b8)"
grep -qF -- '--dx-teal: #ff6a3d' "${THEME}/css/skins/oss_flame.css" || fail "oss_flame --dx-teal anchor changed (expected #ff6a3d)"
ok "oss_base (cyan #00c2b8) vs oss_flame (orange #ff6a3d) accent ramp distinct"

# config 口径 ↔ css 口径: catalog swatch accent must equal the CSS --dx-teal, so
# applying the skin cannot lose the token. Read-only vendor/symfony/yaml.
if [[ -f "${ROOT}/vendor/autoload.php" ]]; then
  OSS_XCHECK="$(php -r '
    require $argv[1];
    $cat = Symfony\Component\Yaml\Yaml::parseFile($argv[2]);
    $base = $cat["skins"]["oss_base"]["swatches"]["accent"] ?? "";
    $flame = $cat["skins"]["oss_flame"]["swatches"]["accent"] ?? "";
    $cssBase = ""; $cssFlame = "";
    foreach (file($argv[3]) as $l) { if (preg_match("/--dx-teal:\s*(#[0-9a-fA-F]{6})/", $l, $m)) { $cssBase = $m[1]; break; } }
    foreach (file($argv[4]) as $l) { if (preg_match("/--dx-teal:\s*(#[0-9a-fA-F]{6})/", $l, $m)) { $cssFlame = $m[1]; break; } }
    echo ($base === $cssBase && $flame === $cssFlame) ? "match" : "MISMATCH {$base}/{$cssBase} {$flame}/{$cssFlame}";
  ' "${ROOT}/vendor/autoload.php" "${MOD}/data/catalog.yml" "${THEME}/css/skins/oss_base.css" "${THEME}/css/skins/oss_flame.css")"
  [[ "$OSS_XCHECK" == "match" ]] || fail "catalog accent vs css --dx-teal mismatch: ${OSS_XCHECK}"
  ok "catalog swatch accent == css --dx-teal (config↔css 变量对齐)"
fi

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

"$DRUSH" "${URI_ARG[@]}" dx:theme-apply ent_apple >/dev/null 2>&1 || fail "dx:theme-apply ent_apple failed"
"$DRUSH" "${URI_ARG[@]}" dx:theme-status --format=json 2>/dev/null | grep -q '"active_skin": "ent_apple"' \
  || fail "active_skin not ent_apple after apply"
ok "apply ent_apple"

# --- R4 SITE 段 · 应用 OSS 皮肤后 active_skin 落地（需站点：切主题/写库）---
# 仅在上方 bootstrap 成功后执行。HTTP 层「计算样式里 --dx-* 不丢失」的断言同样
# 需站点，维护窗口内跑（见 docs/lanes/L5-platform-auth.md「需要维护窗口执行的命令」）。
"$DRUSH" "${URI_ARG[@]}" dx:theme-apply oss_base >/dev/null 2>&1 || fail "dx:theme-apply oss_base failed"
"$DRUSH" "${URI_ARG[@]}" dx:theme-status --format=json 2>/dev/null | grep -q '"active_skin": "oss_base"' \
  || fail "active_skin not oss_base after apply"
ok "apply oss_base"

"$DRUSH" "${URI_ARG[@]}" dx:theme-apply oss_flame >/dev/null 2>&1 || fail "dx:theme-apply oss_flame failed"
"$DRUSH" "${URI_ARG[@]}" dx:theme-status --format=json 2>/dev/null | grep -q '"active_skin": "oss_flame"' \
  || fail "active_skin not oss_flame after apply"
ok "apply oss_flame"

"$DRUSH" "${URI_ARG[@]}" dx:theme-apply portal >/dev/null 2>&1 || fail "restore portal failed"
ok "restore portal"

code=$("$DRUSH" "${URI_ARG[@]}" php:eval "echo \\Drupal::service('http_kernel')->handle(\\Symfony\\Component\\HttpFoundation\\Request::create('/admin/dx/themes'))->getStatusCode();" 2>/dev/null || echo "000")
[[ "$code" == "200" || "$code" == "403" ]] || fail "/admin/dx/themes expected 200/403 got ${code:-empty}"
ok "studio route responds ($code)"

echo "OK  theme-smoke complete"
echo ok
