# Open Ecosystem（Phase OE）

> 状态：已确认（`OE 全默认`）· **OE3 完成**（OE1–OE4 主项已落地）  
> 设计（意图）：[open-ecosystem.md](open-ecosystem.md)  
> 索引：[README.md](README.md)

## 模块

| 模块 | 职责 |
|------|------|
| `dx_ecosystem` | DX-RAL / DPA、签署审计、**开发者认证状态机**、**伙伴文档门禁**、个人注册开关 |
| `dx_appstore` | catalog `license_family` / `source_policy`；安装确认；license.agreement_version |
| `dx_platform` | `tenant_kind`（gov/enterprise/industry/personal） |

## 入口

| 路径 | 说明 |
|------|------|
| `/dx/api/docs` | **公开** DXEP OpenAPI（Swagger UI） |
| `/dx/api/openapi.yaml` | DXEP v1 YAML |
| `/dx/ecosystem/agreements` | 协议列表（公开） |
| `/dx/ecosystem/agreements/dx_ral` | DX-RAL 正文 |
| `/dx/ecosystem/agreements/dpa` | DPA 正文 |
| `/dx/ecosystem/partner` | **L2 伙伴文档目录**（需认证） |
| `/dx/ecosystem/partner/{doc_id}` | 伙伴文档正文 |
| `/admin/dx/ecosystem/dpa` | 开发者签署 DPA → `pending` |
| `/admin/dx/ecosystem/developers` | 平台认证 / 吊销 |
| `/admin/dx/ecosystem/settings` | `personal_registration_enabled`（默认关） |
| `/appstore/request/{app}` | 安装申请须勾选 DX-RAL |

## 认证状态机（OE2 / O5-A）

```
none → (签 DPA) → pending → (平台审核) → certified
                         ↘ revoked
```

L2 访问条件：`certified` + 当前 DPA 版本已签署 + `access dx partner vault`（管理员 `administer dx ecosystem` 可旁路）。

## Drush

```bash
vendor/bin/drush pm:enable dx_ecosystem -y
vendor/bin/drush updatedb -y
vendor/bin/drush dx:ecosystem-agreements
vendor/bin/drush dx:ecosystem-partner-docs
vendor/bin/drush dx:ecosystem-sign-dpa --uid=2
vendor/bin/drush dx:ecosystem-certify --uid=2 --note=review-ok
vendor/bin/drush dx:ecosystem-revoke --uid=2
vendor/bin/drush dx:ecosystem-status --uid=2
vendor/bin/drush dx:ecosystem-publish-l0 --dest=/tmp/drupalx-l0-public
vendor/bin/drush dx:appstore-seed
vendor/bin/drush dx:appstore-approve 1 --accept-dx-ral
```

## 冒烟

```bash
./scripts/ci/ecosystem-smoke.sh
./scripts/ci/l0-publish-smoke.sh
```

## 未完成（后续波次）

| 项 | Phase |
|----|-------|
| 私有 Composer/Git 凭证发放（可选） | OE2 可选 |
| 个人注册产品开关（O6-B，默认仍关闭） | 后续 |

OE3 L0 导出见 [public-framework.md](public-framework.md)；OE4 `tenant_kind` 已贯通。
