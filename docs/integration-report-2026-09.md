# DrupalX 六线集成与验收报告（2026-09）

可见性：`internal`（含现网实现细节与合并动作，不进 L0 公开树）。

> 汇总并行六线（`M3-A`，文件所有权互斥）在 `master` `17a97ed` 之上的交付，供集成方一次性回收。
> 回滚点：`git tag pre-merge-20260830`（= 2026-08 分支大集成前的 master `2945234`）。
> 本轮六线基点均为 `17a97ed`（已含 22 分支集成结果），**均未 push、未打 tag、未部署、未连生产 MySQL**。
> 编写：L6 文档与 CI 线（`lane/docs-ci`）· 2026-09-02。

---

## 0. 摘要

| 项 | 结果 |
|----|------|
| 参与线 | L1 Phase F · L2 Phase G · L3 Phase H · L4 Phase I · L5 Phase R · L6 Phase Q |
| 提交总数 | 20（L1:2 · L2:3 · L3:6 · L4:3 · L5:3 · L6:3） |
| 净变更（vs `17a97ed`，各线独立统计，未合并去重） | 208 文件 / +28,927 / −661 |
| roadmap 勾选 | Phase F/G/H/I/R 全 20 条勾选（离线/静态证据）；Phase Q 勾 Q1–Q3，**Q4 标未验收**（phpunit 待集成方 composer 安装） |
| 无 DB 门禁 | `bash scripts/ci/run-all.sh --no-db` 在 L6 分支实跑 **exit 0**（详见 §2.6） |
| 需维护窗口执行 | 1 条 `updatedb`（含 2 个 hook）+ config 写入 + 连库冒烟 + SDK 构建（详见 §4） |
| 待集成方执行 | `composer require --dev drupal/core-dev`（先 `--dry-run`）+ 逐线 `--no-ff` 合入（详见 §6） |
| 现网行为变更 | L5 零行为变更（只加测试/文档/命令）；L1/L2/L4 均为增量（新路由/新可选参数/新配置键，缺键回落默认）；L3 默认值等价 1.2.x |

---

## 1. 每条线 commit 列表

基点统一为 `master` `17a97ed`。以下为各 `lane/*` 分支相对基点的提交（`git log --format='%h %s' master..<branch>`）。

### L1 · `lane/delivery-ops`（Phase F 交付运营化）— 22 文件 / +3075 / −42

```
0bff85d Cover Phase F delivery ops with CI smokes and lane notes
f4d6801 Add Phase F delivery operations to dx_delivery
```

### L2 · `lane/migrate-exchange`（Phase G 迁移与交换生产化）— 55 文件 / +7011 / −252

```
79de13e Document lane L2 migrate and exchange deliverables
3797c75 Seal exchange packages with SHA-256 ledgers and add webhook endpoint settings
5cc0d1a Extract L2 field mappings into declarative templates and add review batch operations
```

### L3 · `lane/clients-pack`（Phase H 多端出包 v2）— 75 文件 / +7430 / −334

```
e5e5f7b Record that the field-contract mirrors are CI-only consumers for now
657d18e Document lane L3 Phase H delivery, verification and follow-ups
e3f80aa Gate every packer behind the manifest schema and rehearse packs in temp dirs
45848bc Add component catalogue v2 and a cross-end field contract with offline gates
29e544e Make Android shell 1.3.0 payment whitelist and capabilities manifest-driven
0aff466 Add DX-PACK-MANIFEST schema gate for the three packers
```

### L4 · `lane/ecosystem-l2`（Phase I 真实 L2 仓库对接）— 39 文件 / +6364 / −30

```
6221072 Complete Phase I L2 repository, credential report and L0 CI gate
6ff2ae5 Fix L2 credential gates, provider path parsing and L0 gate downgrades
df6a7cd WIP Phase I: L2 composer repository layer, credential lifecycle and audit report
```

> `df6a7cd` 是被中断的 WIP（305/320 断言）；`6ff2ae5` 修完 15 条失败断言并补齐 I1–I4，`6221072` 收尾至 **359/359**。

### L5 · `lane/platform-auth`（Phase R 登录与门面回归）— 17 文件 / +5047 / −3

```
6bc5afa Add auth/theme smoke scripts, Phase R docs and L5 lane record
66c71ac Add dx:ai-readiness gateway report command and tests
49dd84c Add dx_auth login channel and bindings regression tests
```

### L6 · `lane/docs-ci`（Phase Q 质量与 CI）— 本线

