# 政务信任策略（`dx_trust`）

> Phase EB：政务租户默认收紧 App Store trust tier。  
> 策展规范：[module-curation.md](module-curation.md)

## 默认档案

| Profile | 允许 tiers | community |
|---------|------------|-----------|
| `government_default` | platform / security / curated / demo | 阻断自动安装 |
| `enterprise_default` | + stable + community | 须人工批准 |

## 入口

- 管理：`/admin/dx/trust`
- 交付台：政府蓝图自动 `dx:trust-apply government`；企业蓝图用 enterprise

## Drush

```bash
vendor/bin/drush pm:enable dx_trust -y
vendor/bin/drush dx:trust-apply government
vendor/bin/drush dx:trust-status
vendor/bin/drush dx:trust-check community
```

## 商店门禁

`dx_appstore` 安装审批在启用 `dx_trust` 时调用 `TrustPolicy::evaluate()`；拒绝则请求标记 `rejected`。

## 冒烟

```bash
./scripts/ci/trust-smoke.sh
```
