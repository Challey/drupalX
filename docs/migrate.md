# 旧站移植 L1/L2/L3（`dx_migrate` + 交付待办）

> Phase DZ：列表/详情 HTML → DXEP Ingest（草稿节点，人工审核）。  
> 交换协议：[data-exchange.md](data-exchange.md) · 交付编排：[delivery.md](delivery.md)

## 范围

### L1
- 抓取（或 fixture）列表页 HTML  
- 解析常见列表选择器 / 门户模板  
- 经 `dx_channel.ingest` upsert `article`（默认 draft / review）

### L2
- 在 L1 列表基础上跟随详情链接  
- 抽取标题、正文、发布时间、来源  
- HTTP 不可达时使用 `data/fixtures/details/*`  
- **不**直写 SQL

## 门户模板（`--template`）

| 值 | 说明 | 默认 fixture |
|----|------|----------------|
| `auto` | 通用 news-list / article-list / gov-news | `legacy-list.html` |
| `gov_news` | 政务资讯列表 | `gov-news-list.html` |
| `ent_article` | 企业动态列表 | `ent-article-list.html` |
| `legacy` | 经典 `news-list` | `legacy-list.html` |

> **Phase G1（L2，待集成）—— 字段映射模板库可配置化**：模板已从 PHP 常量改为**声明式**，新增行业不再改代码。
> - 模板：`dx_migrate/data/templates/{_base,auto,legacy,gov_news,ent_article,hospital_notice}.yml`；`_` 前缀是内部父模板（`extends: _base` 深合并，映射递归合并、列表整体覆写），不出现在可选清单。
> - 额外目录：`dx_migrate.settings: template_dirs`（绝对路径列表，按序加载、后者覆盖同名）。
> - 命令：`dx:migrate-templates`（清单，含来源目录与校验状态）· `dx:migrate-template-validate <file>` / `--template=<name>`（坏模板逐条报错并非零退出，**不静默回落默认**）· `dx:migrate-l1` / `dx:migrate-l2` / `dx:migrate-package` 新增 `--template=`。
> - 机器名 `/^[a-z_][a-z0-9_]{1,40}$/`；文件名即模板名（`*.yml` / `*.yaml` / `*.json`）；默认 `auto`（`_base.yml` 逐条复刻改造前选择器顺序与兜底，行为保持）。
> 详见 [lanes/L2-migrate-exchange.md](lanes/L2-migrate-exchange.md) §1.1。

## Drush

```bash
vendor/bin/drush pm:enable dx_migrate -y
vendor/bin/drush dx:migrate-l1 --dry-run
vendor/bin/drush dx:migrate-l1 --template=gov_news --dry-run
vendor/bin/drush dx:migrate-l2 --template=gov_news --dry-run
vendor/bin/drush dx:migrate-l2 https://example.gov/news/ --limit=5
vendor/bin/drush dx:migrate-l1 --no-fixture   # 无 URL 且抓取失败则报错
```

## 交付台联动

蓝图 `migrate_level=l1|l2` 时，`DeliveryOrchestrator` 调用 `dx_migrate.runner`。  
无 `source_url` 时使用模块内 fixture（L2 会 enrich 详情 fixture）。  
`migrate_level=l3` 不跑自动导入：验收 JSON 写入 `manual_todos`（范围确认 / 源站权限 / 集成工单），蓝图页展示「人工待办」。

### L3（人工 / 集成，D4-A）

- **不**自动抓取或写入业务库  
- 交付编排写入 `dx_delivery_todo`（类别 `l3_integration`）  
- 验收清单标 **待补**；流水线仍可通过  
- 队列：`/admin/dx/delivery/todos` · `drush dx:delivery-todos`

## 审核队列

路径：`/admin/dx/migrate/review`

列出 Ingest 外部映射中的**未发布**节点：显示外部 ID、按内容类型筛选、一键发布 / 丢弃（删节点并清映射）或跳转编辑。

```bash
vendor/bin/drush dx:migrate-review-list
vendor/bin/drush dx:migrate-review-list --bundle=article
```

> **Phase G2（L2，待集成）—— 审核队列批量操作**：`/admin/dx/migrate/review/batch`（`_permission: administer dx migrate`，**无新增权限项**）。
> - 纯函数层 `ReviewBatch`：`normalize()`（去重 / 非法项拒绝 / 上限 `MAX_ITEMS=50` 切分）· `record()` · `summarize()` · `report()`。
> - 执行层 `ReviewBatchRunner`：`publish` / `discard` / `replay` 三种动作，逐条 try-catch，**单条失败不回滚已成功项**。
> - `replay`（按外部 ID 重放）走重放快照 `ReviewPayloadStore`（state `dx_migrate.review_payloads`，`MAX_SNAPSHOTS=500`，dry-run 不写快照）；`MigrateRunner` 成功 upsert 后落快照，重放幂等。
> 详见 [lanes/L2-migrate-exchange.md](lanes/L2-migrate-exchange.md) §1.2。

## 冒烟

无 DB 门禁（CI 安全，`bash scripts/ci/run-all.sh --no-db` 会自动发现）：

```bash
php web/modules/custom/dx_migrate/tests/pure-assertions.php   # G1/G2 逻辑离线断言（含「默认映射不变」回归）
```

连库（写生产 MySQL，仅维护窗口）：

```bash
./scripts/ci/migrate-smoke.sh
./scripts/ci/migrate-l2-smoke.sh
./scripts/ci/migrate-review-smoke.sh
```

## 导出为 Exchange 包

```bash
vendor/bin/drush dx:migrate-package --template=gov_news
vendor/bin/drush dx:exchange-package-apply pkg_mig_... --dry-run
./scripts/ci/migrate-package-smoke.sh
```

