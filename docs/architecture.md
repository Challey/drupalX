# DrupalX 架构说明

## 混合 SaaS（1C）

```
┌──────────────────────────┐
│  Control Plane (default) │  DB: dx_platform
│  dx_platform / appstore │
└────────────┬─────────────┘
             │ provision
             ▼
┌──────────────────────────┐
│ Tenant site sites/{id}   │  DB: dx_tenant_{id}
│ dx_tenant/portal/ai     │  Host: {id}.drupalx.local
└──────────────────────────┘
             │
             ▼
      Shared codebase (Composer / web/)
```

## 开通流程

1. 控制台创建 `dx_tenant` 实体，或 `drush dx:tenant-provision`
2. `TenantProvisioner`：
   - PDO 在本机 MySQL 执行 `CREATE DATABASE`
   - 从 `sites/example.tenant/settings.php` 生成 `sites/{id}/settings.php`
   - 写入 `sites/sites.php` 域名映射
   - `drush site:install`（`--sites-subdir`）
   - 启用租户模块与 `dx_portal_theme`

## 数据隔离

- 每个租户独立数据库与 `files` / `private` 目录
- 模块与主题版本由平台统一 Composer 锁定（可信与可复现）

## AI 网关

`dx_ai_gateway.gateway`：

- OpenAI-compatible HTTP `/chat/completions`（DeepSeek / 通义 / 智谱 / OpenAI）
- 可选回退到 Drupal `ai.provider`（若模块可用）
- 系统提示词、failover、月度配额
- 平台环境变量 `DX_AI_{PROVIDER}_KEY` 作为共享密钥默认值；各站点 State
  可安全覆盖或清除覆盖后回退
- 租户可在公司设置中启用独立月度配额；未启用时继承 AI 网关默认值，
  配额 `0` 可停用该租户的 AI 请求
- `/dx/ai/chat/stream` 以 SSE 转发 OpenAI-compatible 流式增量；浏览器保留最近
  20 条对话作为多轮上下文，服务端限制上下文总长度为 16,000 字符
- 用量写入 `dx_ai_usage` 表 + State 计数
- 访客入口：`/ai/chat`；管理：`/admin/dx/ai-gateway`
- Drush：`dx:ai-test` · `dx:ai-usage` · `dx:ai-keys-from-env`

## App Store

实体：

- `dx_app_package` — 策展应用
- `dx_install_request` — 安装申请
- `dx_license` / `dx_revenue_share` — 许可与分成骨架

仅允许启用已进入 Composer 锁定 + 白名单的模块。
