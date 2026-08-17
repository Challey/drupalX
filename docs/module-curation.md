# Drupal App Store 策展规范

> 开源分层与 DX-RAL（应用对用户开源、禁止第四方再开放）见 [open-ecosystem.md](open-ecosystem.md)（待确认）。

## 准入原则

1. **可复现**：必须有 Composer 包名，进入平台 `composer.lock`
2. **可信分级**
   - `security`：Drupal.org Security Advisory 覆盖
   - `stable`：有稳定发行版且维护活跃
   - `community`：可用但维护弱 / 无 SA（中国场景常见）— 默认不自动安装
3. **中国常用**：`china_common: true` 便于筛选（社交、OSS、支付等）
4. **源码策略（OE 确认后强制）**：上架包须声明 `license_family` / `source_policy`；对用户开放源码但禁止第四方扩散（DX-RAL）；完整内部库仅认证开发者可见

## 分类

| category | 说明 |
|----------|------|
| ai | 大模型与智能客服 |
| social | 社交分享 / 微信相关 |
| oss | 对象存储 / 缓存 / 基础设施 |
| commerce | 电商 |
| marketing | 营销表单 / SEO |
| utility | 通用增强 |

## 上架字段

见 `web/modules/custom/dx_appstore/data/catalog.yml`：

- `machine_name`, `label`, `category`, `project_url`
- `trust_level`, `china_common`, `price`, `revenue_share_percent`
- `composer_package`, `module_name`, `description`

## 分成模型（MVP）

- 默认开发者分成 65–75%（按条目配置）
- `dx_revenue_share` 记录应付账单；结算与支付渠道留待后续迭代

## 禁止事项

- 未经审核向租户动态 `composer require` 任意包
- 将无 SA 的 `community` 包设为默认自动安装

## 目录筛选

- `/appstore?trust=security,platform`
- `/appstore?policy=gov`：套用 `dx_trust` 允许 tiers
