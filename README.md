# DrupalX — 政企门户交钥匙一键交付平台

基于 **Drupal 11** 的混合 SaaS 底座：交付台（MVP）+ 平台控制台 + 租户独立门户、多模型 AI 网关、策展半封闭 App Store。

战略方向（**已确认**）：[docs/turnkey-delivery.md](docs/turnkey-delivery.md) · [docs/strategy.md](docs/strategy.md)  
文档索引（先看这里）：[docs/README.md](docs/README.md) · 开发上下文：[DEV_MEMORY.md](DEV_MEMORY.md)  
开源生态与受众升级（**已确认**）：[docs/open-ecosystem.md](docs/open-ecosystem.md) · L0 导出：[docs/public-framework.md](docs/public-framework.md) · API：[docs/api/index.html](docs/api/index.html)  
数据接口与交换（**已确认**）：[docs/data-exchange.md](docs/data-exchange.md)（DXEP）  
拍板记录：[docs/decisions.md](docs/decisions.md)（含 `M1` 分支集成 · `M3` 多线并行）  
Channel API：[docs/channel.md](docs/channel.md) · Flutter 壳：[docs/flutter-shell.md](docs/flutter-shell.md)  
统一登录：[docs/auth.md](docs/auth.md) · 企业 ID 登录：[docs/enterprise-login.md](docs/enterprise-login.md)  
打包：`bash scripts/x-pack-flutter.sh` · `bash scripts/pack-tenant-channels.sh` · [docs/flutter-pack.md](docs/flutter-pack.md)  
交钥匙交付台：`/deliver` · `/order` · [docs/delivery.md](docs/delivery.md)

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

## 核心工具 · 微信小程序打包

将已登记应用打成可导入微信开发者工具的小程序包（X 项目核心能力）：

```bash
bash scripts/x-pack-miniprogram.sh --list
bash scripts/x-pack-miniprogram.sh --app=car_hailing_assistant --api-base=https://www.topstar.run
```

完整文档：[docs/skills/x-pack-miniprogram.md](docs/skills/x-pack-miniprogram.md) · Agent Skill：`x-pack-miniprogram`

## 核心工具 · Android App 打包

将已登记应用打成 Android Studio WebView 工程（可选再编 APK）：

```bash
bash scripts/x-pack-android.sh --list
bash scripts/x-pack-android.sh --app=car_hailing_assistant --start-url=https://www.topstar.run/driver
```

完整文档：[docs/skills/x-pack-android.md](docs/skills/x-pack-android.md) · Agent Skill：`x-pack-android`

## 自定义模块

| 模块 | 说明 |
|------|------|
| `dx_platform` | 租户实体（含统一社会信用代码）、开通命令、控制台仪表盘 |
| `dx_tenant` | 租户公司设置与配额覆盖 |
| `dx_portal` | 产品 / 公司 / 媒体内容类型、门户页与用户协议页 |
| `dx_auth` | 统一登录（企业ID / 邮箱自动注册 / 微信 / 短信 / Google）与绑定页 |
| `dx_ai_gateway` | 多模型网关（OpenAI / DeepSeek / 通义 / 智谱）+ 客服聊天块 |
| `dx_appstore` | 可信模块目录、安装申请、许可与 L3 源码包 |
| `dx_delivery` | 交钥匙交付台：向导 / 对话 → 蓝图 → 编排 → 验收报告 + L3 工单 |
| `dx_channel` | DXEP 只读 Channel、Ingest、Exchange 批次包、Webhook |
| `dx_migrate` | 旧站移植 L1 HTML / L2 字段 + 导入审核队列 |
| `dx_ecosystem` | DX-RAL / DPA、开发者认证、伙伴金库、L2 凭证、L0 发布 |
| `dx_theme` | Theme Studio 门面包（政企 10+ 套皮肤） |
| `dx_trust` | 政务信任档位与商店门禁 |
| `dx_health` | 健康检查与租户健康摘要 |
| `dx_certs` | 证书路径托管与就绪/指纹探测 |
| `dx_opinion` | 舆情演示能力 |
| `topstar_app_pay` | App / 微信内 H5 共享支付桥（跑车助手等） |

厂商主题包（`gavias_*` / `features_kiamo` 等）**不入库**，见 `docs/decisions.md` `M2`。

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

见 [docs/roadmap.md](docs/roadmap.md)（当前并行六线：Phase F / G / H / I / R / Q）。  
历史分支收敛记录（22 分支一次并完）：[docs/branch-integration-2026-08.md](docs/branch-integration-2026-08.md)。

## 冒烟与体检

```bash
./scripts/ci/run-all.sh                       # 全部分组冒烟（存在性检查）
php scripts/ci/merge-integrity-check.php      # 实体 id / 类名 / 路由 / 服务 id 重复体检
```

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

