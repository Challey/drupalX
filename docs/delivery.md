# 交钥匙交付台（`dx_delivery`）

> Phase DX MVP：向导 + 对话 → Blueprint → 一键编排。  
> 战略：[turnkey-delivery.md](turnkey-delivery.md)  
> 旧站 L1：[migrate.md](migrate.md) · 舆情演示：`dx_opinion` `/opinion`

## 入口

| 路径 | 说明 |
|------|------|
| `/order` · `/deliver` | 交付台首页（ToC 下单，D7-A 营销入口） |
| `/deliver/wizard` | 页面选型下单 |
| `/deliver/chat` | 对话下单 |
| `/deliver/blueprint/{id}` | 蓝图确认 / 验收（含人工待办） |
| `/admin/dx/delivery` | 管理列表 |
| `/admin/dx/delivery/todos` | L3 / 待补人工待办队列 |

## Drush

```bash
vendor/bin/drush pm:enable dx_delivery dx_migrate dx_opinion dx_trust dx_health -y
vendor/bin/drush dx:delivery-from-chat "做政府门户，要小程序和商城" --machine-name=govdemo
vendor/bin/drush dx:delivery-run 1 --skip-pack
vendor/bin/drush dx:delivery-list
vendor/bin/drush dx:delivery-report 1
vendor/bin/drush dx:delivery-export 1 /tmp/acceptance.json
vendor/bin/drush dx:delivery-todos --blueprint=1
vendor/bin/drush dx:delivery-todo-done 1
vendor/bin/drush dx:migrate-l1 --dry-run
```

## 编排步骤

1. 开通租户（`TenantProvisioner`）  
2. 应用 Theme Studio pack + Channel layout profile  
3. **信任策略**（`dx_trust`：政府默认收紧 / 企业默认）  
3b. **能力启用**（`CapabilityEnabler`：commerce→`dx_payment`、opinion→`dx_opinion`、ai_chat→`dx_ai_gateway`、oss→`dx_oss`；租户侧 soft-fail）  
4. 若勾选 app/miniprogram → `pack-tenant-channels.sh`  
5. **移植**：L1/L2 调 `dx_migrate` → DXEP Ingest（L2 跟详情；无 URL 时用 fixture）；**L3 不假装一键**，写入人工待办（`dx_delivery_todo`）  
6. 验收报告 JSON 存蓝图实体（含 `trust_policy` / `capabilities` / `migrate` / `todos` 步骤与 `checklist` 通过/待补）  

蓝图页 `/deliver/blueprint/{id}` 以步骤清单 + **人工待办**展示验收（通过 / 待补）。打开的 L3/审核/签名待办**不**让流水线失败（`passed` 仍可为 true）。

## 冒烟

```bash
./scripts/ci/delivery-smoke.sh
./scripts/ci/delivery-todos-smoke.sh
./scripts/ci/migrate-smoke.sh
./scripts/ci/migrate-l2-smoke.sh
./scripts/ci/opinion-smoke.sh
./scripts/ci/delivery-ops-smoke.sh
./scripts/ci/desk-smoke.sh
```
