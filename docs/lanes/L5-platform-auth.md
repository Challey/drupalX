# 线 L5 · 登录与门面回归（roadmap Phase R）

> 分支 `lane/platform-auth` · 基点 `master` `17a97ed`（已含 22 分支集成结果）  
> 所有权：`web/modules/custom/dx_auth/tests/**`、`web/modules/custom/dx_ai_gateway/tests/**`、`web/modules/custom/dx_ai_gateway/src/Commands/AiStatusCommands.php`（新）、`web/modules/custom/dx_ai_gateway/drush.services.yml`（append-only）、`scripts/ci/auth-smoke.sh`（新）、`scripts/ci/theme-smoke.sh`、`docs/auth.md`、`docs/enterprise-login.md`、本文件。  
> 现网约束（roadmap Phase R 抬头 + `DEV_MEMORY.md` §2）：**只加测试与文档，不改行为**。统一登录 `/user/login`、五通道、绑定页 `/dx/auth/bindings`、AI 客服 `/ai/chat`、OSS 皮肤 `oss_base`/`oss_flame`、`personal_registration_enabled=false` 全部保持。任何行为变更先出变更说明并单独批准——本线**零行为变更**（证据见 §5）。

---

## 1. 已完成

本线是「续做」：接手时 worktree 里已有 7 个未提交的 `dx_auth` 测试新文件（上一轮被中断）。本轮先读懂它们、修掉其中 3 个的语法/命名空间 bug，再补齐 R1–R4 的命令、冒烟与文档。**没有丢弃任何一个既有文件**。

### R1 五种登录通道回归用例

覆盖企业ID（统一社会信用代码）/ 邮箱首登自动注册 / 微信扫码 / 手机短信 / Google 五通道，每通道含成功、失败、限流或状态分支。断言一律按 **master 真实类名与方法签名**写：

| 通道 | 现网落点（只读，未改） | 关键签名 | 用例要点 |
|------|------------------------|----------|----------|
| 企业ID | `EnterpriseAccountLinker::loginByEnterprise(string $creditCode, string $password): array` + `EnterpriseIdentityService::validate/normalize/mask/resolve` | GB 32100-2015 校验位 | 合法码未绑定 → `msg=enterprise_not_bound`；错校验位/17/19 字符/空/NULL → `invalid_credit_code`；`I`/`O` 非法字符拒绝；归一化去空格连字符并大写 |
| 邮箱 | `AccountAuthController::login` → `LoginRegisterService::createAccount(string $mail, string $password): array`（服务 `dx_auth.login_register`） | 读 `dx_auth.settings:account_auto_register`（默认 `true`） | 新邮箱 + 密码 ≥ 8 位 → 建号并 `status=1`；已注册 / 弱密码 / 非邮箱 → `['error'=>…]`；纯用户名不建号 |
| 微信 | `WechatAuthService`（服务 `dx_auth.wechat`，**小写 c**）`isEnabled(): bool` / `getAccessToken(): string` | 默认 `wechat_enabled=false` | 关闭态 `getAccessToken()` 触网前返回 `""`；openid 复用；封禁夺号；scene `bind_uid` 绑定 |
| 短信 | `SmsAuthService::sendCode(string $mobile, string $ip='0.0.0.0'): bool\|string`（服务 `dx_auth.sms`） | 默认 `sms_enabled=false` | 关闭 → `sms_disabled`（触网前）；`invalid_mobile`；`flood`（IP 10/h、号码 5/h）；OTP HMAC + 5 分钟 TTL + 5 次尝试 |
| Google | `GoogleAuthService::isAvailable(Request): bool` / `isMainlandChina(Request): bool`（服务 `dx_auth.google`） | 默认 `google_enabled=false` + 地理闸门 | 无地理头按大陆处理 → 入口隐藏；`sub` + 已验证邮箱登录/建号；同邮箱归并 |

