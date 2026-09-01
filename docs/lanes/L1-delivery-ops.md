# 线 L1 · 交付运营化（roadmap Phase F）

> 分支 `lane/delivery-ops` · 基点 `master` `17a97ed`（已含 22 分支集成结果）  
> 所有权：`web/modules/custom/dx_delivery/**`、`scripts/ci/{delivery,delivery-ops,delivery-todos,desk}-smoke.sh`、`docs/delivery.md`  
> 现网约束：`/deliver`、`/order`、`/admin/dx/delivery` 全部行为保持，只做新增（新路由 / 新渲染分支 / 新可选参数）。

---

## 1. 已完成

### F1 蓝图列表分区（草稿 / 已确认 / 已执行 / 失败重试）

| 落点 | 说明 |
|------|------|
| `src/BlueprintStatusFilter.php`（新） | 纯函数分区：`draft` / `confirmed` / `executed` / `failed`，`executed` 汇总机器状态 `running` + `completed`；`normalize()` 把空值、未知值、历史单状态值统一回落 `all`（旧书签不报错），`statuses()`、`matches()`、`segmentOf()`、`rollup()` 供列表、导出、冒烟共用 |
| `src/BlueprintListBuilder.php` | `getEntityListQuery()` 追加 `status IN` 条件（无 Views 依赖）；`render()` 前置分区条（`dx_delivery_status_tabs`，纯 `?status=` 链接 + 计数 + `data-status-filter`），并给表格补 `url.query_args:status` 缓存上下文；`getOperations()` **仅在 `failed` 分区**追加「失败重试」→ `dx_delivery.confirm?status=failed`，其它分区原样返回 core 的操作 |
| `templates/dx-delivery-status-tabs.html.twig`（新） | `<nav class="dx-deliver-tabs">` + `aria-current="page"`，可复用于 F2 看板 |
| `css/delivery.css` | 追加分区条与看板样式（纯追加，未删既有规则） |

行为保持核对：无 `?status` 时列集合仍为 `ID / Label / Tenant / Status / Type / Operations`（`Operations` 来自 core `EntityListBuilder::buildHeader()`，本次未改），行内容、链接、`#empty` 文案均与 master 一致；`#empty` 覆盖只在非 `all` 分区生效。

### F2 L3 工单看板 `/deliver/todos`

- 新控制器 `src/Controller/DeliveryTodoBoardController.php`：聚合最近 30 个蓝图的 `handoff_todos`（`HandoffTodoService::listFromBlueprint()`），支持 `?status=open|done|overdue|all`（`pending` 归一为 `open`）与 `?blueprint=<id>`；统计走 `HandoffTodoService::stats()`。
- 新模板 `templates/dx-delivery-todos.html.twig`：状态条 + 蓝图筛选 chip + 工单表（蓝图 / 工单 / 状态 / 负责人 / 到期 / 备注 / 操作）+ 逾期高亮。
- 新路由 `dx_delivery.todo_board`（`/deliver/todos`）、`dx_delivery.todo_complete`（`/deliver/todos/{dx_blueprint}/{todo_id}/complete`），权限 `access dx delivery todos+administer dx delivery`（core 同款 OR 写法）。
- 新权限 `access dx delivery todos`（`dx_delivery.permissions.yml`），**不**发给匿名 / authenticated；`dx_delivery_install()` 追加授予 administrator，另加 `dx_delivery_update_11001()` 给存量站点补授。
- 新确认页 `src/Form/DeliveryTodoCompleteForm.php`：`ConfirmFormBase`，签核写回同一个 `HandoffTodoService`（与 drush、`/deliver/blueprint/{id}/todos/{todo_id}/done` 同一条落库路径），可补 `remark`；对已签核工单重复提交是 no-op，不报错、不覆盖 `done_at`。
- 空态可读：① 没有蓝图 → 指向 `/deliver/wizard`·`/deliver/chat`·`/admin/dx/delivery`；② 蓝图未确认执行（无工单）→ 点名蓝图；③ 筛选后为空 → 提示切「全部」；④ `?blueprint=` 指向不存在蓝图 → 回落聚合视图并显示「找不到蓝图 @id」。
- 只读降级：无签核权限时不渲染签核链接、不显示批量提示，但保留等价 drush 命令文本。
- **`HandoffTodoService` 公共方法签名零改动**（只做纯新增，见 §F3）。

### F3 `drush dx:delivery-todo-done --batch` 与工单 SLA

