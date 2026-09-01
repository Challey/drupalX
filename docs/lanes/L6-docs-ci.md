# 线 L6 · 文档与 CI 收口（roadmap Phase Q）

> 分支 `lane/docs-ci` · 基点 `master` `17a97ed`（已含 22 分支集成结果）
> 所有权：`docs/**`、`DEV_MEMORY.md`、`README.md`、`scripts/ci/**`、`phpunit.xml.dist`（新建）、`composer.json`（**只允许加 `require-dev`**）、本文件、`docs/lanes/nightlog.md`。
> 现网约束：**不改任何 `web/**` 代码、不动 `setup/nginx`/`setup/ha`/`vendor`(symlink→生产)/`web/core`/contrib**；禁 drush / bootstrap / 连库 `*-smoke.sh`；**禁运行 composer**（只写「待执行命令与理由」交集成方在主副本跑）。
> 允许验证：`php -l`、`bash -n`、`php scripts/ci/merge-integrity-check.php`、自写纯 PHP/bash 断言、只读引用 `vendor/symfony/yaml`。
> 本线是「文档 + CI 唯一所有权者」：把并行六线（L1–L6）的成果收口成可无人值守的门禁、对齐索引与 roadmap、并汇总一份集成报告。

---

## 1. 已完成

### Q1 — CI 流水线收口（`scripts/ci/run-all.sh` 无人值守无 DB 门禁）

把原本「一把梭、默认连生产库」的 `run-all.sh` 重构为 **gate（无 DB/无站点，无人值守安全）+ site（需站点/DB，显式分组可跳过）** 两段：

- **参数**（保持既有用法不破坏）：
  - `--no-db`（别名 `--static-only`）：只跑 gate 段，无人值守 CI 默认。
  - `--group=<g>`：只跑某一组（`gate` 或 `site:<线>`）。
  - `--keep-going`：单步失败不中断，最后统一汇总（默认遇 fail 即停并非零退出）。
  - `--with-kernel`：透传给 `unit-tests.sh`，额外跑 Kernel（需 `SIMPLETEST_DB`）。
  - `--list`：只列出所有步骤与其分组，不执行。
  - `-h|--help`：用法。
- **gate 段（无 DB 门禁）执行顺序**：
  1. `php -l` 遍历本线可写 PHP（脚本自持清单，不含 `web/**`）。
  2. `bash -n` 遍历 `scripts/ci/*.sh`。
  3. `php scripts/ci/merge-integrity-check.php`（重复实体 id / 类名 FQCN / 路由 / 服务 id）。
  4. **发现式** `tests/pure-assertions.php`：`find web/modules/custom web/profiles/custom web/themes/custom -type f -path '*/tests/pure-assertions.php'`，逐个跑（L4-6 / L5-2 / L1-4 请求）。
  5. `auth-smoke.sh offline`（**能力守卫**：脚本存在且 `grep -q offline` 才跑，否则 skip）。
  6. `l0-publish-smoke.sh offline`（同上守卫）。
  7. L3 `scripts/x-pack-manifest.sh --all`（存在才跑）。
  8. L3 `tools/clients/isomorph_check.py all`（存在且有 `python3` 才跑）。
  9. `scripts/ci/unit-tests.sh`（Q4 harness，`--with-kernel` 透传）。
- **site 段**：`site:platform`（`auth-smoke.sh site` + `theme-smoke.sh`，L5-2 要求连库勿进 CI）、以及各线连库 smoke（L1 desk/delivery-*、L2 migrate/exchange/webhook、L4 ecosystem/l2-credential/l0-publish 段 2 等）。`--no-db` 时整段跳过。
- **汇总**：`tally/skip_step/run_step/note_bucket/summarize` 按 bucket 输出每组 `pass/fail/skip` 与 `total`，并以总退出码收尾（任一 fail → 非 0；`--keep-going` 下继续跑完再汇总）。

**关键设计：发现式 + 能力守卫。** L6 分支上其它线代码尚未合入，gate 段用 glob/find 发现产物、用「文件存在且含 offline 段」守卫，因此在 master/L6 基点上这些步骤 **skip（绝不跑连库旧版本）**；各线合入后 **自动亮起**，无需再改 `run-all.sh`。发现逻辑已用 `git ls-tree`/`git show` 只读对拍各线真实产物路径（详见 §2.1）。

### Q2 — 文档索引与状态对齐

