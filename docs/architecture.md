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
- 可选优先走 Drupal `ai.provider`（ChatInput / ChatMessage；失败回退 HTTP）
- 系统提示词、failover、月度配额
- **租户覆盖**：`dx_tenant.settings` 的 `ai_quota_override` / `ai_keys_override`（租户密钥 State：`dx_ai_gateway.tenant_api_keys.*`）
- **多轮会话**：PrivateTempStore + `session_id`；`max_history_turns`
- **SSE 流式**：`POST /dx/ai/chat/stream`（`enable_streaming`）
- **知识库**：注入已发布 `dx_product` 摘要与公司资料（`inject_knowledge_base`）
- 用量写入 `dx_ai_usage` 表 + State 计数
- 访客入口：`/ai/chat`；管理：`/admin/dx/ai-gateway`
- Drush：`dx:ai-test` · `dx:ai-usage` · `dx:ai-keys-from-env` · `dx:ai-knowledge`

## App Store

实体：

- `dx_app_package` — 策展应用
- `dx_install_request` — 安装申请
- `dx_license` / `dx_revenue_share` — 许可与分成骨架

仅允许启用已进入 Composer 锁定 + 白名单的模块。
