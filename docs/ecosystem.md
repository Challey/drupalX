# Open Ecosystem（Phase OE）

> 状态：已确认（`OE 全默认`）· OE1 进行中  
> 设计：[open-ecosystem.md](open-ecosystem.md)

## 模块

| 模块 | 职责 |
|------|------|
| `dx_ecosystem` | DX-RAL / DPA 文本、签署审计、个人注册开关 |
| `dx_appstore` | catalog `license_family` / `source_policy`；安装确认；license.agreement_version |
| `dx_platform` | `tenant_kind`（gov/enterprise/industry/personal） |

## 入口

| 路径 | 说明 |
|------|------|
| `/dx/ecosystem/agreements` | 协议列表 |
| `/dx/ecosystem/agreements/dx_ral` | DX-RAL 正文 |
| `/dx/ecosystem/agreements/dpa` | DPA 正文 |
| `/admin/dx/ecosystem/dpa` | 开发者签署 DPA |
| `/admin/dx/ecosystem/settings` | `personal_registration_enabled`（默认关） |
| `/appstore/request/{app}` | 安装申请须勾选 DX-RAL |

## Drush

```bash
vendor/bin/drush pm:enable dx_ecosystem -y
vendor/bin/drush updatedb -y
vendor/bin/drush dx:ecosystem-agreements
vendor/bin/drush dx:ecosystem-sign-dpa
vendor/bin/drush dx:ecosystem-status
vendor/bin/drush dx:appstore-seed
vendor/bin/drush dx:appstore-approve 1 --accept-dx-ral
```

## 冒烟

```bash
./scripts/ci/ecosystem-smoke.sh
```