- `docs/README.md`：新增「**F. 并行六线**」段，挂 `docs/lanes/` 下 L1–L5 五份 lane 文档 + 本文件 + `nightlog.md`（L1-2 / L2-X3 / L4-7 / L5-4 请求）。
- `docs/roadmap.md`：以**各 lane 文档的离线/静态验收证据为准**逐条勾选 F/G/H/I/R，共 **23 条勾选**；Phase Q 勾 Q1–Q3，**Q4 标「未验收」**（phpunit 未装、本线禁跑 composer，「Unit 可执行」证据不存在）。加了「勾选依据」legend 说明「✅=有离线证据 / 连库待窗口」「未验收=缺证据」。H1/H2 注 SDK 构建待窗口。
- `docs/visibility.yml`：登记 `docs/integration-report-2026-09.md: internal`、`docs/lanes: internal`（与 L4 一致）。
- `DEV_MEMORY.md`：见 §4 跨线请求逐条落点（§3.3/§4/§5.3/§5.4/§5.7/§6/§7/§8）。
- `docs/migrate.md`：补 G1 模板库、G2 审核批量两段 + 无 DB 门禁条目（L2-X1）。
- `docs/channel.md`：补 G4 站点级 webhook 配置 UI + 投递健康报表段（L2-X2）。

### Q3 — 集成与验收报告

新建 `docs/integration-report-2026-09.md`（398 行，`internal`），6 大节：
- §0 摘要表（参与线 / 提交总数 20 / 净变更 / roadmap 勾选 / 无 DB 门禁实跑 exit 0 / 需窗口动作 / 待集成方动作 / 现网行为变更）。
- §1 每线 commit 列表（`git log master..<branch>`，各线文件数与增删行）。
- §2 验证输出摘录（L1–L5 各 lane 记录的本机离线实跑 + §2.6 L6 无 DB 门禁本线实跑 + Q2 负例）。
- §3 跨线请求处置结果（L4/L5/L1/L2/L3 逐条表 ✅/🟡/⏳ + §3.6 `dx:ai-readiness` 命名决策）。
- §4 需维护窗口执行的 DB/配置动作合并去重清单（A 集成方 composer+合并 / B updatedb 含 2 hook / C config 写入 / D 验证 / E 构建窗口），给确切命令与依赖顺序。
- §5 风险与回退（全局回滚点 + 各线回退表 + 集成期风险表）。
- §6 待集成方执行的动作清单。

### Q4 — 测试 runner（phpunit harness）

- `phpunit.xml.dist`（新建，PHPUnit 10.5 schema）：`bootstrap="web/core/tests/bootstrap.php"`（core 用 `dirname(__DIR__,2)` 定位 root，位置无关）；`testsuites` 用 glob 发现——`unit` 覆盖 `web/{modules,profiles,themes}/custom/*/tests/src/Unit`，`kernel` 覆盖 `web/{modules,profiles}/custom/*/tests/src/Kernel`；`<source>` 只 include `web/modules/custom`、exclude 其 `tests`。`SIMPLETEST_DB` 默认空、`SYMFONY_DEPRECATIONS_HELPER=disabled`。
- `scripts/ci/unit-tests.sh`（新建）：**优雅跳过**是核心——`vendor/bin/phpunit` 不可执行时打印「SKIP 未安装 phpunit，跳过」+ 安装命令提示并 **exit 0**；存在时 `"$PHPUNIT" -c phpunit.xml.dist --testsuite unit`；`--with-kernel` 额外跑 `--testsuite kernel`，`SIMPLETEST_DB` 未设则打印 WARN（不静默连错库）。
- `composer.json`：**只在 `require` 块后、`conflict` 前新增** `"require-dev": { "drupal/core-dev": "^11.4" }`（Drupal 11.4 → PHPUnit ~10.5；`drupal/core-dev` 是引入 phpunit 的规范 dev 元包）。`require`/`scripts`/`extra` 等既有内容 **一字未动**。
- 安装命令（含 `--dry-run`）作为**待集成方动作**写入报告 §4 A1、§6 与本文 §3；**本线不跑 composer**。

---

## 2. 验证命令与实际输出

以下为 L6 分支（基点 `17a97ed`，其它线代码尚未合入）**本机实跑**，全部无 DB / 无 drush / 无 composer / 无联网。

### 2.1 无 DB 门禁段（Q1 核心）

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