- 命令签名 `todoDone(int $id, string $todo_id = '', array $options = ['batch','owner','due','note','sla-only'])`；`--batch=<id1,id2>` 与单 id 互斥使用，输出仍是单行 JSON：`ok` / `mode`（`single`·`batch`·`sla`）/ `requested` / `completed` / `skipped` / `unknown` / `sla` / `todo_stats` / `todos`（含既有 `"ok":true` 契约）。
- 幂等：`completeBatch()` 复用既有 `complete()`，已完成项进 `skipped` 且保留原 `done_at`；重复跑同一批 → `completed: []` + `ok:true`，不报错。
- SLA：`applySla()` 写 `owner` / `due` / `remark` / `sla_updated_at` 到 todo 数组（存进蓝图既有 `acceptance` JSON，**不动实体 schema**）；`--sla-only` 只改 SLA 不动状态；不给 `--batch`/id 时作用于全部工单。
- `normalizeDue()` 固定按 UTC 解析、`Y-m-d` 原样返回、无法解析的输入原样保留（不静默吞操作员输入）；新增 `dueDeadline()` 统一「裸日期 = 当天 23:59:59 UTC」边界，`isOverdue()` 复用之（修掉此前依赖 PHP 默认时区导致「到期日提前一天」的问题）。
- 语义保持：单个模式仍「未知 id 直接抛错且完全不写库」；批量模式把未知 id 收进 `unknown`、已知项照常落库，命令以非零退出（CI 可断言）。

### F4 验收报告 v3

- 新 `src/Service/AcceptanceReportBuilder.php`（纯数组进 / 数组出）：`definitions()` / `deliverables()` / `sitePaths()` / `context()` / `todoRows()` / `export()`。
- 四类交付物成块：`ops_handbook`（运维手册 → `docs/delivery.md`）、`api_docs`（`/dx/api/docs`）、`certs`（`/admin/dx/certs`）、`l3_source`（`/appstore/licenses`），来源为编排器已写入的 `acceptance.ops`。
- 契约：四个键恒定输出，每项八个列（`key/label/type/value/url/path/status/note`）恒定输出，缺项标 `status:"missing"` + 原因，**绝不省键**；`missing` 列表与 `ok` 计数自洽（`count(missing) === total - ok`）。`ops_handbook` 校验仓库根下文件存在性，站内路径经 `router.no_cache_routes` 实时探测（外链不探测，避免报告变成出网请求）。
- `dx:delivery-export` 产物为单文件可解析 JSON（`spec DX-ACCEPTANCE` / `spec_version 3.0`），并保留全部 v2 键，新增 `status_segment`（复用 F1 映射）、`deliverables`、`handoff_todos{stats,items}`；stdout 摘要加 `spec_version` / `deliverables_total` / `deliverables_ok` / `deliverables_missing` / `todo_stats`。
- `dx:delivery-report` 增加 `spec_version` / `deliverables` / `todo_stats`（既有键与顺序不动）。
- `/deliver/blueprint/{id}/acceptance.json` 默认输出保持历史形状，交付物块需显式 `?deliverables=1`（`acceptanceDownload()` 新增可选 `Request` 参数）。
- 顺手修一处失效断言：`delivery-ops-smoke.sh` 原有 `grep -q '"ok": true'`，而 `dx:delivery-export` 的 stdout 摘要一直是 `json_encode(..., JSON_UNESCAPED_UNICODE)` 的紧凑输出（`"ok":true` 无空格），该断言在现网必然假失败；改为 `grep -qE '"ok": ?true'`（两种形状都接受，不弱化），`test -s /tmp/dx-acceptance-export.json` 原样保留。

---

## 2. 验证命令与实际输出

本线在开发副本内**未**跑任何 drush / `*-smoke.sh`（脚本会写生产 MySQL），以下为本机实际执行结果。

```console
$ php web/modules/custom/dx_delivery/tests/pure-assertions.php
...
ok    owner quote breaks out of markup

OK: 160 assertion(s), 0 failure(s)

$ php scripts/ci/merge-integrity-check.php
OK: 140 file(s) scanned, 0 duplicate(s)

$ for f in desk delivery-todos delivery-ops delivery; do bash -n "scripts/ci/$f-smoke.sh"; done
bash -n ok: scripts/ci/desk-smoke.sh
bash -n ok: scripts/ci/delivery-todos-smoke.sh
bash -n ok: scripts/ci/delivery-ops-smoke.sh
bash -n ok: scripts/ci/delivery-smoke.sh

$ find web/modules/custom/dx_delivery -name '*.php' -exec php -l {} \; | grep -c "No syntax errors detected"
16
```

`tests/pure-assertions.php` 是无 DB、无站点的纯断言脚本（纯函数 + JSON 结构 + Twig 模板渲染，160 条），覆盖：

