# Lane L2 · 迁移与交换生产化（roadmap Phase G）

可见性：`internal`（含现网实现细节，不进 L0 公开树）

| 项 | 值 |
|----|----|
| 线 | L2 · 迁移与交换生产化 |
| 分支 | `lane/migrate-exchange` |
| 基点 | `master` `17a97ed`（已含 22 分支集成结果） |
| 卡 | G1 模板库可配置化 · G2 审核队列批量操作 · G3 Exchange 完整性 · G4 Webhook endpoint 配置 UI |
| 状态 | 实作完成 · 纯 PHP 用例 422 条全绿 · 冒烟脚本只写不跑 · 待集成 |

所有权边界：只改 `dx_migrate/**`、`dx_channel/**`、`docs/openapi/dxep-v1.yaml`、`docs/data-exchange.md`、`scripts/ci/{migrate,migrate-l2,migrate-review,migrate-package,exchange,webhook,channel,channel-audit}-smoke.sh` 与本文件。`web/core`、contrib、`vendor`、`setup/nginx`、`setup/ha` 未触碰。

---

## 1. 已完成

### 1.1 G1 — L2 字段映射模板库可配置化

字段映射从 `L1HtmlAdapter` 常量里整体搬出，改为声明式模板 + 载入器。**新增一个行业不再需要改 PHP。**

| 组成 | 落点 |
|------|------|
| 模板库 | `web/modules/custom/dx_migrate/data/templates/{_base,auto,legacy,gov_news,ent_article,hospital_notice}.yml` |
| 载入器 | `src/Service/L2TemplateRegistry.php`（服务 `dx_migrate.l2_templates`）· 异常 `src/Service/L2TemplateException.php` |
| 消费方 | `src/Service/L1HtmlAdapter.php`（选择器、正文分段、external_id 规则、抓取参数、资源类型/状态全部由模板驱动）· `src/Service/MigrateRunner.php` |
| 额外目录 | `dx_migrate.settings: template_dirs`（`config/install` + `config/schema`，绝对路径列表，按序加载、后者覆盖同名） |
| 命令 | `dx:migrate-templates`（清单，含来源目录与校验状态）· `dx:migrate-template-validate <file>` / `--template=<name>`（两路入口）· `dx:migrate-l1` / `dx:migrate-l2` / `dx:migrate-package` 新增 `--template=` |
| 机器名 | `/^[a-z_][a-z0-9_]{1,40}$/`；文件名即模板名，支持 `*.yml` / `*.yaml` / `*.json` |
| 继承 | `extends: _base` 深合并（映射递归合并，**列表整体覆写**）；`_` 前缀文件是内部父模板，不出现在可选清单，`--template=_base` 在使用点被明确拒绝 |
| 校验 | 缺必填键 / 枚举越界 / 正则不可编译 / 坏 `extends` → 逐条 `字段路径 + 原因`，命令以非零退出；`MigrateRunner` 走 `definition()` 时同样抛错，**不静默回落到默认模板** |
| 样本 | `hospital_notice`（通知公告）＋ `data/fixtures/hospital-notice-list.html`、`details/notice-300{1,2}.html`：G1 验收证据，纯数据新增 |

行为保持（现网最重要）：

- 默认 `--template=auto`，`_base.yml` 逐条复刻改造前的选择器顺序与兜底规则；`resource.type=article`、`resource.status=draft`、`review=true`、`detail_limit=10` 与旧常量一致。
- `MigrateRunner::runL1/runL2` 公共签名不变，只在末尾追加可选参数；`L1HtmlAdapter::loadDetailHtml()/parseDetail()` 追加 `string $template = 'auto'` 默认值 → 老调用点行为不变。
- `dx:migrate-package` 的默认模板仍是 `gov_news`。

### 1.2 G2 — 审核队列批量操作