**发现逻辑对拍各线真实产物**（`git ls-tree`/`git show` 只读，证明合入后会自动跑）：

| gate 步骤 | 合入后命中 | 只读核对来源 |
|-----------|-----------|--------------|
| pure-assertions.php（发现式） | 6 个：`dx_delivery`/`dx_migrate`/`dx_channel`/`dx_ecosystem`/`dx_auth`/`dx_ai_gateway` | 各 `lane/*` 分支 `tests/pure-assertions.php` 均存在 |
| auth-smoke.sh offline | `lane/platform-auth` 脚本含 8 处 `offline` | `git show lane/platform-auth:scripts/ci/auth-smoke.sh` |
| l0-publish-smoke.sh offline | `lane/ecosystem-l2` 脚本含 6 处 `offline` | `git show lane/ecosystem-l2:scripts/ci/l0-publish-smoke.sh` |
| L3 x-pack-manifest.sh --all | `lane/clients-pack:scripts/x-pack-manifest.sh` | `git ls-tree` |
| L3 isomorph_check.py all | `lane/clients-pack:tools/clients/isomorph_check.py` | `git ls-tree` |

当前 5 个 skip 全因这些文件尚在各自 lane 分支、未合入本线基点——**符合预期**（守卫阻止了在 master 上误跑连库旧版本）。

### 2.2 phpunit runner 优雅跳过（Q4 核心）

```
$ bash scripts/ci/unit-tests.sh
== DrupalX unit tests ==
SKIP 未安装 phpunit，跳过（vendor/bin/phpunit 不存在）。
未安装 phpunit，跳过；安装命令见下（由集成方在主副本执行，本线禁跑 composer）：
  cd /home/wwwroot/drupalX
  composer require --dev drupal/core-dev:^11.4 --dry-run   # 先审查：只应新增 dev 包，不动生产包
  composer require --dev drupal/core-dev:^11.4             # 确认无误后实装 → 生成 vendor/bin/phpunit
装好后：
  bash scripts/ci/unit-tests.sh                 # Unit（无需 DB）
  SIMPLETEST_DB=mysql://... bash scripts/ci/unit-tests.sh --with-kernel   # + Kernel
EXIT=0
```

### 2.3 语法层

```
$ bash -n scripts/ci/run-all.sh        && echo ok   → ok
$ bash -n scripts/ci/unit-tests.sh     && echo ok   → ok
$ php -l scripts/ci/merge-integrity-check.php       → No syntax errors detected
$ php scripts/ci/merge-integrity-check.php | tail -1 → OK: 135 file(s) scanned, 0 duplicate(s)
```

### 2.4 Q2 负例（证明 merge-integrity 能挡重复 FQCN）

`merge-integrity-check.php` 接受 `[dir ...]` 参数。用一次性临时目录注入**同 namespace + 同类名**（`Drupal\dx_demo\Service\Collision`）两个文件：

```
$ php scripts/ci/merge-integrity-check.php .tmp-mic-test
DUPLICATE class name: drupal\dx_demo\service\collision
FAIL: ... 1 duplicate(s)
EXIT=1        # 期望 1

$ php scripts/ci/merge-integrity-check.php     # 干净树
OK: 135 file(s) scanned, 0 duplicate(s)
EXIT=0
```

> 注：`merge-integrity-check.php` 按 FQCN（`strtolower($ns.'\\'.$name)`）判重——不同 namespace 的同名类**不算**重复（首次用不同 ns 测试返回 0 duplicate，改用相同 ns 才触发 exit 1）。脚本尾部「N file(s) scanned」的计数为脚本内硬编码统计口径，不受 `[dir]` 参数影响。

### 2.5 换行符自查（LF 铁律）

本线所有**新建**文件写完均 `sed -i 's/\r$//'` 修正并 `grep -c $'\r'` 复查为 0：`phpunit.xml.dist`、`scripts/ci/unit-tests.sh`、`scripts/ci/run-all.sh`（覆盖写）、`docs/integration-report-2026-09.md`、`docs/lanes/L6-docs-ci.md`、`docs/lanes/nightlog.md`。

---

## 3. 需要维护窗口 / 集成方执行的命令（本线禁做）

> 完整合并去重清单见 `docs/integration-report-2026-09.md` §4/§6。本线相关的核心两项：