| 卡 | 断言要点 |
|----|----------|
| F1 | 四段顺序与状态映射、`executed=running+completed`、大小写/空格容错、数组与未知值回落 `all`、`rollup()` 计数、标签模板 `aria-current="page"` 唯一、计数徽标与空列表行为 |
| F2 | 筛选归一与 `filter()`/`stats()`；看板模板行态（`--open`/`--done`）、逾期标记、`<th>负责人</th>`/`<th>到期</th>`、空态块替换表格、只读用户无签核链接但仍见 drush 命令、XSS 转义 |
| F3 | `parseIdList` 去重保序、批量完成/skip/unknown、重放幂等且不改 `done_at`、SLA 三字段与 `sla_updated_at`、`--sla-only` 不动状态、裸日期 UTC 边界（`946771199` 未逾期 / `946771200` 逾期）、legacy `complete()` 仍抛错 |
| F4 | 四键与八列恒定、缺项 `missing` + 原因、计数器自洽、`sitePaths()` 只收站内路径且去重、19 个顶层键不丢、`status_segment`、JSON 往返可解析、空验收仍输出四键 |

需要真实 DB 的断言已写进冒烟脚本（**不在此执行**）：

- `scripts/ci/desk-smoke.sh` — F1：`/admin/dx/delivery` 路由路径、匿名访问仍 `forbidden`（用 `access_manager.checkNamedRoute`，不给匿名授管理权限）、种子 4 个不同状态蓝图后按 `?status=` 逐段渲染真实 list builder（`renderInIsolation`），断言分区互斥、`all`/未知值含全部、只有 `failed` 段出现 `…/confirm?status=failed` 且 core 原操作链接仍在。
- `scripts/ci/delivery-todos-smoke.sh` — F3：真实蓝图上 `--batch` 全量签核 + 重放幂等 + `--sla-only` + 含未知 id 的批量必须非零退出且 `unknown`/`skipped` 正确；F2：两条新路由 path、`_permission` 串、uid 0 `forbidden` / uid 1 `allowed`、`render_board()` 渲染 all/open/不存在蓝图/逾期四种 HTML 形态。
- `scripts/ci/delivery-ops-smoke.sh` — F4：`dx:delivery-export` 产物用 `json_validate` + 结构深检（顶层键、四键八列、计数器、SLA 列、`status_segment`）、`dx:delivery-report` 三个新键、`acceptance.json` 默认输出**不含** `deliverables` 而 `?deliverables=1` 含。
- `scripts/ci/delivery-smoke.sh` — F4：同一蓝图执行前后各导出一次，草稿态断言「四键全在且 `ok=0`」，完成态断言 `status_segment=executed` 与手册指向 `docs/delivery.md` 且 `status=ok`。

三个交付线冒烟脚本第一步都跑 `php web/modules/custom/dx_delivery/tests/pure-assertions.php | tail -1`，逻辑回归会在任何写库动作之前失败。

---

## 3. 需要维护窗口执行的 DB 动作

代码上线后（生产 docroot `/home/wwwroot/drupalX`）按序执行：

```bash
cd /home/wwwroot/drupalX
vendor/bin/drush cr
vendor/bin/drush updatedb -y          # 应用 dx_delivery_update_11001：给 administrator 补 'access dx delivery todos'
vendor/bin/drush cache:rebuild
```

权限与路由核对：

```bash
vendor/bin/drush php:eval '$r = \Drupal::entityTypeManager()->getStorage("user_role")->loadOverrideFree("administrator"); echo $r->hasPermission("access dx delivery todos") ? "granted" : "pending", "\n';'
# 期望 granted

vendor/bin/drush php:eval 'echo \Drupal::service("router.route_provider")->getRouteByName("dx_delivery.todo_board")->getPath(), "\n';'
# 期望 /deliver/todos
```

如需外包 / 运营同学看板上签核（**不要**发给匿名或 authenticated）：

```bash
vendor/bin/drush php:eval '$r = \Drupal::entityTypeManager()->getStorage("user_role")->loadOverrideFree("l3_ops"); if ($r) { $r->grantPermission("access dx delivery todos"); $r->save(); echo "ok\n"; } else { echo "role missing\n"; }'
```

无 schema 变更：SLA 与交付物链接全部落在 `dx_blueprint.acceptance` 既有 JSON 文本列内，批量签核不新增表 / 字段 / 配置。

冒烟（会写生产 MySQL，必须窗口内跑）：

```bash
./scripts/ci/desk-smoke.sh
./scripts/ci/delivery-todos-smoke.sh
./scripts/ci/delivery-ops-smoke.sh
./scripts/ci/delivery-smoke.sh
./scripts/ci/l3-handoff-smoke.sh      # 回归既有 L3 工单链路（未改签名）
```

存量数据回填（可选）：历史蓝图 `acceptance.ops` 可能缺四类链接，`deliverables` 会如实标 `missing`。要补齐需在窗口内重跑编排：

```bash
vendor/bin/drush dx:delivery-report <id> | grep -A6 '"deliverables"'
vendor/bin/drush dx:delivery-run <id> --skip-provision --skip-pack   # 重写 acceptance.ops（会追加 log，评估后再做）
```