**关键语义澄清**（pure-assertions 已钉死）：邮箱首登自动注册由 `dx_auth.settings:account_auto_register` 控制，与 `dx_ecosystem.settings:personal_registration_enabled=false`（O6-A/O6-B 应用商店个人租户开关，保持关闭）**完全无关**；`personal_registration_enabled` 不是 `dx_auth` 的键。历史上分支自带测试曾引用被丢弃的 `WeChatAuthService`（大写 C），本线一律按 master 的 `WechatAuthService` 重写。

**续做时修掉的 3 个 bug**（均为测试文件自身缺陷，未触碰实现）：
- `AuthChannelKernelTest.php`：① `Drupal\Core\KernelTests\KernelTestBase` → 真实命名空间 `Drupal\KernelTests\KernelTestBase`；② `resetstaticCache()` → `ConfigFactory::clearStaticCache()`；③ 第 86 行 `assertSame(…->id()), 'msg';` 括号错位导致 `php -l` fatal，修为 `assertSame(…->id(), 'msg')`；④ 11 处 `$this->database()` → `\Drupal::database()`（`KernelTestBase` 无 `database()` helper）。
- `BindingsKernelTest.php`：命名空间同上 + 7 处 `$this->database()` → `\Drupal::database()`。
- `AccountAuthControllerLoginTest.php`：`Drupal\Core\Access\Csrf\CsrfTokenGenerator` → 真实 `Drupal\Core\Access\CsrfTokenGenerator`。

**产出冒烟** `scripts/ci/auth-smoke.sh`（新，167 行），按 R1「把可离线断言段与需站点段拆开」：
- `offline`（默认，CI 安全，永不 bootstrap/连库/触网）：跑两个 pure-assertions harness + 遍历 `php -l` + 静态口径（10 条路由、`account_auto_register:true`、四张 schema 表、`dx:ai-readiness` 存在且 `dx:ai-status` 仍归 `AiCommands`）。`bash scripts/ci/auth-smoke.sh offline` 实测 EXIT=0，输出见 §2。
- `site`（**仅维护窗口**，真 drush + 生产 MySQL）：`pm:enable dx_auth` + `cr`，五通道 `php:eval` 探测（企业ID `enterprise_not_bound` / 邮箱 `created` / 微信 `off|empty` / 短信 `sms_disabled` / Google `mainland|hidden`）、`account_login` 恒答 HTTP 200、`bindings/status` 匿名 403/200、`dx:ai-readiness --format=json` 三元组、回归 `dx:ai-status` 仍出 `ready_count`。site 段引用的服务 id 与返回值字面量已逐一对照现网源码核实（`sms_disabled`/`enterprise_not_bound`/`createAccount` 返回 `['account'=>…]`），但**本线开发副本内绝不运行**。

### R2 `/dx/auth/bindings` 边界用例

Unit + Kernel 两级入库（均在允许路径 `dx_auth/tests/**`）：
- **Unit** `SocialAccountLinkerBindingTest.php`：`SocialAccountLinker` 的 bind-or-merge 纯逻辑——重复绑定幂等、同标识冲突归并（`mergeUsers`）、uid 1 不可被吞并、双手机号冲突拒绝、社交身份无解绑路径。
- **Kernel** `BindingsKernelTest.php`（440 行，需真实 DB）：匿名访问 dataProvider（`bindings` / `bindings_status`）、CSRF 双闸门（`X-CSRF-Token` + `CsrfRequestHeaderAccessCheck::TOKEN_KEY`）、控制器守卫（未登录 → 「请先登录」）、重复绑定、归并、uid 1 保护、双手机号冲突、企业ID 绑定/解绑、唯一键约束、脱敏渲染数组。
- pure-assertions 里另有 schema 唯一键断言（四张身份表各自 unique 标识列 + `uid` 非唯一索引 → 一账号可持多身份），这是「重复绑定」与「冲突归并」的底层锚点。

### R3 `drush dx:ai-readiness` 就绪报表（模型 / 密钥 / 配额三元组）