| 组成 | 落点 |
|------|------|
| 表单 | `src/Form/ReviewQueueBatchForm.php` → 路由 `dx_migrate.review_batch` = `/admin/dx/migrate/review/batch`，`_permission: 'administer dx migrate'`（既有权限，无新增权限项） |
| 入口 | 审核列表页顶部链接 + `dx_migrate.links.action.yml` 动作按钮 |
| 纯函数层 | `src/Service/ReviewBatch.php`：`normalize()`（去重、非法项拒绝、上限切分）、`record()`、`summarize()`、`report()`；`MAX_ITEMS = 50` |
| 执行层 | `src/Service/ReviewBatchRunner.php`：`publish` / `discard` / `replay` 三种动作，逐条 try-catch，**单条失败不回滚已成功项** |
| 重放快照 | `src/Service/ReviewPayloadStore.php`（state `dx_migrate.review_payloads`，`MAX_SNAPSHOTS = 500`，dry-run 不写快照）；`MigrateRunner` 在成功 upsert 后落快照 |
| CLI | `dx:migrate-review-batch <action> "<ids>" [--limit=] [--dry-run]`，`--dry-run` 只输出计划 |
| 提交方式 | 表单 POST 回同一路由，由 Form API 校验 `form_token`（不存在 GET 副作用路由） |

约束语义：

- 单次上限 50，超出部分进 `overflow` 不参与执行；`--limit` 只能调小不能调大。
- 汇总报告字段：`action / plan{limit,accepted,rejected,overflow} / total / succeeded / failed / ok / results[]`，`results[]` 每项含目标标识与中文结果说明。
- `replay` 按外部 ID 从快照重建 payload，经 `IngestService::upsert()` 落到**同一节点**（按 `type:external_id` 寻址），重放前后 nid 一致 → 幂等；无快照时该条记为失败并提示。
- `discard` 同时清 `dx_channel.external_map` 映射，不留孤儿。

### 1.3 G3 — Exchange 离线包完整性

| 组成 | 落点 |
|------|------|
| 校验和 | `src/Service/ExchangeChecksums.php`：台账 `checksums.sha256`（格式 `<64hex>  <相对路径>`，与 `sha256sum --check` 兼容）；`ledger/renderLedger/parseLedger/compare/integrityFromComparison/canonicalDigest/contentDigest/archiveDigest/applyGuard` 全为 `public static` 纯函数 |
| 打包 | `ExchangeService::exportZip()` 写台账；`register()` 登记 ZIP 时先比台账，`drush dx:exchange-package-export` 产物自封 |
| 校验入口 | `drush dx:exchange-package-verify <id>`（复核 state 内容与封存摘要）· `drush dx:exchange-package-verify-archive <path>`（离线 ZIP 文件校验，输出 `DX.OK` / 错误码） |
| apply 门禁 | `ExchangeService::apply()` 在**写入任何一条之前**调 `applyGuard()`；`ExchangeController::apply()` 收到拒收即返回 400 + 稳定错误码 |
| 报告分页 | `src/Service/ExchangeReport.php`：`paginate()`（`DEFAULT_PAGE_SIZE = 25`、`MAX_PAGE_SIZE = 200`，非法值回落并回 `page_clamped`）；聚合计数永远描述整轮，不描述当页 |
| 失败重试 | `retryFailed()` + `drush dx:exchange-package-retry` + `POST /api/dx/v1/exchange/packages/{id}/retry`；`mergeItems()/mergeReports()` 把新结果折回同一份报告 → 反复重试不增长报告；`MAX_REPORT_RETRIES = 5`，用尽后返回 HTTP 200 + `meta.code: DX.EXCHANGE.RETRY_EXHAUSTED` + `retry_blocked=true` |
| HTTP 读报告 | `GET /api/dx/v1/exchange/packages/{id}/report?page=&page_size=`（`drush dx:exchange-package-report` 另有 `--failed-only`） |
| 稳定错误码 | `DX.EXCHANGE.CHECKSUM_MISSING` / `CHECKSUM_MISMATCH` / `CHECKSUM_INVALID` / `RETRY_EXHAUSTED`，已并入 `docs/data-exchange.md` §12 |
| 夹具 | `data/packages/demo-package.zip` 重新生成（含台账，598 → 836 B），生成脚本 `data/packages/make-demo-zip.php` 入库可复跑 |

兼容矩阵（`applyGuard`，决定现网是否会被新门禁锁住）：