```
（本提交）Add the six-lane integration report, L6 lane record and nightlog
548cc71 Align docs index, roadmap and memory with the six-lane delivery
1a516d3 Add unattended no-DB CI gate and graceful phpunit runner
```

---

## 2. 验证输出摘录

各线在**开发副本内未跑任何 drush / 连库 `*-smoke.sh`**（会写生产 MySQL）。以下为各 lane 文档记录的本机实跑结果（离线/静态），L6 无 DB 门禁段为本报告作者实跑。

### 2.1 L1 · `dx_delivery`（Phase F）

```
php web/modules/custom/dx_delivery/tests/pure-assertions.php
  → OK: 160 assertion(s), 0 failure(s)
php scripts/ci/merge-integrity-check.php
  → OK: 140 file(s) scanned, 0 duplicate(s)
php -l（16 个 .php）→ 全部 No syntax errors detected
bash -n desk/delivery-todos/delivery-ops/delivery-smoke.sh → 全部 ok
```

覆盖：F1 四态映射与 `executed=running+completed`、`aria-current` 唯一；F2 看板筛选归一/逾期/只读降级；F3 批量完成-skip-unknown/幂等不改 `done_at`/SLA/裸日期 UTC 边界；F4 四键八列恒定/计数器自洽/JSON 往返。连库断言写进 4 个 smoke（**未跑**）。

### 2.2 L2 · `dx_migrate` + `dx_channel`（Phase G）

```
纯 PHP 用例 422 条全绿（dx_migrate 含「默认映射不变」回归 181 条）
```

覆盖：G1 声明式模板载入/继承/校验（坏模板非零退出、不静默回落）；G2 `ReviewBatch` 去重-上限-三动作-幂等重放；G3 `ExchangeChecksums` SHA-256 台账（篡改拒收 `DX.EXCHANGE.*`）；G4 Webhook 配置/健康/脱敏。连库断言写进 8 个 smoke（**未跑**）。

### 2.3 L3 · 多端出包（Phase H）— 四个静态冒烟本机实跑绿

```
bash scripts/ci/clients-isomorph-smoke.sh → PASS 1144/1144 check(s)
bash scripts/ci/flutter-shell-smoke.sh    → PASS clients-isomorph[dart] 114/114 · OK flutter-shell smoke
bash scripts/ci/miniprogram-shell-smoke.sh→ PASS catalog 133/133 · mp 19/19 · fixtures 300/300 · OK
bash scripts/ci/packer-smoke.sh           → 41 check(s) passed, 0 failed
   · 白名单 24 主机矩阵与 shell 1.2.x 行为等价（javac 探针 + LegacyWhitelistProbe 对拍）
   · 三端 --list == 门禁 registry；演练只写入 /tmp（仓库 upgrade/ 与生产 staging 未被写入）
```

> L3 的四个脚本已改写为纯静态 + 临时目录（`grep` 证明无 drush/mysql/bootstrap），故本机能跑。
> **需真机/SDK 的构建未执行**（禁联网构建）：APK 编译、`flutter test`/`build`、微信开发者工具导入（详见 §4 D 段与 L3 lane 文档 §4 V1–V8）。

### 2.4 L4 · `dx_ecosystem`（Phase I）

```
php web/modules/custom/dx_ecosystem/tests/pure-assertions.php | tail -1
  → PURE ASSERTIONS dx_ecosystem: 359/359 passed, 0 failed
php scripts/ci/merge-integrity-check.php | tail -1
  → OK: 151 file(s) scanned, 0 duplicate(s)
bash scripts/ci/l0-publish-smoke.sh offline
  → L0 gate DX.L0.OK · 扫描 540 文件 · public 42 / partner 0 / internal 5 / 未登记 0 · OK（退出码 0）
```

覆盖：凭证格式/TTL/脱敏；状态机（吊销不可被 rotate 复活）与门禁分类稳定码；provider 还原与解码/未解码双形态路由；I1 回环离线半段；I3 `CredentialReport` 14 列 + 表头唯一 + 空输入不崩；I4 L0 未登记 exit 4 / `enforce:false` 转 warning 不阻断 / `docs/lanes` 不在 public。连库回环写进 `l2-credential`/`ecosystem`/`l0-publish` 段 2（**未跑**）。

### 2.5 L5 · `dx_auth` + `dx_ai_gateway`（Phase R）

