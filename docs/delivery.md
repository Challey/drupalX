# 交钥匙交付台（`dx_delivery`）

> Phase DX MVP：向导 + 对话 → Blueprint → 一键编排。  
> 战略：[turnkey-delivery.md](turnkey-delivery.md)

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
vendor/bin/drush pm:enable dx_delivery -y
vendor/bin/drush dx:delivery-from-chat "做政府门户，要小程序和商城" --machine-name=govdemo
vendor/bin/drush dx:delivery-run 1 --skip-pack
vendor/bin/drush dx:delivery-list
```

## 编排步骤

1. 开通租户（`TenantProvisioner`）  
2. 应用 Theme Studio pack + Channel layout profile  
3. 若勾选 app/miniprogram → `pack-tenant-channels.sh`  
4. 移植级别写入验收说明（L1/L2 后续接 Ingest）  
5. 验收报告 JSON 存蓝图实体  

## 冒烟

```bash
./scripts/ci/delivery-smoke.sh
```
