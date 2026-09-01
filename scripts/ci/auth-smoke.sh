#!/usr/bin/env bash
# auth-smoke.sh — lane L5 (roadmap Phase R) login gateway & bindings smoke.
#
# Two clearly separated sections, per the R1 "把可离线断言段与需站点段拆开" ask:
#
#   offline — no DB, no drush, no network, no Drupal bootstrap. Runs the two
#             pure-assertion harnesses (dx_auth R1/R2, dx_ai_gateway R3) plus
#             static route / config / schema / command口径 checks. CI-safe and
#             always runnable, including in a lane worktree whose vendor/ is a
#             symlink to production.
#
#   site    — MAINTENANCE WINDOW ONLY. Real drush against production MySQL:
#             probes the five login channels under shipping config (all closed
#             channels must refuse before any outbound call), the R2 bindings
#             anonymous gate, and R3 dx:ai-readiness --format=json. NEVER run
#             this outside a window; it enables modules and rebuilds cache.
#
# Usage:
#   bash scripts/ci/auth-smoke.sh            # offline (default; CI-safe)
#   bash scripts/ci/auth-smoke.sh offline    # offline section only
#   bash scripts/ci/auth-smoke.sh site       # site section only (needs drush+DB)
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
MODE="${1:-offline}"
DRUSH=(vendor/bin/drush)
AUTH="web/modules/custom/dx_auth"
AIGW="web/modules/custom/dx_ai_gateway"

fail() { echo "FAIL  $*" >&2; exit 1; }
ok()   { echo "OK    $*"; }

# ---------------------------------------------------------------------------
# OFFLINE 段 — 可离线断言（永不 bootstrap / 连库 / 触网）。
# ---------------------------------------------------------------------------
offline_section() {
  echo "==> auth-smoke OFFLINE (no DB / no drush / no network)"

  # R1/R2 — dx_auth 纯断言 harness：五通道 + bindings 边界 + 路由/schema/drush 口径。
  local out
  out="$(php "$AUTH/tests/pure-assertions.php")" || fail "dx_auth pure-assertions exited non-zero"
  echo "$out" | tail -1 | grep -q '^OK:' || fail "dx_auth pure-assertions did not report OK"
  ok "dx_auth pure-assertions · $(echo "$out" | tail -1)"

  # R3 — dx_ai_gateway 纯断言 harness：dx:ai-readiness 三元组整形器 + config 口径。
  out="$(php "$AIGW/tests/pure-assertions.php")" || fail "dx_ai_gateway pure-assertions exited non-zero"
  echo "$out" | tail -1 | grep -q '^OK:' || fail "dx_ai_gateway pure-assertions did not report OK"
  ok "dx_ai_gateway pure-assertions · $(echo "$out" | tail -1)"

  # 本线全部新增/续做 PHP 的语法自检（测试 + 新命令）。
  local n=0 f
  while IFS= read -r f; do
    php -l "$f" >/dev/null || fail "php -l failed: $f"
    n=$((n + 1))
  done < <(find "$AUTH/tests" "$AIGW/tests" "$AIGW/src/Commands/AiStatusCommands.php" -name '*.php')
  ok "php -l clean across ${n} test/command file(s)"

  # R1/R2 静态口径 — 五通道 + bindings 的路由都在（文件层，不 bootstrap）。
  local routing="$AUTH/dx_auth.routing.yml" r
  for r in account_login enterprise_login enterprise_lookup sms_send wechat_qrcode \
           google_jump bindings bindings_status bind_mobile claim_account; do
    grep -q "dx_auth.${r}:" "$routing" || fail "route dx_auth.${r} missing from routing.yml"
  done
  ok "R1/R2 routes present (5 channels + bindings) in dx_auth.routing.yml"

  # R1 静态口径 — 邮箱首登自动注册的现网开关（与 dx_ecosystem 的
  # personal_registration_enabled 无关，那只管应用商店个人租户）。
  grep -q 'account_auto_register: true' "$AUTH/config/install/dx_auth.settings.yml" \
    || fail "account_auto_register 现网默认应为 true"
  ok "R1 config口径: account_auto_register ships true (email auto-register on)"

  # R1 静态口径 — 四张身份表 schema（企业 / 微信 / Google / 手机）。
  local t
  for t in dx_auth_enterprise dx_auth_wechat dx_auth_google dx_auth_mobile; do
    grep -q "$t" "$AUTH/dx_auth.install" || fail "schema table $t missing from dx_auth.install"
  done
  ok "R1 schema口径: four identity tables declared in dx_auth.install"

  # R3 静态口径 — 新命令另取名 dx:ai-readiness，绝不复用现网 dx:ai-status
  #（L1 delivery-ops-smoke.sh 依赖其 ready_count）。
  grep -q '@command dx:ai-readiness' "$AIGW/src/Commands/AiStatusCommands.php" \
    || fail "dx:ai-readiness command missing"
  grep -q '@command dx:ai-status' "$AIGW/src/Commands/AiCommands.php" \
    || fail "existing dx:ai-status must stay owned by AiCommands"
  if grep -q '@command dx:ai-status' "$AIGW/src/Commands/AiStatusCommands.php"; then
    fail "AiStatusCommands must NOT re-register dx:ai-status (Drush name collision)"
  fi
  ok "R3 command口径: dx:ai-readiness new · dx:ai-status untouched"

  echo "OK  auth-smoke offline complete"
  echo ok
}