**命名决策（刻意偏离任务卡字面）**：任务卡写「R3 `drush dx:ai-status`」，但 `dx:ai-status` **已存在**于现网 `AiCommands.php`（输出 `default_provider`/`ready_count`/`providers`/`hint`），且 **L1 线 `delivery-ops-smoke.sh` 第 34–35 行依赖其 `ready_count`**。Drush 两个 `drush.command` 服务用同一 `@command` 名会冲突；而 `AiCommands.php` 属禁改文件。故新报表命令取名 **`dx:ai-readiness`**，`dx:ai-status` 保持 byte-for-byte 不动（`git diff` 空）。此决策写进代码文档块、`drush.services.yml` 注释、`docs/auth.md` 与本文件。

- 新建 `web/modules/custom/dx_ai_gateway/src/Commands/AiStatusCommands.php`（199 行，`class AiStatusCommands extends DrushCommands`），构造注入 `AiGateway`（`@dx_ai_gateway.gateway`）+ `UsageTracker`（`@dx_ai_gateway.usage_tracker`），**只读**现有配置，参考 `AiCommands.php` 做法，不改它。
- `readiness(array $options = ['format' => 'table']): void`：`--format=json` 走 `json_encode(…, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)`（对齐 `ThemeCommands` 约定，非 PropertyList）；table 走 `$this->io()->table()`。
- 纯静态整形器 `buildReport(array $rows, array $quota): array`（无 Drupal / 无容器 / 无 I/O，离线可测）：输出 `default_provider`、`ready`、`ready_count`、`provider_count`、`models{id:model}`、`keys{id:{configured,source}}`、`quota{period,quota,tokens_used,remaining,calls,ok_calls}`、`providers[list]`、`missing[list]`、`hint`。**三元组 = models（模型）/ keys（密钥）/ quota（配额）恒在**。
- **无密钥环境**：`ready=false`，`missing` 列全部 provider，`hint` 明确提示 `missing API key(s): … — set DX_AI_{PROVIDER}_KEY or configure at /admin/dx/ai`；JSON 仍可解析（round-trip）。`ready` 语义 = 默认 provider 有可用密钥 **且** 配额有余（`remaining>0`）；配额耗尽 → `hint: monthly quota exhausted (remaining=0)`。
- **密钥永不外泄**：`buildReport` 只白名单 `configured`/`source`（`site|environment|none`），即使 row 混入 `api_key` 也不进输出（pure-assertions 有专门断言）。
- `drush.services.yml` **append-only**：追加 `dx_ai_gateway.status_commands`（distinct service id + distinct command name），原 `dx_ai_gateway.commands` 一字未动。YAML 有效，两个 `drush.command` tag。
- 测试：`dx_ai_gateway/tests/pure-assertions.php`（283 行，**67 断言 0 失败**，用 `Symfony\Component\Yaml` 只读钉住真实 config：`default_provider=deepseek`、`monthly_quota=100000`、四 provider 及 model）+ `tests/src/Unit/AiStatusCommandsTest.php`（230 行 phpunit Unit）。
- **生效需 `drush cr`**（新 drush 服务需重建容器才发现），见 §3。

### R4 OSS 皮肤变量清单 + `theme-smoke.sh` 断言

两套皮肤 `oss_base`（开源底座·青）/ `oss_flame`（开源底座·焰）同底、仅 accent ramp 分歧。关键 CSS 变量清单（`web/themes/custom/dx_portal_theme/css/skins/{oss_base,oss_flame}.css`，13 个 `--dx-*` 令牌）：