```
php web/modules/custom/dx_auth/tests/pure-assertions.php      → 450 断言全绿
php web/modules/custom/dx_ai_gateway/tests/pure-assertions.php → 67 断言全绿
php scripts/ci/merge-integrity-check.php                       → OK: 146 file(s), 0 duplicate(s)
bash scripts/ci/auth-smoke.sh offline                          → EXIT=0（五通道/绑定/命令口径离线段）
head -n 106 scripts/ci/theme-smoke.sh | bash                   → EXIT=0（R4 OFFLINE 段：13 个 --dx-* 变量、accent ramp、config↔css 对齐）
php -l（14 个测试/命令 .php）→ 全部 No syntax errors detected
git diff master --stat → 仅 2 文档 + 1 CI 脚本(纯追加) + 1 drush.services.yml(append)；零 runtime PHP/twig/css/js/routing 改动
```

> phpunit Unit（8 个）/ Kernel（2 个）测试**本机无 runner**（`composer.json` 原无 `require-dev`），等 L6 Q4 harness + 集成方 composer 安装后可跑；Kernel 需 `SIMPLETEST_DB`。

### 2.6 L6 · 无 DB 门禁段（本报告作者实跑，`lane/docs-ci`）

在 L6 分支（基点 `17a97ed`，其它线代码尚未合入）实跑：

```
$ bash scripts/ci/run-all.sh --no-db
== gate: no-DB / no-site checks (unattended-safe) ==
── [gate] merge integrity check
OK: 135 file(s) scanned, 0 duplicate(s)
   skip [gate] tests/pure-assertions.php — 未发现（待各线集成）
   skip [gate] auth-smoke.sh offline — 脚本不存在（待对应线集成）
   skip [gate] l0-publish-smoke.sh offline — 无 offline 段（待对应线集成）
   skip [gate] L3 manifest schema gate — scripts/x-pack-manifest.sh 不存在（待 L3 集成）
   skip [gate] L3 clients isomorph check — tools/clients/isomorph_check.py 或 python3 不存在（待 L3 集成）
── [gate] unit-tests.sh (unit)
SKIP 未安装 phpunit，跳过（vendor/bin/phpunit 不存在）。
================ summary ================
  gate             pass 4  fail 0  skip 5
total: pass 4  fail 0  skip 5
EXIT=0
```

**门禁发现逻辑已对拍各线真实产物**（`git ls-tree` 只读核对）：合入后本段将自动发现并跑 6 个 `pure-assertions.php`（`dx_delivery`/`dx_migrate`/`dx_channel`/`dx_ecosystem`/`dx_auth`/`dx_ai_gateway`）、`auth-smoke.sh offline`（8 处 offline 命中）、`l0-publish-smoke.sh offline`（6 处命中）、`x-pack-manifest.sh --all`、`isomorph_check.py all`。当前 skip 全因这些文件尚在各自 lane 分支、未合入本线基点。

Q2 负例验证（`merge-integrity-check.php` 接受 `[dir ...]` 参数，用一次性临时目录注入重复 FQCN）：

```
$ php scripts/ci/merge-integrity-check.php .tmp-mic-test   # 两文件同 namespace+class
DUPLICATE class name: drupal\dx_demo\service\collision
FAIL: ... 1 duplicate(s)      EXIT=1（期望 1）
$ php scripts/ci/merge-integrity-check.php                 # 干净树
OK: 135 file(s) scanned, 0 duplicate(s)   EXIT=0
```

---

## 3. 跨线请求处置结果

图例：✅ 已落实 · 🟡 部分落实 · ⏳ 转待办（附原因/归属）。

### 3.1 来自 L4（Phase I）

