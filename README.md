# DrupalX — 政企门户交钥匙一键交付平台

基于 **Drupal 11** 的混合 SaaS 底座：交付台（MVP）+ 平台控制台 + 租户独立门户、多模型 AI 网关、策展半封闭 App Store。

战略方向（**已确认**）：[docs/turnkey-delivery.md](docs/turnkey-delivery.md) · [docs/strategy.md](docs/strategy.md)  
开源生态与受众升级（**已确认**）：[docs/open-ecosystem.md](docs/open-ecosystem.md) · L0 导出：[docs/public-framework.md](docs/public-framework.md) · API：[docs/api/index.html](docs/api/index.html)  
数据接口与交换（**已确认**）：[docs/data-exchange.md](docs/data-exchange.md)（DXEP）  
拍板记录：[docs/decisions.md](docs/decisions.md)  
Channel API：[docs/channel.md](docs/channel.md) · Flutter 壳：[docs/flutter-shell.md](docs/flutter-shell.md)  
统一登录：[docs/auth.md](docs/auth.md)  
打包：`bash scripts/x-pack-flutter.sh` · `bash scripts/pack-tenant-channels.sh` · [docs/flutter-pack.md](docs/flutter-pack.md)  
交钥匙交付台：`/deliver` · [docs/delivery.md](docs/delivery.md)

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
| `dx_auth` | 统一登录（企业ID / 邮箱自动注册 / 微信 / 短信 / Google） |
| `dx_ai_gateway` | 多模型网关（OpenAI / DeepSeek / 通义 / 智谱）+ 客服聊天块 |
| `dx_appstore` | 可信模块目录、安装申请、许可与分成实体 |

## 常用 Drush

```bash
vendor/bin/drush dx:tenant-list
vendor/bin/drush dx:tenant-provision acme --label="Acme" --mail=a@acme.com
vendor/bin/drush dx:appstore-seed
vendor/bin/drush --uri=http://demo.drupalx.local status
```

## AI 配置

1. 在租户或控制台启用 `dx_ai_gateway`（租户 recipe 已自动启用）
2. 在 `/admin/config/ai/providers` 配置标准 Provider 与 Key
3. 于 `/admin/dx/ai-gateway` 可选择标准 Drupal AI Provider / 模型；未启用
   或调用失败时自动回退 DrupalX 默认模型与 failover
4. 在环境变量中设置 `DX_AI_{PROVIDER}_KEY` 作为直连回退链的默认密钥；也可在各站点
   `/admin/dx/ai-gateway` 保存独立覆盖，清除覆盖后自动回退环境密钥
5. 租户可在 `/admin/dx/tenant-settings` 覆盖平台月度配额（设为 `0` 可停用）
6. 门户聊天默认通过 SSE 流式显示，并自动携带最近 20 条多轮会话上下文
7. 企业资料与最多 10 个已发布产品会作为受限知识上下文注入
8. 门户主题已放置「AI Customer Service」区块（`dx_customer_service_chat`）

## App Store

- 目录页：`/appstore` · L3 源码：`/appstore/licenses`
- 管理：`/admin/dx/appstore/packages`
- 策展规范：[docs/module-curation.md](docs/module-curation.md)

## 路线图

见 [docs/roadmap.md](docs/roadmap.md)。

## 安全说明

- **切勿提交 `.env`**（已在 `.gitignore`）
- 生产环境请更换默认管理员密码，并收紧 `trusted_host_patterns`

## Theme Studio（门户门面）

**苹果简约主题** `ent_apple` · 域名切流见 [docs/domain-cutover.md](docs/domain-cutover.md)（www/x → DrupalX；短闻 → news.drupal.org.cn）。

主题是用户第一感知。策展 packs 一键切换：

- 模块 `dx_theme` · 管理 `/admin/dx/themes` · 伙伴 `/dx/themes`
- Packs：`portal` · `slate` · `harbor` · `ember` · `midnight` · `minimal`
- CLI：`drush dx:theme-list|apply|status`
- 文档：[docs/theme-studio.md](docs/theme-studio.md) · 冒烟：`./scripts/ci/theme-smoke.sh`