| 变量 | `oss_base`（青） | `oss_flame`（焰） | 说明 |
|------|------------------|-------------------|------|
| `--dx-ink` | `#0b1220` | `#0b1220` | 主墨蓝（同底） |
| `--dx-charcoal` | `#141c2b` | `#141c2b` | 次级深底 |
| `--dx-teal` | **`#00c2b8`** | **`#ff6a3d`** | **强调主色（分歧点）** |
| `--dx-teal-bright` | **`#3ee6dc`** | **`#ff8a5c`** | 强调亮部（分歧） |
| `--dx-teal-deep` | **`#089991`** | **`#e04e28`** | 强调暗部（分歧） |
| `--dx-cinnabar` | `#e23c2f` | `#e23c2f` | 朱砂点缀 |
| `--dx-cinnabar-deep` | `#c42d22` | `#c42d22` | 朱砂暗部 |
| `--dx-paper` | `#f4f6fa` | `#f4f6fa` | 纸面底 |
| `--dx-paper-2` | `#e6ebf3` | `#e6ebf3` | 纸面次级 |
| `--dx-muted` | `#5c6678` | `#5c6678` | 弱化文字 |
| `--dx-line` | `rgba(11,18,32,0.1)` | `rgba(11,18,32,0.1)` | 分隔线 |
| `--dx-white` | `#fff` | `#fff` | 纯白 |
| `--dx-radius` | `2px` | `2px` | 圆角 |

配置口径（`dx_theme/data/catalog.yml` `skins:` 段）：`oss_base` → `library: dx_portal_theme/skin_oss_base`、`body_class: dx-skin--oss-base`、`swatches.accent: #00c2b8`；`oss_flame` → `skin_oss_flame`、`dx-skin--oss-flame`、`accent: #ff6a3d`。

`scripts/ci/theme-smoke.sh`（**append**，+65 行，未删既有断言）新增两段，按 R4「文件层即可跑 / HTTP 层需站点，分开标注」：
- **OFFLINE 段**（文件层，无 drush/DB）：两套皮肤 CSS 存在 + `skin_${oss}` 在 `libraries.yml` + `${oss}:` 在 `catalog.yml`；body-class hook（`dx-skin--oss-base`/`dx-skin--oss-flame`）；13 个 `--dx-*` 变量逐个 `grep`；accent 分歧锚点（`--dx-teal: #00c2b8` vs `#ff6a3d`）；**config↔css 交叉断言**——用 `php`+`vendor/symfony/yaml`（只读）读 `catalog.swatches.accent` 对比 CSS `--dx-teal` 正则提取值，确保「应用皮肤后关键变量不丢失」。实测输出见 §2（全 OK，EXIT=0）。
- **SITE 段**（需站点，插在 `ent_apple` apply 之后、restore portal 之前）：`dx:theme-apply oss_base`/`oss_flame` + `dx:theme-status --format=json | grep '"active_skin": "oss_base"'`。因现网 `theme-smoke.sh` 第 115 行 `drush status` 会连生产 MySQL，**本线只跑 OFFLINE 段**（`head -n 106 | bash`），SITE 段留维护窗口。

---

## 2. 验证命令与实际输出

本线在开发副本内**未**跑任何 drush / 完整 `*-smoke.sh`（脚本会写生产 MySQL）。以下为本机实际执行结果。

