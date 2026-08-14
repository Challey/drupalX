# DrupalX — 中小企业 AI 数字门户平台

基于 **Drupal 11** 的混合 SaaS 底座：平台控制台 + 租户独立门户、多模型 AI 网关、Drupal App Store 雏形。

## 架构要点

- **1C 混合 SaaS**：共享代码库，控制台与每个企业租户使用**独立 MySQL 数据库**
- **本机 MySQL**：通过 `.env` 配置（默认主机 `192.168.16.1` / `127.0.0.1`）
- **Multisite**：`web/sites/sites.php` 映射 `{tenant}.drupalx.local` → `sites/{tenant}/`

详见 [docs/architecture.md](docs/architecture.md)。

## 环境要求

- PHP 8.3+（已验证 8.5）+ 扩展：`pdo_mysql`、`mysqli`、可选 `redis`
- Composer 2.x
- 本机 MySQL 8.x（账号需具备 `CREATE DATABASE` 权限）
- Drush 13（已随 Composer 安装）

## 快速开始

```bash
cd /home/wwwroot/drupalX
cp .env.example .env   # 填写 DX_DB_* 等
./scripts/bootstrap.sh
```

开通演示租户：

```bash
./scripts/provision-tenant.sh demo --label="Demo SME" --mail=demo@example.com
vendor/bin/drush dx:appstore-seed
```

### 本地访问

将 Web 服务器文档根指向 `web/`，并配置：

| 主机 | 站点 |
|------|------|
| `platform.drupalx.local` 或默认 | 控制台 `sites/default` |
| `demo.drupalx.local` | 租户门户 `sites/demo` |

也可临时：

```bash
# 控制台
cd web && php -S platform.drupalx.local:8080 .ht.router.php

# 租户（另开终端，依赖 sites.php）
cd web && php -S demo.drupalx.local:8081 .ht.router.php
```

默认管理员（可在 `.env` 修改）：`admin` / `admin`

## 自定义模块

| 模块 | 说明 |
|------|------|
| `dx_platform` | 租户实体、开通命令、控制台仪表盘 |
| `dx_tenant` | 租户公司设置 |
| `dx_portal` | 产品 / 公司 / 媒体内容类型与门户页 |
| `dx_ai_gateway` | 多模型网关（OpenAI / DeepSeek / 通义 / 智谱）+ 客服聊天块 |
| `dx_appstore` | 可信模块目录、安装申请、许可与分成实体 |
| `dx_pay` | 微信 / 支付宝结账骨架 + `/store` 商城 |
| `dx_oss` | 阿里云 OSS / 腾讯云 COS 一键启用包 |
| `dx_xmt_bridge` | XMT claim / trusted-content 桥接 |

## 常用 Drush

```bash
vendor/bin/drush dx:tenant-list
vendor/bin/drush dx:tenant-provision acme --label="Acme" --mail=a@acme.com
vendor/bin/drush dx:appstore-seed
vendor/bin/drush --uri=http://demo.drupalx.local status
```

## AI 配置

1. 在租户或控制台启用 `key`、`ai`、`ai_provider_openai`（可选；网关可纯 HTTP）
2. 于 `/admin/dx/ai-gateway` 选择默认模型、failover、流式/知识库开关
3. 将各厂商 API Key 写入 State，或通过 `drush dx:ai-keys-from-env`
4. 租户可在 `/admin/dx/tenant` 覆盖配额与密钥
5. 门户主题已放置「AI Customer Service」区块（`dx_customer_service_chat`）；支持 SSE 与多轮会话

```bash
vendor/bin/drush dx:ai-test deepseek
vendor/bin/drush dx:ai-usage
vendor/bin/drush dx:ai-knowledge
```


## App Store

- 目录页：`/appstore`
- 管理：`/admin/dx/appstore/packages`
- 策展规范：[docs/module-curation.md](docs/module-curation.md)

## 路线图

见 [docs/roadmap.md](docs/roadmap.md)。

## 安全说明

- **切勿提交 `.env`**（已在 `.gitignore`）
- 生产环境请更换默认管理员密码，并收紧 `trusted_host_patterns`