---

## 4. 跨线请求

1. `DEV_MEMORY.md`（集成线 / 文档线所有）  
   - §6 路由速查补 `/deliver/todos`（L3 工单看板，需 `access dx delivery todos`）。  
   - §7 冒烟清单补 `desk-smoke.sh`、`delivery-todos-smoke.sh`、`delivery-ops-smoke.sh` 与 `php web/modules/custom/dx_delivery/tests/pure-assertions.php`（当前只列了 6 条，缺这三条）。  
   - §3.3 交钥匙交付小节补「Phase F：四态分区 / 工单看板 / 批量签核 + SLA / 验收报告 v3」。
2. `docs/README.md` 文档索引：新增 `docs/lanes/` 目录条目并挂上本文件（`docs/lanes/` 目前不存在，本线只提交了自身文件）。
3. `dx_api` / 开放生态线：确认 `/dx/api/docs`、`/admin/dx/certs`、`/appstore/licenses` 三条路由的长期路径名。F4 会实时探测，路由改名会被判 `missing`（这是期望行为，但要在文档里对齐）。
4. CI 线（L6）：把 `php scripts/ci/merge-integrity-check.php` 与各 `*-smoke.sh` 第一步的纯断言纳入流水线必跑项；`tests/pure-assertions.php` 无依赖，可放在 `composer install` 之后、任何站点安装之前。
5. 主题 / 门面线：看板与分区条目前使用模块自带 `css/delivery.css`（BEM `dx-deliver*`）。若全局样式改造影响 `.dx-deliver-tabs`、`.dx-deliver-board*`，请在合并时给出对照断言，本线不占用主题文件。

---

## 5. 风险与回退

| 风险 | 影响 | 处置 |
|------|------|------|
| 忘记跑 `updatedb` | administrator 无新权限 → `/deliver/todos` 403（uid 1 仍可用） | `delivery-todos-smoke.sh` 会打印 `warn: run drush updatedb -y`；路由同时接受 `administer dx delivery`，不会彻底锁死 |
| 匿名/ authenticated 被误授权 | 交钥匙内部工单外泄 | 新权限默认不发给任何角色，`dx_delivery_install()` 只授 administrator；冒烟断言 `_permission` 串与 uid 0 `forbidden` |
| 现网页面回归 | `/deliver`、`/order`、`/admin/dx/delivery`、`acceptance.json` | 全部为增量：无 `?status` 时列表列与行不变；`acceptance.json` 默认输出不含新键（脚本显式断言「default 里搜不到 deliverables 就失败」） |
| 分区计数开销 | 每次渲染列表多 5 个 COUNT 查询 | 仅管理员页 + 蓝图量级很小；缓存上下文已按 `status` 参数分片。若量级上涨，改成单条 `GROUP BY status` 聚合即可（`BlueprintStatusFilter::rollup()` 已按此形状设计） |
| 批量签核写库 | 一次改多个工单 | 写前 `listFromBlueprint()`、写后同一 `saveOnBlueprint()`；幂等可重放，异常整体不半写（`unknown` 非空仍先落已知项并回报） |
| `getOperations()` 覆写与 core 未来变更 | D12 会启用 `?CacheableMetadata` 形参 | 覆写沿用 core 同款注释签名 + `func_get_args()` 透传，行为与 `buildOperations()` 一致；纯断言脚本不依赖该分支 |
| 新增 `templates/`、`tests/` 与既有自动化冲突 | 低 | 模板名 `dx-delivery-*` 前缀唯一；`tests/pure-assertions.php` 不匹配 PHPUnit 的 `*Test.php` 发现规则，不会被误跑 |

回退（单文件粒度，不动现网数据）：

```bash
# 1) 代码回退（lane 分支未合并时直接丢弃该线）
# 本线两个提交：f4d6801（模块代码 + docs/delivery.md）与其后的 CI/文档提交
git -C /home/wwwroot/drupalX revert --no-commit <lane-ci-docs-commit> f4d6801
git -C /home/wwwroot/drupalX commit -m "Revert L1 delivery ops (Phase F)"

# 2) 权限回退（保留数据，只撤看板入口）
cd /home/wwwroot/drupalX
vendor/bin/drush php:eval 'user_role_revoke_permissions("administrator", ["access dx delivery todos"]);'
vendor/bin/drush cr
```

数据侧无需回退：Phase F 不新增表 / 字段，`acceptance` JSON 里多出的 `owner/due/remark/sla_updated_at` 与 `deliverables` 块对旧消费方是未知键，`dx:delivery-report` 与旧看板会忽略；如需彻底清掉 SLA，用 `--sla-only --owner= --due= --note=` 逐蓝图置空即可（不改状态）。