```console
$ php web/modules/custom/dx_auth/tests/pure-assertions.php | tail -1
OK: 450 assertion(s), 0 failure(s)

$ php web/modules/custom/dx_ai_gateway/tests/pure-assertions.php | tail -1
OK: 67 assertion(s), 0 failure(s)

$ php scripts/ci/merge-integrity-check.php | tail -1
OK: 146 file(s) scanned, 0 duplicate(s)

$ bash scripts/ci/auth-smoke.sh offline
==> auth-smoke OFFLINE (no DB / no drush / no network)
OK    dx_auth pure-assertions · OK: 450 assertion(s), 0 failure(s)
OK    dx_ai_gateway pure-assertions · OK: 67 assertion(s), 0 failure(s)
OK    php -l clean across 14 test/command file(s)
OK    R1/R2 routes present (5 channels + bindings) in dx_auth.routing.yml
OK    R1 config口径: account_auto_register ships true (email auto-register on)
OK    R1 schema口径: four identity tables declared in dx_auth.install
OK    R3 command口径: dx:ai-readiness new · dx:ai-status untouched
OK  auth-smoke offline complete
ok
# EXIT=0

$ head -n 106 scripts/ci/theme-smoke.sh | bash      # 只跑 R4 OFFLINE 段，避开第 115 行 drush
==> DrupalX Theme Studio smoke (gov + enterprise)
OK    dx_theme module files present
OK    catalog families present
OK    gov + enterprise + apple + classic skin packs registered
OK    routes · catalog · gallery wired
OK    oss_base + oss_flame skin packs registered (css + library + catalog)
OK    oss body-class hooks present
OK    oss_base + oss_flame define all 13 key --dx-* variables
OK    oss_base (cyan #00c2b8) vs oss_flame (orange #ff6a3d) accent ramp distinct
OK    catalog swatch accent == css --dx-teal (config↔css 变量对齐)
# EXIT=0

$ bash -n scripts/ci/auth-smoke.sh && bash -n scripts/ci/theme-smoke.sh && echo "bash -n ok"
bash -n ok

$ find web/modules/custom/dx_auth/tests web/modules/custom/dx_ai_gateway/tests \
       web/modules/custom/dx_ai_gateway/src/Commands/AiStatusCommands.php -name '*.php' \
    -exec php -l {} \; | grep -c "No syntax errors detected"
14
```

两个 pure-assertions 都是**无 DB / 无 drush / 无站点 / 无 PHPUnit** 的纯断言 harness（`ReflectionClass::newInstanceWithoutConstructor()` + 手写 stub + 只读 `vendor/symfony/yaml`），覆盖：

| harness | 断言数 | 覆盖 |
|---------|--------|------|
| `dx_auth/tests/pure-assertions.php` | 450 | R1 五通道服务纯逻辑（GB 32100 校验位、归一化、mask、`account_auto_register` 与 `personal_registration_enabled` 语义分离、短信/微信/Google 关闭态降级）+ R2 归并/uid 1 保护/双手机号冲突 + 四张身份表 schema 唯一键 + 路由/drush 命令口径 |
| `dx_ai_gateway/tests/pure-assertions.php` | 67 | R3 `buildReport` 整形器（命令表面、无密钥/部分密钥/配额闸门、JSON round-trip、三元组恒在、secret 白名单、边界形状）+ 只读钉住真实 config（`default_provider=deepseek`、`monthly_quota=100000`、四 provider） |

phpunit Unit / Kernel 测试**本机无 runner**（`composer.json` 无 `require-dev`），等 L6（Q4）harness 落地即可跑；Kernel 级需真实 DB，命令见 §3。

---

## 3. 需要维护窗口执行的命令

代码上线后（生产 docroot `/home/wwwroot/drupalX`）按序执行。**这些命令连生产 MySQL / bootstrap Drupal，本线开发副本内一律不跑。**

```bash
cd /home/wwwroot/drupalX

# 1) 让 append 的 drush 服务（dx:ai-readiness）被发现——必须 cr
vendor/bin/drush cr

# 2) R3 就绪报表（三元组），无密钥环境应可解析且提示缺项
vendor/bin/drush dx:ai-readiness                 # table
vendor/bin/drush dx:ai-readiness --format=json   # 解析 models/keys/quota + hint
# 回归：既有 dx:ai-status 契约未受影响（L1 依赖 ready_count）
vendor/bin/drush dx:ai-status | grep ready_count

# 3) R1/R2 冒烟 site 段（真 drush + 生产 MySQL；会 pm:enable + cr）
bash scripts/ci/auth-smoke.sh site

# 4) R4 冒烟 site 段（含 dx:theme-apply oss_base/oss_flame + theme-status）
./scripts/ci/theme-smoke.sh
```

phpunit（待 L6 Q4 引入 `phpunit` 开发依赖 + Kernel 测试目录约定后）：