| # | 请求 | 处置 | 落点 / 原因 |
|---|------|------|-------------|
| L4-1 | `docs/roadmap.md` Phase I 的 I1–I4 勾选 | ✅ | roadmap 四条均 `[x]`（离线 359/359 + `l0-publish-smoke.sh offline` 实跑绿）；连库回环注为待窗口 |
| L4-2 | `DEV_MEMORY.md` §5.3 补 Phase I 新配置键 | ✅ | §5.3 增 `l2_composer_base_url`/`l2_repository_root`/`l2_composer_driver`/`l2_token_header`/`l2_signing_key`/`l2_download_ttl`/`l2_dist_mode` + 缺键回落说明 |
| L4-3 | `DEV_MEMORY.md` §6 补 `/dx/ecosystem/l2/*` 与 `/admin/dx/ecosystem/credentials` | ✅ | §6 路由速查两行已加 |
| L4-4 | `DEV_MEMORY.md` §5.4 补 l2/report Drush 命令 | ✅ | §5.4 增 `dx:ecosystem-l2-plan`/`-l2-repo`/`-l2-auth-check`/`-credential-report` + 状态机收紧说明 |
| L4-5 | `DEV_MEMORY.md` §7 补 `pure-assertions.php` 与 `l0-publish-smoke.sh offline` | ✅ | §7.1 无 DB 门禁段列全 6 个 harness + offline 段 |
| L4-6 | CI 门禁纳入 `merge-integrity-check.php`、各 `pure-assertions.php`、`l0-publish-smoke.sh offline` | ✅ | `run-all.sh` gate 段全部纳入（发现式，合入即跑） |
| L4-7 | `docs/lanes/` 与 `docs/README.md` 索引挂载 | ✅ | README 新增「F. 并行六线」段；`docs/visibility.yml` 登记 `docs/lanes: internal` |
| L4-8 | `dx_appstore` 确认 `/appstore`、`/appstore/licenses` 长期路径 | ⏳ | 归属 L4 自身（其亦拥有 `dx_appstore`）；路径当前稳定，登记为集成后复核项，无 L6 动作 |

### 3.2 来自 L5（Phase R）

| # | 请求 | 处置 | 落点 / 原因 |
|---|------|------|-------------|
| L5-1 | Q4 引入 phpunit runner（8 Unit + 2 Kernel 待跑） | 🟡 | harness 已交付：`phpunit.xml.dist` 发现 `web/modules/custom/*/tests/src/{Unit,Kernel}`（bootstrap 用 core `tests/bootstrap.php`）+ `scripts/ci/unit-tests.sh`（`--with-kernel` 开关）。**phpunit 安装转集成方**（本线禁跑 composer）→ 见 §6 |
| L5-2 | Q1/Q3 把 `auth-smoke.sh offline` 与两个 `pure-assertions.php` 纳入无人值守 CI；`site` 段与完整 `theme-smoke.sh` 连库勿进 CI | ✅ | gate 段含 `auth-smoke.sh offline`（能力探测：存在且有 offline 段才跑）+ 发现式 pure-assertions；`theme-smoke.sh`/`auth-smoke.sh site` 归 `site:platform` 组，`--no-db` 时整段跳过 |
| L5-3 | `DEV_MEMORY.md` §7 补 `auth-smoke.sh` | ✅ | §7.1 offline + §7.2 site 均已列 |
| L5-4 | `docs/README.md` 索引挂 `docs/lanes/L5-platform-auth.md` | ✅ | README「F. 并行六线」表已挂 |
| L5-5 | `dx:ai-readiness` 命名决策写进文档（`dx:ai-status` 被现网 `AiCommands.php` 占用且禁改） | ✅ | 见本报告 §3.5「命名决策」+ L6 lane 文档 + roadmap R3 注；L5 已在 `docs/auth.md`/代码块/`drush.services.yml` 注明 |
| L5-6 | Q2 `merge-integrity-check.php` 本线 146 文件 0 重复 | ✅ | 已纳入 gate（§2.6 负例证明注入重复→exit 1） |

### 3.3 来自 L1（Phase F）

| # | 请求 | 处置 | 落点 / 原因 |
|---|------|------|-------------|
| L1-1 | `DEV_MEMORY.md` §6 补 `/deliver/todos`；§7 补 desk/delivery-todos/delivery-ops + `dx_delivery/pure-assertions.php`；§3.3 补 Phase F | ✅ | §6 加 `/deliver/todos`；§7.1/§7.2 分列 offline 与三条连库 smoke；§3.3 加 Phase F 摘要 |
| L1-2 | `docs/README.md` 新增 `docs/lanes/` 并挂本文件 | ✅ | README「F. 并行六线」表已挂 L1 |
| L1-3 | `dx_api`/生态线确认 `/dx/api/docs`、`/admin/dx/certs`、`/appstore/licenses` 长期路径（F4 实时探测，改名判 missing） | ⏳ | 归属 `dx_api`/生态线；三路径现稳定且已在 `DEV_MEMORY` §6 登记，转集成后复核项，无 L6 动作 |
| L1-4 | CI 线把 `merge-integrity-check.php` 与各 smoke 第一步纯断言纳入必跑 | ✅ | gate 段已含 merge-integrity + 发现式 pure-assertions（放 `composer install` 后、站点安装前） |
| L1-5 | 主题/门面线：`.dx-deliver-tabs`/`.dx-deliver-board*` 若被全局样式改造需给对照断言 | ⏳ | 归属主题/门面线；L1 不占主题文件，L6 亦不改主题，转待办 |