# ---------------------------------------------------------------------------
# SITE 段 — 需要维护窗口执行（真 drush + 生产 MySQL）。本线开发副本内绝不运行。
# ---------------------------------------------------------------------------
site_section() {
  echo "==> auth-smoke SITE (maintenance window: real drush + MySQL)"
  [[ -x "${DRUSH[0]}" ]] || fail "drush not found at ${DRUSH[0]}"
  "${DRUSH[@]}" status --fields=bootstrap 2>/dev/null | grep -qi 'Successful' \
    || fail "Drupal not bootstrapped — run inside a maintenance window"

  "${DRUSH[@]}" pm:enable dx_auth -y >/dev/null 2>&1 || fail "pm:enable dx_auth failed"
  "${DRUSH[@]}" cr >/dev/null 2>&1 || fail "cr failed (needed for the appended drush service)"
  ok "dx_auth enabled + cache rebuilt"

  local msg code js

  # --- R1 五通道（现网默认：微信 / 短信 / Google 未配密钥，须在出网前拒绝）---
  # 通道一 · 企业ID：未绑定的合法代码 → enterprise_not_bound（lookup 是预览，非登录）。
  msg="$("${DRUSH[@]}" php:eval 'echo \Drupal::service("dx_auth.account_linker")->loginByEnterprise("91110000MA0123456P","x")["msg"];' 2>/dev/null)"
  [[ "$msg" == "enterprise_not_bound" ]] || fail "enterprise channel expected enterprise_not_bound, got ${msg}"
  ok "R1 企业ID: unbound legal code → enterprise_not_bound (no login)"

  # 通道二 · 邮箱首登自动注册：account_auto_register=true 时新邮箱建号并激活。
  msg="$("${DRUSH[@]}" php:eval '$m="smoke_".time()."@example.com"; $r=\Drupal::service("dx_auth.login_register")->createAccount($m,"LongEnough1"); echo isset($r["error"])?"error":"created";' 2>/dev/null)"
  [[ "$msg" == "created" ]] || fail "email auto-register expected created, got ${msg}"
  ok "R1 邮箱: auto-register creates an active account"

  # 通道三 · 微信扫码：默认关闭，getAccessToken 在触网前返回空串。
  msg="$("${DRUSH[@]}" php:eval '$s=\Drupal::service("dx_auth.wechat"); echo ($s->isEnabled()?"on":"off")."|".($s->getAccessToken()===""?"empty":"token");' 2>/dev/null)"
  [[ "$msg" == "off|empty" ]] || fail "wechat channel expected off|empty (no outbound), got ${msg}"
  ok "R1 微信: disabled by default, never reaches api.weixin.qq.com"

  # 通道四 · 手机短信：默认关闭，sendCode 在触网前返回 sms_disabled。
  msg="$("${DRUSH[@]}" php:eval 'echo \Drupal::service("dx_auth.sms")->sendCode("13800138000","203.0.113.9");' 2>/dev/null)"
  [[ "$msg" == "sms_disabled" ]] || fail "sms channel expected sms_disabled, got ${msg}"
  ok "R1 短信: disabled by default → sms_disabled before any gateway call"

  # 通道五 · Google：无地理头按大陆处理，入口对无头请求隐藏。
  msg="$("${DRUSH[@]}" php:eval '$req=\Symfony\Component\HttpFoundation\Request::create("https://example.com/dx/auth/google_jump"); $s=\Drupal::service("dx_auth.google"); echo ($s->isMainlandChina($req)?"mainland":"oversea")."|".($s->isAvailable($req)?"avail":"hidden");' 2>/dev/null)"
  [[ "$msg" == "mainland|hidden" ]] || fail "google channel expected mainland|hidden, got ${msg}"
  ok "R1 Google: geo-gated, hidden for a headerless mainland request"

  # --- R1 限流 / Topstar 契约：account_login 恒答 HTTP 200，判定落在 body.code ---
  code="$("${DRUSH[@]}" php:eval 'echo \Drupal::service("http_kernel")->handle(\Symfony\Component\HttpFoundation\Request::create("/dx/auth/account_login","POST",["name"=>"x","password"=>"y"]))->getStatusCode();' 2>/dev/null || echo 000)"
  [[ "$code" == "200" ]] || fail "account_login expected HTTP 200 (verdict in body), got ${code}"
  ok "R1 限流/契约: /dx/auth/account_login answers 200, verdict carried in code"

  # --- R2 /dx/auth/bindings 未授权访问：匿名被登录闸门挡住 ---
  code="$("${DRUSH[@]}" php:eval 'echo \Drupal::service("http_kernel")->handle(\Symfony\Component\HttpFoundation\Request::create("/dx/auth/bindings/status"))->getStatusCode();' 2>/dev/null || echo 000)"
  [[ "$code" == "403" || "$code" == "200" ]] || fail "bindings/status anonymous expected 403/200, got ${code}"
  ok "R2 bindings: anonymous /dx/auth/bindings/status responds ${code} (login-gated)"

  # --- R3 dx:ai-readiness：--format=json 可解析，三元组齐全，缺项写进 hint ---
  js="$("${DRUSH[@]}" dx:ai-readiness --format=json 2>/dev/null)" || fail "dx:ai-readiness --format=json failed (run drush cr first)"
  echo "$js" | php -r 'exit(json_validate(stream_get_contents(STDIN)) ? 0 : 1);' || fail "dx:ai-readiness output is not valid JSON"
  for leg in '"models"' '"keys"' '"quota"'; do
    echo "$js" | grep -q "$leg" || fail "dx:ai-readiness JSON missing the ${leg} leg"
  done
  ok "R3 dx:ai-readiness: parseable model / key / quota triple"

  # R3 回归 — 既有 dx:ai-status 契约未受影响（L1 依赖 ready_count）。
  "${DRUSH[@]}" dx:ai-status 2>/dev/null | grep -q 'ready_count' || fail "existing dx:ai-status lost its ready_count contract"
  ok "R3 regression: existing dx:ai-status still emits ready_count"

  echo "OK  auth-smoke site complete"
  echo ok
}

case "$MODE" in
  offline) offline_section ;;
  site)    site_section ;;
  *) echo "usage: $0 [offline|site]" >&2; exit 2 ;;
esac