```bash
cd /home/wwwroot/drupalX
# Unit（无需 DB）
vendor/bin/phpunit web/modules/custom/dx_auth/tests/src/Unit
vendor/bin/phpunit web/modules/custom/dx_ai_gateway/tests/src/Unit
# Kernel（需测试 DB / SQLite 内存库，按 Q4 约定配置 SIMPLETEST_DB）
vendor/bin/phpunit web/modules/custom/dx_auth/tests/src/Kernel
```

Kernel 测试需要的模块：`dx_auth` 已在 `tests/src/Kernel/*KernelTest.php` 的 `$modules` 声明；跑前确保测试库可写、`core.extension` 无 ghost 模块阻塞（见 `docs/enterprise-login.md` §Unfinished）。

---

## 4. 跨线请求

1. **L6（Phase Q · 质量与 CI）**  
   - **Q4（最高优先）**：引入 `phpunit` 开发依赖 + Drupal Kernel 测试目录约定。本线 8 个 `tests/src/Unit/*Test.php` 与 2 个 `tests/src/Kernel/*Test.php` 已按 `Drupal\Tests\UnitTestCase` / `Drupal\KernelTests\KernelTestBase` 规范写好，只等 runner。验收「`web/modules/custom/*/tests/src/Unit` 可执行」时请把 `dx_auth` 与 `dx_ai_gateway` 一并纳入。  
   - **Q1/Q3**：把 `bash scripts/ci/auth-smoke.sh offline`（默认离线、CI 安全）与 `head -n 106 scripts/ci/theme-smoke.sh | bash` 的离线段纳入 `run-all.sh` 必跑项；两个 `tests/pure-assertions.php` 无依赖，可放在 `composer install` 之后、任何站点安装之前。`site` 段与完整 `theme-smoke.sh` 连生产 MySQL，**不要**进无人值守 CI。  
   - **Q2**：`merge-integrity-check.php` 本线跑为 `OK: 146 file(s), 0 duplicate(s)`（新增 `dx:ai-readiness` 命令与 `dx_ai_gateway.status_commands` 服务未引入任何 id/路由/类名重复）。

2. **L1（Phase F · 交付运营化）**：`delivery-ops-smoke.sh` 依赖 `dx:ai-status` 的 `ready_count`。本线**未触碰** `AiCommands.php`（`dx:ai-status` byte-for-byte 不变），新报表另取名 `dx:ai-readiness`，不会与你的断言冲突。若你后续想消费三元组，可改用 `dx:ai-readiness --format=json`。

3. **集成线 / 文档线（`DEV_MEMORY.md`、`docs/README.md` 所有）**  
   - `DEV_MEMORY.md` §7 冒烟清单补 `auth-smoke.sh`（offline 段可无人值守）与两个 `tests/pure-assertions.php`。  
   - `docs/README.md` 文档索引挂上本文件 `docs/lanes/L5-platform-auth.md`。  
   - `docs/enterprise-login.md` 本线已订正类名 `WeChatAuthService`→`WechatAuthService` 并补全 `dx_auth_google` 表与 `GoogleAuthService`/`SocialAccountLinker`/`AccountAuthController`/`BindingsController`/`LoginRegisterService` 条目——若文档线有统一校订，请以此为准。

4. **主题 / 门面线**：R4 的 config↔css 交叉断言依赖 `catalog.yml` 的 `swatches.accent` 与 `css/skins/oss_*.css` 的 `--dx-teal` 保持一致。若日后调整 OSS 皮肤强调色，请同步改两处，否则 `theme-smoke.sh` OFFLINE 段会失败（这是期望行为）。

---

## 5. 风险与回退

### 现网行为零变更（证据）

`git diff master --stat`——本线对 **tracked 文件**的全部改动：

```console
$ git diff master --stat
 docs/auth.md                                       | 15 +++++
 docs/enterprise-login.md                           | 13 ++++-
 scripts/ci/theme-smoke.sh                          | 65 ++++++++++++++++++++++
 .../custom/dx_ai_gateway/drush.services.yml        | 12 ++++
 4 files changed, 102 insertions(+), 3 deletions(-)
```