### 3.4 来自 L2（Phase G）

| # | 请求 | 处置 | 落点 / 原因 |
|---|------|------|-------------|
| L2-X1 | `docs/migrate.md` 补 G1/G2 章节 | ✅ | 已加「Phase G1 模板库可配置化」与「Phase G2 审核队列批量操作」两段 + 无 DB 门禁条目 |
| L2-X2 | `docs/channel.md` 补 G4 配置入口 | ✅ | 已加「站点级 endpoint 配置 UI + 投递健康报表（G4）」段（表单/健康页/API/脱敏/`webhook-site-sync`/state 裁剪） |
| L2-X3 | `docs/README.md` 索引收 `docs/lanes/`；roadmap Phase G 勾选 | ✅ | README 已收；roadmap G1–G4 全 `[x]` |
| L2-X4 | L6 提供隔离测试库开关（`SIMPLETEST_DB`/`DRUSH_URI` 注入各 smoke） | 🟡 | `run-all.sh` 已把**无 DB 门禁段**与**连库 site 段**显式分离，无人值守 CI 只跑无 DB 段（等效满足「CI 不连生产库」）；向各 smoke 注入 `SIMPLETEST_DB` 需改**其它线所有权**的脚本，转待办：建议窗口内用独立测试库跑 site 段（`unit-tests.sh --with-kernel` 已支持 `SIMPLETEST_DB`） |
| L2-X5 | 出包线离线包须附 `checksums.sha256` | ⏳ | 归属 L3 出包线；登记为 L3↔L2 契约待办 |
| L2-X6 | 交付台引用 Exchange 包用 `data.integrity`/`data.report` | ⏳ | 归属 L1 交付线；登记为 L1↔L2 契约待办 |
| L2-X7 | 后台导航暴露批量/Webhook 入口用既有 route | ⏳ | 归属门户主题线；登记待办 |

### 3.5 来自 L3（Phase H）

| # | 请求 | 处置 | 落点 / 原因 |
|---|------|------|-------------|
| L3-X1 | `docs/skills/x-pack-android.md`、`.cursor/skills/x-pack-android/SKILL.md` 同步壳 1.3.0 | ⏳ | `docs/skills/**` 属 L6，但 `.cursor/skills/**` **非 L6 所有权**；且同步需 L3 的 codegen/`--payment-host`/`--capability` 细节。为避免与实现漂移，转待办：集成后由 L3 或专项 docs pass 统一同步两处 |
| L3-X2 | `docs/skills/x-pack-miniprogram.md`、`.cursor/skills/x-pack-miniprogram/**` 同步 | ⏳ | 同 L3-X1（`drupalx_portal` 演练应用、schema 门禁、portal 脚本未知参数 exit 1） |
| L3-X3 | `docs/README.md`/`docs/packer-pipeline.md` 索引 `manifest-pack.md`/`isomorph-pack.md`，补门禁 8 步 | ⏳ | 目标文档 `manifest-pack.md`/`isomorph-pack.md` 仅存在于 L3 分支，master 上不存在，**此时加索引会产生死链**；转待办：L3 文档合入后再挂索引并校对 `packer-pipeline.md` 门禁描述 |
| L3-X4 | roadmap Phase H 勾选；`DEV_MEMORY` §2/§9 增「三处单一真源」 | ✅ | roadmap H1–H4 全 `[x]`（H1/H2 注 SDK 构建待窗口）；`DEV_MEMORY` 新增 §5.7「多端出包单一真源」表（manifest-schema/component_catalog/field-contract + 各自门禁命令） |
| L3-X5 | `dx_channel` 契约以 `clients/field-contract.json` 为准，改键跑 `isomorph_check.py mirror --write` | ⏳ | 归属 L2/服务端线；登记待办（gate 已含 isomorph 检查，漂移会指名端与文件） |
| L3-X6 | `dx_portal` 门面按 `contract.divergences` 收敛后再给 `ends` 加 `web` | ⏳ | 归属 L5/门面线；登记待办 |
| L3-X7 | `scripts/upgrade/gavias/build_package.sh` `DEFAULT_OUT` 改 `$HOME` | ⏳ | 归属部署线（`scripts/upgrade/**` 非 L6 所有权）；登记待办 |
| L3-X8 | 交付台 `channels` 带 `shell_version`/`catalog_version` | ⏳ | 归属 L1 交付线；登记待办 |

