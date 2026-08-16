# 交钥匙交付台（`dx_delivery`）

> Phase DX MVP：向导 + 对话 → Blueprint → 一键编排。  
> 战略：[turnkey-delivery.md](turnkey-delivery.md)  
> 旧站 L1：[migrate.md](migrate.md) · 舆情演示：`dx_opinion` `/opinion`

## 入口

| 路径 | 说明 |
|------|------|
| `/deliver` | 交付台首页 |
| `/deliver/wizard` | 页面选型下单 |
| `/deliver/chat` | 对话下单 |
| `/deliver/blueprint/{id}` | 蓝图确认 / 验收 |
| `/admin/dx/delivery` | 管理列表 |

## Drush

```bash
vendor/bin/drush pm:enable dx_delivery dx_migrate dx_opinion -y
vendor/bin/drush dx:delivery-from-chat "做政府门户，要小程序和商城" --machine-name=govdemo
vendor/bin/drush dx:delivery-run 1 --skip-pack
vendor/bin/drush dx:delivery-list
vendor/bin/drush dx:migrate-l1 --dry-run
```

## 编排步骤

1. 开通租户（`TenantProvisioner`）  
2. 应用 Theme Studio pack + Channel layout profile  
3. **信任策略**（`dx_trust`：政府默认收紧 / 企业默认）  
3b. **能力启用**（`CapabilityEnabler`：commerce→`dx_payment`、opinion→`dx_opinion`、ai_chat→`dx_ai_gateway`、oss→`dx_oss`；租户侧 soft-fail）  
4. 若勾选 app/miniprogram → `pack-tenant-channels.sh`  
5. **移植**：L1/L2 调 `dx_migrate` → DXEP Ingest（L2 跟详情；无 URL 时用 fixture）；L3 记入手工  
6. 验收报告 JSON 存蓝图实体（含 `trust_policy` / `capabilities` / `migrate` 步骤）  

蓝图页 `/deliver/blueprint/{id}` 以步骤清单展示验收（trust / capabilities / migrate 等）。

## 冒烟

```bash
./scripts/ci/delivery-smoke.sh
./scripts/ci/migrate-smoke.sh
./scripts/ci/migrate-l2-smoke.sh
./scripts/ci/opinion-smoke.sh
```