```bash
# 集成方在主副本 /home/wwwroot/drupalX（先 dry-run 看会不会动生产包）
composer require --dev drupal/core-dev:^11.4 --dry-run
composer require --dev drupal/core-dev:^11.4          # 确认只新增 dev 包后实装 → vendor/bin/phpunit

# 逐线 --no-ff 合入（建议 L2→L4→L1→L3→L5→L6，文档/CI 线最后），每合一条体检
php scripts/ci/merge-integrity-check.php              # 期望 0 duplicate(s)

# 装好 phpunit 后（维护窗口内验证 Q4）
bash scripts/ci/unit-tests.sh                          # Unit（L5 的 8 个）
export SIMPLETEST_DB=mysql://user:pass@127.0.0.1/drupalx_test
bash scripts/ci/unit-tests.sh --with-kernel            # + Kernel（L5 的 2 个）

# 无 DB 门禁全绿（此时各线 pure-assertions/offline/L3 静态都会被发现并跑）
bash scripts/ci/run-all.sh --no-db
```

---

## 4. 跨线请求处置结果

图例：✅ 已落实 · 🟡 部分落实 · ⏳ 转待办（附原因/归属）。完整逐条表见 `docs/integration-report-2026-09.md` §3。

### 4.1 来自 L4（Phase I）

| # | 请求 | 处置 | 落点 |
|---|------|------|------|
| L4-1 | roadmap Phase I 的 I1–I4 勾选 | ✅ | roadmap 四条 `[x]`（离线 359/359 + `l0-publish-smoke.sh offline` 绿）；连库回环注待窗口 |
| L4-2 | `DEV_MEMORY.md` §5.3 补 Phase I 新配置键 | ✅ | §5.3 增 `l2_composer_base_url`/`l2_repository_root`/`l2_composer_driver`/`l2_token_header`/`l2_signing_key`/`l2_download_ttl`/`l2_dist_mode` + 缺键回落 |
| L4-3 | `DEV_MEMORY.md` §6 补 `/dx/ecosystem/l2/*`、`/admin/dx/ecosystem/credentials` | ✅ | §6 路由速查两行已加 |
| L4-4 | `DEV_MEMORY.md` §5.4 补 l2/report Drush 命令 | ✅ | §5.4 增 `dx:ecosystem-l2-plan`/`-l2-repo`/`-l2-auth-check`/`-credential-report` + 状态机收紧 |
| L4-5 | `DEV_MEMORY.md` §7 补 `pure-assertions.php` 与 `l0-publish-smoke.sh offline` | ✅ | §7.1 无 DB 门禁段列全 6 harness + offline 段 |
| L4-6 | CI 门禁纳入 merge-integrity、各 pure-assertions、l0-publish offline | ✅ | `run-all.sh` gate 段全纳入（发现式，合入即跑） |
| L4-7 | `docs/lanes/` 与 README 索引挂载 | ✅ | README「F. 并行六线」+ `visibility.yml` 登记 `docs/lanes: internal` |
| L4-8 | `dx_appstore` 确认 `/appstore`、`/appstore/licenses` 长期路径 | ⏳ | 归属 L4 自身（其拥有 `dx_appstore`）；路径当前稳定，登记集成后复核项 |

### 4.2 来自 L5（Phase R）

| # | 请求 | 处置 | 落点 |
|---|------|------|------|
| L5-1 | Q4 引入 phpunit runner（8 Unit + 2 Kernel 待跑） | 🟡 | harness 已交付（`phpunit.xml.dist` glob 发现 + `unit-tests.sh --with-kernel`）；**phpunit 安装转集成方**（本线禁 composer）→ §3 |
| L5-2 | Q1/Q3 纳入 `auth-smoke.sh offline` + 两个 pure-assertions；`site` 段与 `theme-smoke.sh` 连库勿进 CI | ✅ | gate 段含 `auth-smoke.sh offline`（能力守卫）+ 发现式 pure-assertions；`theme-smoke.sh`/`auth-smoke.sh site` 归 `site:platform`，`--no-db` 整段跳过 |
| L5-3 | `DEV_MEMORY.md` §7 补 `auth-smoke.sh` | ✅ | §7.1 offline + §7.2 site 均列 |
| L5-4 | README 索引挂 `docs/lanes/L5-platform-auth.md` | ✅ | README「F. 并行六线」表已挂 |
| L5-5 | `dx:ai-readiness` 命名决策写进文档 | ✅ | 报告 §3.6 + roadmap R3 注（`dx:ai-status` 被现网 `AiCommands.php` 占用且禁改，L1 依赖其 `ready_count`） |
| L5-6 | merge-integrity 本线 146 文件 0 重复纳入门禁 | ✅ | gate 已含（§2.4 负例证明注入重复→exit 1） |