### 3.6 命名决策：`dx:ai-readiness`（不是 `dx:ai-status`）

roadmap R3 任务卡字面写「`drush dx:ai-status` 就绪报表」，但 **`dx:ai-status` 已存在**于现网 `web/modules/custom/dx_ai_gateway/src/Commands/AiCommands.php`（输出 `default_provider`/`ready_count`/`providers`/`hint`），且 **L1 的 `delivery-ops-smoke.sh` 依赖其 `ready_count`**，而 `AiCommands.php` 属**禁改文件**。两个 `@command` 同名会冲突。

**决策**：L5 新报表命令取名 **`dx:ai-readiness`**（三元组 models/keys/quota 恒在），`dx:ai-status` 保持 byte-for-byte 不动（`git diff` 空）。集成方与运维须知：

- 查就绪三元组（模型/密钥/配额）→ `drush dx:ai-readiness [--format=json]`
- 既有 `ready_count` 契约（L1 依赖）→ `drush dx:ai-status`（未改）
- 两者都在 `auth-smoke.sh`（offline 口径 + site 回归）与 pure-assertions 中钉住。

---

## 4. 需要维护窗口执行的 DB / 配置动作（合并去重清单）

> 生产 docroot：`/home/wwwroot/drupalX`。以下命令**连生产 MySQL / bootstrap Drupal**，必须安排在维护窗口并知会值班。已按依赖排序、跨线去重。

### A. 集成方在构建/主副本执行（非生产运行期，先于部署）

```bash
cd /home/wwwroot/drupalX

# A1) phpunit 开发依赖（Q4/L5-1）——先 dry-run 看会不会动生产包
composer require --dev drupal/core-dev:^11.4 --dry-run
#     审查输出：应只“新增” dev 包（phpunit/phpunit ~10.5 等），不改 require 里的生产包版本。
#     若 dry-run 显示要升/降级任何生产包 → 停，改到窗口内并加 --with-all-dependencies 后复核。
composer require --dev drupal/core-dev:^11.4          # 确认无误后实装（生成 vendor/bin/phpunit）

# A2) 逐线 --no-ff 合入 master（顺序建议 L2→L4→L1→L3→L5→L6，文档线最后）；每合一条跑一次体检
php scripts/ci/merge-integrity-check.php              # 期望 0 duplicate(s)；非 0 立即停并排查重复实体/类/路由/服务 id
```

### B. 部署代码到生产后（窗口内，连库；一次性）

```bash
cd /home/wwwroot/drupalX

# B1) 确认相关模块已启用（现网多已启用，缺则补）
vendor/bin/drush pm:list --filter=dx_ecosystem,dx_migrate,dx_channel,dx_auth,dx_delivery --format=list
vendor/bin/drush pm:enable dx_ecosystem dx_migrate dx_channel dx_auth dx_delivery -y   # 仅在未启用时

# B2) 一次 updatedb 同时应用两条线的 hook（去重：只跑一次）
#     · dx_delivery_update_11001（L1/F2）：给 administrator 补 'access dx delivery todos'
#     · dx_ecosystem_update_9003（L4/I3）：建审计表 dx_l2_credential_event（不改任何实体 base field）
vendor/bin/drush updatedb -y

# B3) 清缓存（新服务/路由/表单/菜单/drush 命令发现，尤其 L5 的 dx:ai-readiness）
vendor/bin/drush cr
```

### C. 配置写入（存量站点 `config/install` 不补键，需显式写；缺键时新代码均回落默认，不炸）