| `integrity.status` | 含义 | apply |
|--------------------|------|-------|
| `legacy` | G3 之前登记的包（无 integrity 记录） | 放行 |
| `inline` | JSON 体内联登记（无 ZIP 台账） | 放行 |
| `verified` | ZIP 登记时台账全对 | 放行，且逐次比对 `content_sha256`，被改过 → `CHECKSUM_MISMATCH` |
| `missing` | ZIP 无台账 | 拒收 `CHECKSUM_MISSING` |
| `mismatched` | 台账与内容对不上 | 拒收（内容不符 → `CHECKSUM_MISMATCH`；台账行不可解析 / 越界 → `CHECKSUM_INVALID`） |
| `unsigned` | 台账行数与资源数不等（历史导出） | 放行 |

台账安全：归档成员名与台账名都会先归一化（去掉前导 `/`、`./`、`\` 分隔符），绝对路径无法指向归档之外；台账行里出现 `..` 段直接记为非法行 → 整包以 `DX.EXCHANGE.CHECKSUM_INVALID` 拒收（登记与 apply 同码），与「内容被篡改」的 `CHECKSUM_MISMATCH` 区分开，便于对端判断是导出坏了还是包被动过。

### 1.4 G4 — Webhook 真实 endpoint 配置 UI

| 组成 | 落点 |
|------|------|
| 配置表单 | `src/Form/WebhookSettingsForm.php` → `/admin/dx/channel/webhooks`（系统设置菜单项，`administer dx channel`）：站点级 endpoint URL + HMAC 签名密钥 + 启停 + 订阅事件 |
| 配置键 | `dx_channel.settings: webhook.{enabled,url,secret,events}`（`config/install` 默认全空/false，schema 同步补齐） |
| 镜像机制 | 保存即以固定 id `wh_site` 镜像进既有 endpoint 表（`WebhookService::syncSiteEndpoint()`）；清空 URL 即移除镜像 → 复用既有签名、重试、死信链路，**不新增第二套投递器** |
| CLI | `drush dx:webhook-site-sync [--enable=] [--disable=]`（只改 config 不经 UI 时同步镜像；URL 为空时输出 `mirrored endpoint removed`）· `drush dx:webhook-health --days=` · `drush dx:webhook-stats-reset` |
| 健康报表 | 后台页 `/admin/dx/channel/webhooks/health`（`WebhookHealthController`）+ API `GET /api/dx/v1/webhooks/health?days=1..90`（scope `webhook:read`） |
| 计数层 | `src/Service/WebhookHealth.php` 纯静态：`recordDispatch/recordRetry/report/endpointRows/grade/windowTotals/backoffSeconds/mayRetry/normalize`；分级阈值 `HEALTHY_RATE = 0.95`、`FAILING_RATE = 0.5`，状态 `unconfigured/unknown/healthy/degraded/failing` |
| 死信退避 | 既有 `retryDeadLetters()` 追加可选参 `bool $ignoreBackoff = FALSE`；`drush dx:webhook-retry` 新增 `--force`；退避表 `[1,5,30,120,600,1800,3600,7200]`、预算 `MAX_ATTEMPTS = 8`，第 9 次不再投递，计入 `deferred` |
| 存储 | 新 state 键 `dx_channel.webhook_stats`（按 UTC 日聚合，序列上限 120 天，`drush dx:webhook-stats-reset` 清零） |

未配置时的行为与今天完全一致：`webhook.url` 为空 ⇒ 不产生 `wh_site` ⇒ 只投递既有注册端点；投递失败继续落死信，**不抛异常**；`dx:webhook-health` 报 `unconfigured`；`site_endpoint` 为 `false`；`config/install` 里 `webhook.url`/`enabled` 为空，冒烟脚本据此断言「opt-in」。

> 任务卡里写的「`dx_channel.settings.yml` 里是 `fail.example.com` 占位 sink」经核对与仓库现状不符：该文件的 `webhook` 键在本次改动前**根本不存在**，`fail.example.com` 只出现在代码内 fail-sink 与冒烟测试 URL。因此 G4 的实现是「补上真正的配置入口 + 保持未配置语义」，而不是「替换占位值」。

### 1.5 随带修正（既有缺陷，均在我拥有的文件内）

1. `docs/openapi/dxep-v1.yaml` 里 `/exchange/changes`、`/exchange/push`、`/exchange/packages`、`/exchange/packages/{package_id}` 四条路径**从未有对应路由**（实际为 `/api/dx/v1/exchange/*`，`setup/nginx` 也无 rewrite）。已按实现订正，并在纯断言脚本里加了双向契约检查防回归。
2. `MigrateCommands::templateList()` 中内部父模板的状态列原显示 `-`（歧义），改为 `internal parent`。
3. `docs/data-exchange.md` 补齐 G3/G4 细节并与代码对齐（§10.2.1 台账格式与 7 种行→结果、§10.2.2 分页与重试语义、§10.3 退避与站点级 endpoint、§12 四个错误码）。

### 1.6 现网约束自查

被删除的公共签名 **0** 个；下列签名只做「追加可选参数」或「新增」，老调用点不受影响：

```
ExchangeService::apply(string, bool, array $options = [])
ExchangeService::register(array $body, array $integrity = [])
ExchangeService::retryFailed(...) / report(...) / verifyPackage(...) / integrity(...)   ← 新增
WebhookService::retryDeadLetters(int $limit = 20, bool $ignoreBackoff = FALSE)
WebhookService::countDeadLetters() / stats() / saveStats() / resetStats() / healthReport() / siteEndpoint() / syncSiteEndpoint()  ← 新增
L1HtmlAdapter::loadDetailHtml(..., string $template = 'auto') / parseDetail(string, string $template = 'auto')
MigrateRunner::runL1(...) / runL2(..., string $template = 'auto', int $detailLimit = 10)
```

已有路由的 path / requirements 一字未改；新增路由 6 条（3 后台：批量表单 + webhook 设置 + 健康报表；3 API：report / retry / webhooks-health）。未新建任何与现有类同名同职责的类。

---

## 2. 验证命令与实际输出

全部在 `/home/wwwroot/drupalX/.worktrees/lane-migrate-exchange` 内执行。**未运行 drush、未运行任何 `*-smoke.sh`**（会写生产 MySQL），冒烟脚本只写不跑；无 composer、无联网。

```
$ php web/modules/custom/dx_migrate/tests/pure-assertions.php
dx_migrate pure assertions: 181 assertion(s), 0 failure(s)

$ php web/modules/custom/dx_channel/tests/pure-assertions.php
dx_channel pure assertions: 241 assertion(s), 0 failure(s)

$ php scripts/ci/merge-integrity-check.php
OK: 149 file(s) scanned, 0 duplicate(s)

$ find web/modules/custom/dx_migrate web/modules/custom/dx_channel -name '*.php' -print0 \
    | xargs -0 -n1 php -l | grep -v '^No syntax errors' | wc -l
0
$ git diff --name-only | grep '\.php$' | xargs -n1 php -l | grep -v '^No syntax errors' | wc -l
0

$ for f in migrate-smoke migrate-l2-smoke migrate-review-smoke migrate-package-smoke \
           exchange-smoke webhook-smoke channel-smoke channel-audit-smoke; do
      bash -n scripts/ci/$f.sh && echo "OK scripts/ci/$f.sh"; done
OK scripts/ci/migrate-smoke.sh
OK scripts/ci/migrate-l2-smoke.sh
OK scripts/ci/migrate-review-smoke.sh
OK scripts/ci/migrate-package-smoke.sh
OK scripts/ci/exchange-smoke.sh
OK scripts/ci/webhook-smoke.sh
OK scripts/ci/channel-smoke.sh
OK scripts/ci/channel-audit-smoke.sh
```

YAML / OpenAPI 体检（`vendor` 为指向生产的 symlink，只读引用 Symfony Yaml）：

```
$ php -r 'require "vendor/autoload.php"; ...'  # 逐个解析本线改过的 18 个 yml
all yaml parsed cleanly            # dxep-v1.yaml、6 个模板、2 个 config/install、2 个 schema、
                                   # 2 个 routing、dx_migrate.services.yml、2 个 drush.services.yml、
                                   # dx_channel.links.menu.yml、dx_migrate.links.action.yml（共 18 个）

paths: 16 / schemas: 5 / distinct $ref: 5 / unresolved refs: none
```

### 2.1 纯 PHP 用例覆盖了什么

`dx_migrate/tests/pure-assertions.php`（181 条，无 Drupal bootstrap，自带 `spl_autoload_register`）：

- G1 模板发现与目录顺序；默认映射与改造前逐字段等值（向后兼容回归）；缺必填键 → 明确 issue 清单（含 `list.fixture`、`item.external_id.source`、`fetch.timeout`、`resource.status` 等 21 条）；`extends` 深合并语义（列表整体覆写）；**纯数据新增行业**（临时目录 + `_base` 继承，不改 PHP 即可 `definition()` 拿到完整映射）；`_base` 不可直接使用；适配器对 4 份 fixture 的解析结果；占位符渲染。
- G2 `normalize()` 的去重 / 非法拒绝 / 上限溢出 / `report()` 聚合（全成功、部分失败、全失败、空输入）；快照库 `put/find/forget/forgetKey/prune/stats`（含 dry-run 不落快照、上限裁剪）。

`dx_channel/tests/pure-assertions.php`（241 条）：

- G3 台账 build/render/parse（含短摘要、垃圾行、`..` 越界行与其对应的 `CHECKSUM_INVALID`）、compare 的 mismatch/missing/undeclared、canonical JSON 与摘要稳定性、`applyGuard` 六种状态矩阵、**已提交的 `demo-package.zip` 真的能自校验通过**、报告分页（越界回落、`page_clamped`、聚合不随页变化）、重试合并幂等（重放两次报告条目数不变）。
- G4 计数聚合、成功率、分级、UTC 窗口裁剪、退避表单调性、`mayRetry` 预算、未配置态。
- 契约：OpenAPI 的 `/api/dx/v1/exchange|webhooks*` 与 `dx_channel.routing.yml` **双向**一致（新增端点漏文档会红）、`$ref` 全解析、错误码与 `checksums.sha256` 在两份文档中同名、G2 批量路由确为带 token 的表单类且文件存在、三条新 API 路由有 controller。

### 2.2 冒烟脚本新增断言（需要 DB，只写不跑）

| 脚本 | 新增内容 |
|------|----------|
| `migrate-smoke.sh` | L1 默认 `template=auto` 回显、`--template=gov_news` 回显、模板清单含三模板与 `internal parent`、`--template=no_such_template` 硬失败、`--template=_base` 被拒 |
| `migrate-l2-smoke.sh` | `dx:migrate-templates` / `dx:migrate-template-validate`（文件与 `--template` 两路）；`hospital_notice` 深合并结果 python 校验；坏模板非零退出 + 逐字段 issue + `ISSUES>=20`；`--limit=1` 覆盖 `detail_limit`；**用 `dx_migrate.settings.template_dirs` 指向 `/tmp` 目录纯数据加行业**（正向可跑、清空后必须 `Unknown migrate template`） |
| `migrate-review-smoke.sh` | 批量路由与 `_form`；`--dry-run` 计划字段；`--limit=2 "1 2 3 4"` 的 overflow；未知 action 拒绝；真实 publish「2 成功 + 1 失败且已成功的确实已发布、不回滚」；pending 恰减 2；discard 后节点与 `external_map` 均无残留；replay 前后 nid 相同（幂等）；未知外部 ID 记 failed |
| `migrate-package-smoke.sh` | 内联登记 `status=inline` 与 `counts`/`resource_count` 一致、64hex 摘要、换模板摘要变化、`verify` 放行、导出的 ZIP 含 `checksums.sha256`、`verify-archive` 输出 `DX.OK`、ZIP 再登记 `status=verified`、`apply --dry-run` 计数 |
| `exchange-smoke.sh` | 台账往返、篡改 ZIP 后 apply 拒收错误码、无台账 ZIP → `CHECKSUM_MISSING`、台账不可解析（短摘要 + `..` 行）→ `CHECKSUM_INVALID`（verify-archive 与 register 两路）、报告分页切片、失败重试不重复入库、`RETRY_EXHAUSTED` 预算 |
| `webhook-smoke.sh` | 健康报表字段、退避 `deferred` 与 `--force`、站点级 endpoint 镜像→移除的完整回环、`stats-reset`、Bearer scope 下 `/api/dx/v1/webhooks/health` 返回 200 |
| `channel-smoke.sh` | G4 两条 admin 路由 path 与 `_permission`、`healthReport(7)` 键齐全、未配置时 `site_endpoint` 为假、`dx_channel.settings` 的 `webhook.url`/`enabled` 为空（opt-in） |
| `channel-audit-smoke.sh` | 无需改动（本线未触碰审计链路），保持原样 |

---

## 3. 需要维护窗口执行的 DB 动作与 config 导入

**本线无 schema / 实体 / 字段变更**（未改任何 `*.install`，未新增 Content Entity，`kv`/`state` 表结构不变），因此没有 DDL 型动作。以下是让新代码生效所需的操作，逐条给确切命令：

1. 部署代码后清缓存（新服务、新路由、新表单、新菜单项都依赖）：

   ```bash
   cd /home/wwwroot/drupalX
   drush cr
   ```

2. 确认两模块已启用（现网均已启用，此步通常为空操作）：

   ```bash
   drush pm:list --filter=dx_migrate,dx_channel --format=list
   drush pm:enable dx_migrate dx_channel -y     # 仅在未启用时执行
   ```

3. **config 导入 —— `config/install` 不会为已安装站点补键**，需显式写入（新代码对缺键是容错的，写键的意义是让后台表单与 `drush config-get` 有明确初始值）：

   ```bash
   # G4：站点级 webhook 键（默认「未配置」，保持今天行为）
   drush php:eval '
   $c = \Drupal::configFactory()->getEditable("dx_channel.settings");
   $c->set("webhook", ["enabled" => FALSE, "url" => "", "secret" => "", "events" => ["resource.published"]]);
   $c->save();'
   drush config:get dx_channel.settings webhook

   # G1：额外模板目录（不配也行，默认只用模块内置目录）
   drush php:eval '
   $c = \Drupal::configFactory()->getEditable("dx_migrate.settings");
   $c->setValue("template_dirs", []);
   $c->save();'
   drush config:get dx_migrate.settings template_dirs
   ```

   若走配置同步（`$config_directories[sync]`），请导出后 `drush config:import -y`，**不要**在生产直接编辑 `config/sync`。

4. 启用真实 endpoint（仅在拿到对端 URL 与密钥时）：优先后台 `/admin/dx/channel/webhooks` 填写；若只能改 config，则改完必须同步镜像：

   ```bash
   drush php:eval '
   $c = \Drupal::configFactory()->getEditable("dx_channel.settings");
   $c->set("webhook.url", "https://partner.example/hooks/dx");
   $c->set("webhook.secret", "<shared-secret>");
   $c->set("webhook.enabled", TRUE);
   $c->save();'
   drush dx:webhook-site-sync            # 把 config 镜像为端点表里的 wh_site
   drush dx:webhook-test                 # 打一发 resource.published 验证签名与连通
   drush dx:webhook-health --days=1      # 期望 status 从 unknown → healthy/degraded
   ```

5. 权限：本线**未新增权限项**，复用既有 `administer dx migrate`（`dx_migrate.permissions.yml`，安装时已授 administrator）与 `administer dx channel`。若需让运营编辑角色使用批量审核与 G4 页面：

   ```bash
   drush user:role:add-permission editor "administer dx migrate" "administer dx channel"
   ```

6. 建议先跑一次现网数据体检（只读，不改数据）：

   ```bash
   drush dx:migrate-templates
   drush dx:migrate-review-list --bundle=article
   drush dx:exchange-package-list
   drush dx:webhook-health --days=7
   ```

7. 冒烟回归（会写生产 MySQL，必须安排在窗口内、并知会值班）：

   ```bash
   ./scripts/ci/migrate-smoke.sh && ./scripts/ci/migrate-l2-smoke.sh \
    && ./scripts/ci/migrate-review-smoke.sh && ./scripts/ci/migrate-package-smoke.sh \
    && ./scripts/ci/exchange-smoke.sh && ./scripts/ci/webhook-smoke.sh \
    && ./scripts/ci/channel-smoke.sh && ./scripts/ci/channel-audit-smoke.sh
   ```

   已知副作用：`migrate-l2-smoke.sh` 会临时写 `dx_migrate.settings.template_dirs` 指向 `/tmp/dx-mig-templates`，脚本结尾把它重置为 `[]` 并删除临时目录；`webhook-smoke.sh` 只通过服务方法镜像 `wh_site` 并在段尾移除，不写 `dx_channel.settings`。

---

## 4. 跨线请求（不在本线所有权内，未自行改动）

| # | 请求 | 目标 | 需要的动作 |
|---|------|------|-----------|
| X1 | `docs/migrate.md` 缺 G1/G2 章节 | 文档索引线 / L6 | 增「字段映射模板库」（`dx:migrate-templates`、`--template=`、`template_dirs`、模板 schema）与「审核队列批量操作」（表单路由、50 上限、三种动作、幂等语义）两节；素材可直接取自本文件 §1.1–1.2 与 `dx_migrate/tests/pure-assertions.php` |
| X2 | `docs/channel.md` 缺 G4 配置入口 | 文档索引线 / L6 | 补 `/admin/dx/channel/webhooks`（表单）、`/admin/dx/channel/webhooks/health`（报表）、`GET /api/dx/v1/webhooks/health` 与 scope `webhook:read`；并注明未配置时的行为不变 |
| X3 | `docs/README.md` 索引未收 `docs/lanes/` | 文档索引线 | 把本文件与后续 lane 文档登记进索引；`roadmap.md` Phase G 四条勾选（我未勾，避免与集成阶段冲突） |
| X4 | `scripts/ci/*-smoke.sh` 全部连生产 MySQL，本线需要 DB 的断言无法在无窗口环境验证 | L6 质量与 CI 线 | 提供隔离测试库开关（例如 `SIMPLETEST_DB` / `DRUSH_URI` 环境变量注入到各 smoke 脚本），否则 CI 只能跑 `php -l` + 纯断言脚本 |
| X5 | 出包线若产离线包 | L3 多端出包 | 必须随包附 `checksums.sha256`（`<64hex>␣␣<相对路径>`，不含绝对路径与 `..`），否则登记即 `DX.EXCHANGE.CHECKSUM_MISSING` |
| X6 | 交付台在验收报告里引用 Exchange 包 | L1 交付运营化 | 引用 `data.integrity` / `data.report`（`docs/data-exchange.md` §10.2.1–10.2.2 为准），不要自行拼校验和 |
| X7 | 后台导航 | 门户主题线 | 若侧栏需暴露「批量操作」「Webhook 投递」入口，请用 `dx_migrate.links.action.yml` / `dx_channel.links.menu.yml` 的既有 route，勿新建 route |

---

## 5. 风险与回退

| 风险 | 面 | 缓解 | 回退 |
|------|----|------|------|
| `L1HtmlAdapter` 改写量大（+410/-…），选择器语义若与旧常量有细微差异会影响迁移结果 | 中 | `_base.yml` 逐条复刻旧顺序；181 条纯断言含「默认映射不变」回归；`migrate-smoke.sh` / `migrate-l2-smoke.sh` 对 4 份 fixture 双向覆盖 | 迁移是幂等的（按 `type:external_id` upsert），重跑不产生重复节点；如需彻底回退，丢弃本线 dx_migrate 改动即可 |
| 模板可被外部目录覆盖，误配置会把生产迁移指向错误选择器 | 中 | 只有 `administer dx migrate` 可改 config；坏模板载入即报错且非零退出，不回落；`dx:migrate-templates` 显示每个模板的来源目录 | `drush php:eval '...setValue("template_dirs", [])->save();'` + `drush cr` |
| G3 强制校验可能锁住「历史上被手工改过 state」的旧包 | 中低 | `legacy`/`inline`/`unsigned` 一律放行；只有 ZIP 登记且内容被改过才拒收 | 用 `dx:exchange-package-export` 重新导出再登记；或按 §1.3 兼容矩阵评估后临时移除该包 `integrity` 记录（需在窗口内执行并记录） |
| apply 报告结构升级后，旧 state 里的报告字段缺失 | 低 | `ExchangeReport::paginate/items/failedItems` 对缺字段容错并回填默认 | 无需动作 |
| G4 误配 URL 时事件会被投到陌生端点 | 中 | 默认 `enabled=false`/`url=''`；保存表单需 `administer dx channel`（restrict access）；密钥只在写入时接收，展示与 API 输出均脱敏；健康报表可立刻看到失败率 | 后台清空 URL 保存，或窗口内执行 `drush php:eval '\Drupal::configFactory()->getEditable("dx_channel.settings")->set("webhook.enabled", FALSE)->set("webhook.url", "")->save();'` 后 `drush dx:webhook-site-sync`（空 URL ⇒ 移除 `wh_site`） |
| `dx_channel.webhook_stats` 新 state 键随时间膨胀 | 低 | 日序列上限 120 天裁剪；`drush dx:webhook-stats-reset` 随时清零 | 无 |
| `demo-package.zip` 二进制变更 | 低 | 仅冒烟与文档示例使用；`make-demo-zip.php` 入库可随时重生 | `php web/modules/custom/dx_channel/data/packages/make-demo-zip.php` |
| 与本线无关的集成风险：重复类声明 | 高（历史事故） | `php scripts/ci/merge-integrity-check.php` → `0 duplicate(s)`；新增类名全部为本线独有（`L2Template*`、`ReviewBatch*`、`ReviewPayloadStore`、`ExchangeChecksums`、`ExchangeReport`、`WebhookHealth`、`Webhook*Controller/Form`） | 集成时保留本线版本即可 |

**整体回退**：本线未 push、未打 tag，基点即 `master` `17a97ed`；集成阶段若判定不合入，直接丢弃 `lane/migrate-exchange` 分支不影响任何已落地内容。已合入后回退 = `git revert` 本线合并提交 + `drush cr`；无 DB 结构变更 ⇒ 无需数据库回滚。

---

## 6. 本线提交内容（55 个文件 = 28 改 + 27 新，含本文件）

**dx_migrate（新增 19 / 修改 7）**

```
+ config/install/dx_migrate.settings.yml        + config/schema/dx_migrate.schema.yml
+ data/templates/{_base,auto,legacy,gov_news,ent_article,hospital_notice}.yml
+ data/fixtures/hospital-notice-list.html       + data/fixtures/details/notice-3001.html
+ data/fixtures/details/notice-3002.html
+ src/Service/{L2TemplateRegistry,L2TemplateException,ReviewBatch,ReviewBatchRunner,ReviewPayloadStore}.php
+ src/Form/ReviewQueueBatchForm.php             + dx_migrate.links.action.yml
+ tests/pure-assertions.php
M dx_migrate.{routing,services,drush.services}.yml
M src/Commands/MigrateCommands.php              M src/Controller/ReviewQueueController.php
M src/Service/{L1HtmlAdapter,MigrateRunner}.php
```

**dx_channel（新增 7 / 修改 12）**

```
+ src/Service/{ExchangeChecksums,ExchangeReport,WebhookHealth}.php
+ src/Form/WebhookSettingsForm.php              + src/Controller/WebhookHealthController.php
+ data/packages/make-demo-zip.php               + tests/pure-assertions.php
M src/Service/{ExchangeService,WebhookService}.php
M src/Controller/{ExchangeController,WebhookController}.php
M src/Commands/{ExchangeCommands,WebhookCommands}.php
M dx_channel.{routing,links.menu,drush.services}.yml
M config/{install/dx_channel.settings,schema/dx_channel.schema}.yml
M data/packages/demo-package.zip                （重生成，含台账）
```

**文档与冒烟（新增 1 / 修改 9）**

```
M docs/data-exchange.md                         M docs/openapi/dxep-v1.yaml
M scripts/ci/{migrate,migrate-l2,migrate-review,migrate-package,exchange,webhook,channel}-smoke.sh
+ docs/lanes/L2-migrate-exchange.md             （本文件）
```