### 4.3 来自 L1（Phase F）

| # | 请求 | 处置 | 落点 |
|---|------|------|------|
| L1-1 | `DEV_MEMORY.md` §6 补 `/deliver/todos`；§7 补 desk/delivery-* + `dx_delivery/pure-assertions.php`；§3.3 补 Phase F | ✅ | §6 加 `/deliver/todos`；§7.1/§7.2 分列 offline 与三条连库 smoke；§3.3 加 Phase F 摘要 |
| L1-2 | README 新增 `docs/lanes/` 并挂本文件 | ✅ | README「F. 并行六线」表已挂 L1 |
| L1-3 | 生态线确认 `/dx/api/docs`、`/admin/dx/certs`、`/appstore/licenses` 长期路径 | ⏳ | 归属 `dx_api`/生态线；三路径现稳定且已在 `DEV_MEMORY` §6 登记，转集成后复核项 |
| L1-4 | CI 把 merge-integrity 与各 smoke 第一步纯断言纳入必跑 | ✅ | gate 段已含 merge-integrity + 发现式 pure-assertions |
| L1-5 | 主题/门面线给 `.dx-deliver-tabs`/`.dx-deliver-board*` 对照断言 | ⏳ | 归属主题/门面线；L1 不占主题文件，L6 亦不改主题 |

### 4.4 来自 L2（Phase G）

| # | 请求 | 处置 | 落点 |
|---|------|------|------|
| L2-X1 | `docs/migrate.md` 补 G1/G2 章节 | ✅ | 加「Phase G1 模板库可配置化」「Phase G2 审核队列批量操作」两段 + 无 DB 门禁条目 |
| L2-X2 | `docs/channel.md` 补 G4 配置入口 | ✅ | 加「站点级 endpoint 配置 UI + 投递健康报表（G4）」段 |
| L2-X3 | README 索引收 `docs/lanes/`；roadmap Phase G 勾选 | ✅ | README 已收；roadmap G1–G4 全 `[x]` |
| L2-X4 | 提供隔离测试库开关（`SIMPLETEST_DB`/`DRUSH_URI` 注入各 smoke） | 🟡 | `run-all.sh` 已把无 DB 门禁段与连库 site 段显式分离（等效满足「CI 不连生产库」）；向各 smoke 注入 `SIMPLETEST_DB` 需改**其它线所有权**脚本 → 转待办（`unit-tests.sh --with-kernel` 已支持 `SIMPLETEST_DB`） |
| L2-X5 | 出包线离线包须附 `checksums.sha256` | ⏳ | 归属 L3；登记 L3↔L2 契约待办 |
| L2-X6 | 交付台引用 Exchange 包用 `data.integrity`/`data.report` | ⏳ | 归属 L1；登记 L1↔L2 契约待办 |
| L2-X7 | 后台导航暴露批量/Webhook 入口用既有 route | ⏳ | 归属门户主题线；登记待办 |

### 4.5 来自 L3（Phase H）

| # | 请求 | 处置 | 落点 |
|---|------|------|------|
| L3-X1 | `docs/skills/x-pack-android.md` + `.cursor/skills/x-pack-android/SKILL.md` 同步壳 1.3.0 | ⏳ | `docs/skills/**` 属 L6，但 `.cursor/skills/**` **非 L6 所有权**；同步需 L3 codegen/`--payment-host`/`--capability` 细节，避免漂移 → 集成后由 L3 或专项 docs pass 统一同步两处 |
| L3-X2 | `docs/skills/x-pack-miniprogram.md` + `.cursor/skills/x-pack-miniprogram/**` 同步 | ⏳ | 同 L3-X1 |
| L3-X3 | README/`packer-pipeline.md` 索引 `manifest-pack.md`/`isomorph-pack.md` 补门禁 8 步 | ⏳ | 目标文档仅存在于 L3 分支，master 上不存在，**此时加索引会产生死链** → L3 文档合入后再挂 |
| L3-X4 | roadmap Phase H 勾选；`DEV_MEMORY` §2/§9 增「三处单一真源」 | ✅ | roadmap H1–H4 全 `[x]`（H1/H2 注 SDK 构建待窗口）；`DEV_MEMORY` 新增 §5.7「多端出包单一真源」表 |
| L3-X5 | `dx_channel` 契约以 `clients/field-contract.json` 为准，改键跑 `isomorph_check.py mirror --write` | ⏳ | 归属 L2/服务端线；gate 已含 isomorph 检查，漂移会指名端与文件 |
| L3-X6 | `dx_portal` 门面按 `contract.divergences` 收敛后再给 `ends` 加 `web` | ⏳ | 归属 L5/门面线 |
| L3-X7 | `scripts/upgrade/gavias/build_package.sh` `DEFAULT_OUT` 改 `$HOME` | ⏳ | 归属部署线（`scripts/upgrade/**` 非 L6 所有权） |
| L3-X8 | 交付台 `channels` 带 `shell_version`/`catalog_version` | ⏳ | 归属 L1 交付线 |