```bash
# C1) L2/G4 站点级 webhook 键（默认“未配置”，保持今天行为）
vendor/bin/drush php:eval '$c=\Drupal::configFactory()->getEditable("dx_channel.settings");$c->set("webhook",["enabled"=>FALSE,"url"=>"","secret"=>"","events"=>["resource.published"]]);$c->save();'
vendor/bin/drush config:get dx_channel.settings webhook

# C2) L2/G1 额外模板目录（不配也行，默认只用模块内置目录）
vendor/bin/drush php:eval '$c=\Drupal::configFactory()->getEditable("dx_migrate.settings");$c->setValue("template_dirs",[]);$c->save();'

# C3) L4/Phase I 私有 Composer 键（默认空=OE2 占位行为；接真实主机时才填）
vendor/bin/drush config:get dx_ecosystem.settings l2_composer_base_url l2_repository_root l2_composer_driver l2_token_header l2_signing_key l2_download_ttl l2_dist_mode
#     若走打包 config：vendor/bin/drush config:import -y   （不要在生产直接编辑 config/sync）
#     接真实 Satis（非回环）示例：
#     vendor/bin/drush config:set dx_ecosystem.settings l2_repository_enabled=true l2_composer_driver=satis \
#       l2_composer_base_url="https://packages.example.com" l2_repository_root="/srv/l2" \
#       l2_signing_key="<≥32 字符强随机>" l2_download_ttl=600 -y && vendor/bin/drush cr
#     vendor/bin/drush dx:ecosystem-l2-repo --lint && vendor/bin/drush dx:ecosystem-l2-repo --build=/srv/l2 --src=/srv/l2-src
#     vendor/bin/drush dx:ecosystem-l2-plan --uid=<UID>   # 生成 auth.json 片段（明文 token 只此一次）

# C4) 权限（可选，勿发匿名/authenticated）
#     L1 看板签核给运营：vendor/bin/drush user:role:add-permission <role> "access dx delivery todos"
#     L2 批量审核/webhook 给编辑：vendor/bin/drush user:role:add-permission editor "administer dx migrate" "administer dx channel"
```

### D. 验证（窗口内）

```bash
# D1) 无 DB 门禁全绿（此时各线 pure-assertions/offline/L3 静态都会被发现并跑）
bash scripts/ci/run-all.sh --no-db

# D2) phpunit（A1 装好后）
bash scripts/ci/unit-tests.sh                       # Unit（无需 DB）
export SIMPLETEST_DB=mysql://user:pass@127.0.0.1/drupalx_test
export SIMPLETEST_BASE_URL=http://localhost
bash scripts/ci/unit-tests.sh --with-kernel         # Unit + Kernel（Kernel 需测试库）

# D3) 连库 site 冒烟（一条跑全 gate + 所有 site 组；或按线单跑）
bash scripts/ci/run-all.sh --keep-going
#     关键单跑：L1 desk/delivery-todos/delivery-ops/delivery/l3-handoff ·
#              L2 migrate/migrate-l2/migrate-review/migrate-package/exchange/webhook/channel/channel-audit ·
#              L4 ecosystem/l2-credential/l0-publish（两段全跑）· L5 auth-smoke.sh site + theme-smoke.sh
vendor/bin/drush dx:ai-readiness --format=json      # L5/R3 三元组
vendor/bin/drush dx:ai-status | grep ready_count    # 回归：既有契约未受影响（L1 依赖）
```

### E. 构建窗口（需 SDK / 网络，与 DB 窗口分开；L3）

```bash
# E1) Android APK（jdk17 + sdk34）：cd <staging>/android/car_hailing_assistant-android-deploy-latest && ./gradlew :app:assembleDebug
#     通过标准：aapt dump badging 权限集 == 清单 capabilities；与 1.2.x 真机行为一致（versionCode +1 才能覆盖安装）
# E2) Flutter：cd clients/flutter_shell && flutter pub get && flutter test（19 test）&& flutter build apk --release
# E3) 小程序：微信开发者工具导入 <…>-mp-deploy-latest（appid touristappid），断网/token 空回落 fixtures 不白屏
```

**schema 变更合计**：仅 L4 新增 1 张纯审计表 `dx_l2_credential_event`（只增，不耦合任何实体 base field）；L1 SLA/交付物落 `dx_blueprint.acceptance` 既有 JSON 文本列，无 DDL；L2/L5 无 schema 变更；L3 无服务端 schema。

---

## 5. 风险与回退

### 5.1 全局回滚点

- `git tag pre-merge-20260830`（= 2026-08 大集成前 master `2945234`）是**上一轮**大回滚点。
- 本轮六线基点 `17a97ed` 均未合入 master；集成前丢弃任一 `lane/*` 分支对现网**零影响**。
- 建议集成方在开始合并前打新回滚点：`git tag pre-lane-merge-20260902 17a97ed`（本报告不代打，遵守“不打 tag”约束）。

### 5.2 各线回退（已合入后）

