# 旧站移植 L1/L2（`dx_migrate`）

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

## 审核队列

路径：`/admin/dx/migrate/review`

列出 Ingest 外部映射中的**未发布**节点，支持一键发布或跳转编辑。

## 冒烟

```bash
./scripts/ci/migrate-smoke.sh
./scripts/ci/migrate-l2-smoke.sh
./scripts/ci/migrate-review-smoke.sh
```