- 改动只落在 **2 个文档（`.md`）+ 1 个 CI 脚本（`.sh`，纯追加断言）+ 1 个 `drush.services.yml`（append-only 注册新命令）**。3 处 deletion 全在 `docs/enterprise-login.md`，是类名订正文本（`WeChatAuthService`→`WechatAuthService` 等），非代码。
- **零** runtime `PHP` / `twig` / `css` / `js` / `routing.yml` / `services.yml` / `libraries.yml` / `.theme` 被改：

```console
$ git diff master --name-only | grep -E '\.(php|twig|css|js|theme)$|routing\.yml$|\.libraries\.yml$|services\.yml$' | grep -v drush.services.yml
（空）
```

- `AiCommands.php`（`dx:ai-status`）零改动：`git diff master --stat -- …/AiCommands.php` 为空。
- master 已跟踪的 3 个 `dx_auth` Unit 测试（`EnterpriseAccountLinkerTest`/`EnterpriseIdentityChecksumTest`/`LoginRegisterServiceTest`）本线**未改**（不在 diff 内）。
- 其余全是**新增文件**（测试 / 命令 / 冒烟 / 文档），对现网运行路径无影响：新 drush 命令 `dx:ai-readiness` 只读；测试文件不被 Drupal 运行时加载；`theme-smoke.sh`/`auth-smoke.sh` 是 CI 脚本。

### 风险表

| 风险 | 影响 | 处置 |
|------|------|------|
| 忘记 `drush cr` | `dx:ai-readiness` 未被发现（命令不存在） | `auth-smoke.sh site` 第一步即 `cr`；`dx:ai-status` 不受影响仍可用 |
| `dx:ai-readiness` 与 `dx:ai-status` 混淆 | 运维找错命令 | 代码文档块 + `drush.services.yml` 注释 + `docs/auth.md` + 本文件均写明命名决策；两者输出契约都在 pure-assertions/site 段回归 |
| site 段企业ID 用例 `91110000MA0123456P` 在生产已被绑定 | 期望 `enterprise_not_bound`，实得 `portal_redirect` | 该码是 pure-assertions 已验证的合法校验位测试码；若生产已绑定，改用一个合法但未绑定的码即可（site 段仅维护窗口跑，可现场调整） |
| Kernel 测试本机无 runner | R2 Kernel 级只写不跑 | 已按 `Drupal\KernelTests\KernelTestBase` 规范写，`php -l` 全通过；等 L6 Q4 harness + 维护窗口 DB 跑 |
| OSS 皮肤强调色日后调整 | `theme-smoke.sh` OFFLINE 交叉断言失败 | 期望行为：强制 `catalog.accent` 与 `css --dx-teal` 同步；见 §4.4 |
| 测试引用类名大小写 | 历史 `WeChatAuthService` 幽灵类 | 全库按 master `WechatAuthService` 重写；pure-assertions 另有否定断言钉住不再出现大写 C 变体 |

### 回退（单文件粒度，不动现网数据）

```bash
# 本线未合并时：直接丢弃 lane 分支（现网零影响，因为只加了文件）
git -C /home/wwwroot/drupalX worktree remove .worktrees/lane-platform-auth
git -C /home/wwwroot/drupalX branch -D lane/platform-auth

# 已合并后回退：revert 本线提交即可（全是新增文件 + 4 个非 runtime 文件的追加/订正）
git -C /home/wwwroot/drupalX revert --no-commit <l5-commits>
git -C /home/wwwroot/drupalX commit -m "Revert L5 platform auth regression (Phase R)"
vendor/bin/drush cr   # 撤掉 dx:ai-readiness 服务发现
```

数据侧无需回退：Phase R 不新增表 / 字段 / 配置，`dx:ai-readiness` 只读不写，测试与冒烟不落库（site 段的 `pm:enable dx_auth` + `cr` 是幂等的现网既有状态）。
