# 旧站移植 L1（`dx_migrate`）

> Phase DZ：列表页 HTML → DXEP Ingest（草稿节点，人工审核）。  
> 交换协议：[data-exchange.md](data-exchange.md) · 交付编排：[delivery.md](delivery.md)

## 范围（L1）

- 抓取（或 fixture）列表页 HTML  
- 解析 `news-list` / `article-list` / 通用链接  
- `PUT` 等价：经 `dx_channel.ingest` upsert `article`（默认 draft / review）  
- **不**直写 SQL、不猜深度字段映射（L2 加深）

## Drush

```bash
vendor/bin/drush pm:enable dx_migrate -y
vendor/bin/drush dx:migrate-l1 --dry-run
vendor/bin/drush dx:migrate-l1 https://example.gov/news/
vendor/bin/drush dx:migrate-l1 --no-fixture   # 无 URL 且抓取失败则报错
```

## 交付台联动

蓝图 `migrate_level=l1|l2` 时，`DeliveryOrchestrator` 调用 `dx_migrate.runner`。  
无 `source_url` 时使用模块内 `data/fixtures/legacy-list.html`。

## 冒烟

```bash
./scripts/ci/migrate-smoke.sh
```
