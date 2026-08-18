# DrupalX 架构说明

产品主叙事（交钥匙交付）见 [turnkey-delivery.md](turnkey-delivery.md)；确认后将增加 Delivery 编排层（Blueprint → provision / theme / migrate / appstore / channel）。

标准数据接口与交换（DXEP：Channel / Ingest / Exchange）见 [data-exchange.md](data-exchange.md)。Channel 读网关模块 `dx_channel` 已实现 `site` 与 `app-layout`（见 [channel.md](channel.md)）；Flutter 壳见 [flutter-shell.md](flutter-shell.md)。

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
- 可优先使用 `drupal/ai` 1.4 标准 Provider 管理器与统一模型配置；
  Provider 调用失败后回退 DrupalX OpenAI-compatible 链
- 系统提示词、failover、月度配额
- 平台环境变量 `DX_AI_{PROVIDER}_KEY` 作为共享密钥默认值；各站点 State
  可安全覆盖或清除覆盖后回退
- 租户可在公司设置中启用独立月度配额；未启用时继承 AI 网关默认值，
  配额 `0` 可停用该租户的 AI 请求
- `/dx/ai/chat/stream` 以 SSE 转发 OpenAI-compatible 流式增量；浏览器保留最近
  20 条对话作为多轮上下文，服务端限制上下文总长度为 16,000 字符
- 租户企业资料与最多 10 个已发布产品以受限知识上下文注入
- 用量写入 `dx_ai_usage` 流水表；并发请求通过带过期回收的
  `dx_ai_quota_reservation` 短期预留配额
- 访客入口：`/ai/chat`；管理：`/admin/dx/ai-gateway`
- Drush：`dx:ai-test` · `dx:ai-usage` · `dx:ai-keys-from-env`

## 统一登录

`dx_auth`：一个入口多种方式（企业信用代码、邮箱自动注册、微信、短信、Google）。说明与验收见 [auth.md](auth.md)。

## 开源生态公开面（L0）

白名单 [public-framework.md](public-framework.md) / [l0-whitelist.yml](l0-whitelist.yml) / [visibility.yml](visibility.yml)。发布脚本 `scripts/publish-l0-tree.sh`。公开 API：`/dx/api/docs`（契约 [openapi/dxep-v1.yaml](openapi/dxep-v1.yaml)）。L2 伙伴文档仍走 `/dx/ecosystem/partner` 金库门禁。

## App Store

实体：

- `dx_app_package` — 策展应用
- `dx_install_request` — 安装申请
- `dx_license` / `dx_revenue_share` — 许可与分成骨架

仅允许启用已进入 Composer 锁定 + 白名单的模块。

## Theme Studio（门面）

Shell 主题仍为 `dx_portal_theme`；策展视觉/交互 packs 由模块 `dx_theme` 一键切换（白标色叠在当前 pack 之上）。详见 [theme-studio.md](theme-studio.md)。