### 4.6 命名决策：`dx:ai-readiness`（不是 `dx:ai-status`）

roadmap R3 字面写「`drush dx:ai-status` 就绪报表」，但 **`dx:ai-status` 已存在**于现网 `web/modules/custom/dx_ai_gateway/src/Commands/AiCommands.php`（输出 `default_provider`/`ready_count`/`providers`/`hint`），且 **L1 的 `delivery-ops-smoke.sh` 依赖其 `ready_count`**，而 `AiCommands.php` 属**禁改文件**——两个同名 `@command` 会冲突。**决策**：L5 新报表命令取名 `dx:ai-readiness`（models/keys/quota 三元组），`dx:ai-status` 保持 byte-for-byte 不动。查就绪三元组 → `dx:ai-readiness`；既有 `ready_count` 契约 → `dx:ai-status`（未改）。两者都在 `auth-smoke.sh`（offline + site）与 pure-assertions 中钉住。

---

## 5. 风险与回退

### 5.1 本线提交内容

| 提交 | 内容 | 文件 |
|------|------|------|
| `1a516d3` | Q1 + Q4 CI harness | `scripts/ci/run-all.sh`、`scripts/ci/unit-tests.sh`、`phpunit.xml.dist`、`composer.json`（仅 require-dev） |
| `548cc71` | Q2 索引/roadmap/memory 对齐 | `docs/roadmap.md`、`docs/README.md`、`DEV_MEMORY.md`、`docs/visibility.yml`、`docs/migrate.md`、`docs/channel.md` |
| （本提交） | Q3 报告 + L6 lane + nightlog | `docs/integration-report-2026-09.md`、`docs/lanes/L6-docs-ci.md`、`docs/lanes/nightlog.md` |

### 5.2 回退

- **本线未合并时**：直接丢弃 `lane/docs-ci`（对现网零影响——本线只改文档 + CI 脚本 + `composer.json` 的一个新增键）。
- **已合并后**：`git revert` 三提交 + `drush cr`（撤文档/CI 变更）。`composer.json` 的 `require-dev` 回退 = 去掉该键（生产 `require` 未动，无需重装）。
- 本线**无任何 DB / 配置写入动作**，回退不涉及数据。

### 5.3 集成期风险（本线相关）

| 风险 | 影响 | 缓解 |
|------|------|------|
| `composer require --dev drupal/core-dev` 牵动生产包 | 生产依赖被升/降级 | 强制先 `--dry-run` 审查（报告 §4 A1）；异常则窗口内 `--with-all-dependencies` 复核 |
| `docs/visibility.yml` / README / roadmap / DEV_MEMORY 多线各自登记 | 合并冲突 | 本线只加与 L4 一致的 `docs/lanes: internal` + report 条目；冲突按 R4「保留 master 正文 + 手工并入索引/勾选」 |
| `run-all.sh` 被多线同时改 | 合并冲突 | 本线是 CI 唯一所有权者；其它线只新增各自 `*-smoke.sh`，gate 用发现式（glob）而非硬编码，合入即生效 |
| 连库冒烟在 CI 误触发写生产库 | 生产数据污染 | `--no-db` 是无人值守默认；site 段需 `vendor/bin/drush` 存在且显式不带 `--no-db`；各线 offline 段永不 bootstrap |
| Q4 在 phpunit 未装时被误判为「测试通过」 | 漏跑 L5 的 10 个测试 | `unit-tests.sh` 未装时打印 SKIP + 安装命令并 exit 0（**不静默 pass**）；roadmap Q4 标「未验收」；报告 §6 列为待集成方动作 |