| 线 | 回退方式 | 数据侧 |
|----|----------|--------|
| L1 | `git revert` 两提交（`0bff85d` `f4d6801`）+ `drush cr`；或只撤看板入口：`user_role_revoke_permissions("administrator",["access dx delivery todos"])` | 无表/字段变更；SLA/交付物是 `acceptance` JSON 未知键，旧消费方忽略 |
| L2 | 丢弃 `lane/migrate-exchange` 或 `git revert` 三提交 + `drush cr` | 无 schema 变更；`dx_channel.webhook_stats` state 可 `dx:webhook-stats-reset` 清零 |
| L3 | 按提交粒度 revert（H4 脚本+schema → H1 模板+codegen → H2/H3 纯新增） | 无服务端数据；客户契约文件仅追加行 |
| L4 | `git revert`（`6221072` `6ff2ae5` `df6a7cd`）+ `drush cr` | 唯一新表 `dx_l2_credential_event` 可保留（纯审计）或窗口内 `dropSchema`；config 新键回落默认 |
| L5 | 丢弃分支即可（全是新增文件 + 4 个非 runtime 文件追加/订正）；已合入 `git revert` + `drush cr`（撤 `dx:ai-readiness` 发现） | 无表/字段/配置变更；`dx:ai-readiness` 只读 |
| L6 | `git revert` 三提交 | 无 DB/配置动作；`composer.json` 的 `require-dev` 回退 = 去掉该键（生产 `require` 未动） |

### 5.3 集成期主要风险

| 风险 | 影响 | 缓解 |
|------|------|------|
| 跨线重复类/实体/路由/服务 id | 站点 bootstrap fatal | 每合一条跑 `merge-integrity-check.php`（§2.6 负例证明 exit 1）；各线新增类名互不重名 |
| `composer require --dev drupal/core-dev` 牵动生产包 | 生产依赖被升/降级 | 强制先 `--dry-run` 审查（§4 A1）；异常则窗口内 `--with-all-dependencies` 并复核 |
| `docs/visibility.yml` 多线各自登记冲突 | 合并冲突 / 重复键 | 本线只加 `docs/lanes: internal` 与 `docs/integration-report-2026-09.md: internal`（与 L4 的 `docs/lanes: internal` 内容一致，冲突取一致值即可）；R4 规则文档冲突手工并入 |
| `run-all.sh` 被多线同时改 | 合并冲突 | 本线是 CI 唯一所有权者；其它线只新增各自 `*-smoke.sh`，gate 用发现式（glob）而非硬编码，合入即生效 |
| 连库冒烟在 CI 误触发写生产库 | 生产数据污染 | `run-all.sh --no-db` 是无人值守默认；site 段需 `vendor/bin/drush` 存在且显式不带 `--no-db`；各线 offline 段永不 bootstrap |
| Kernel 测试无 `SIMPLETEST_DB` 直接跑 | 连到错误库 / 失败 | `unit-tests.sh` 默认只跑 Unit；`--with-kernel` 且未设 `SIMPLETEST_DB` 时打印 WARN |

---

## 6. 待集成方执行的动作清单（本线禁做，交由集成方在主副本执行）

1. **composer（本线严禁运行）**：`composer require --dev drupal/core-dev:^11.4 --dry-run` → 审查不动生产包 → 去掉 `--dry-run` 实装。产出 `vendor/bin/phpunit`（Drupal 11.4 → PHPUnit ~10.5）。装好后 `bash scripts/ci/unit-tests.sh` 即可执行 L5 的 8 个 Unit 测试；配 `SIMPLETEST_DB` 后 `--with-kernel` 跑 2 个 Kernel。
2. **合并顺序**：逐线 `git merge --no-ff lane/<名>`（建议 L2→L4→L1→L3→L5→L6，文档/CI 线最后），每合一条 `php scripts/ci/merge-integrity-check.php`。`docs/visibility.yml`、`docs/README.md`、`docs/roadmap.md`、`DEV_MEMORY.md` 可能多线触碰，按 R4「保留 master 正文 + 手工并入索引/勾选」处理。
3. **打回滚点 tag**（本线不打 tag）：合并前 `git tag pre-lane-merge-20260902 17a97ed`。
4. **维护窗口**：按 §4 B/C/D 执行 `updatedb`（含 2 hook）+ config 写入 + 无 DB 门禁 + 连库 site 冒烟。
5. **构建窗口**：按 §4 E 执行 Android/Flutter/小程序 真机-SDK 构建（L3 §4 V1–V8）。
6. **转待办的跨线契约**（§3 中标 ⏳ 者）：L1↔生态线路径确认、L1↔主题 CSS 对照、L2↔L3 checksums、L2↔L1 Exchange 引用、L3 skill 文档同步（`docs/skills/**` + `.cursor/skills/**`）、L3 packer-pipeline 索引、L3↔L2 field-contract、L3↔L5 门面 `ends`、部署脚本 `$HOME`、L1↔L3 出包参数。建议登记进 `docs/lanes/nightlog.md` 后续窗口跟进。
