# DrupalX 架构说明

## 混合 SaaS（1C）

```
┌──────────────────────────┐
│  Control Plane (default) │  DB: dcn_platform
│  dcn_platform / appstore │
└────────────┬─────────────┘
             │ provision
             ▼
┌──────────────────────────┐
│ Tenant site sites/{id}   │  DB: dcn_tenant_{id}
│ dcn_tenant/portal/ai     │  Host: {id}.drupalx.local
└──────────────────────────┘
             │
             ▼
      Shared codebase (Composer / web/)
```

## 开通流程

1. 控制台创建 `dcn_tenant` 实体，或 `drush dcn:tenant-provision`
2. `TenantProvisioner`：
   - PDO 在本机 MySQL 执行 `CREATE DATABASE`
   - 从 `sites/example.tenant/settings.php` 生成 `sites/{id}/settings.php`
   - 写入 `sites/sites.php` 域名映射
   - `drush site:install`（`--sites-subdir`）
   - 启用租户模块与 `dcn_portal_theme`

## 数据隔离

- 每个租户独立数据库与 `files` / `private` 目录
- 模块与主题版本由平台统一 Composer 锁定（可信与可复现）

## AI 网关

`dcn_ai_gateway.gateway`：

- 优先尝试 Drupal AI Provider 管理器（若 `ai` 模块可用）
- 否则对 OpenAI-compatible HTTP `/chat/completions` 直连
- 支持 failover 顺序与月度 token 配额（State 计数）

## App Store

实体：

- `dcn_app_package` — 策展应用
- `dcn_install_request` — 安装申请
- `dcn_license` / `dcn_revenue_share` — 许可与分成骨架

仅允许启用已进入 Composer 锁定 + 白名单的模块。
