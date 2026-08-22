# DEV_MEMORY — DrupalX 开发记忆

> 供新会话快速恢复上下文。详文档见 `docs/`。  
> 更新：2026-08-22 · 仓库：`git@github.com:Challey/drupalX.git` · 工作区：`/home/wwwroot/drupalX`

---

## 1. 项目定位

| 项 | 值 |
|----|-----|
| 产品名 | **DrupalX**（`dx_*` / `DX_*`，非 DrupalCN） |
| 主叙事 | 政企门户 **交钥匙一键交付**（D2-B 向导+对话 MVP） |
| 架构 | 1C 混合 SaaS：共享代码库 + 控制台/租户 **独立 MySQL 库** |
| 生产 | https://www.drupal.org.cn |
| 部署 | `/home/challey/ops/bin/deploy drupalX --pack`（双机 LNMPa） |

---

## 2. 现网保护项（不可回退）

合并/重构时 **必须保留**：

| 功能 | 路径/标识 | 备注 |
|------|-----------|------|
| 统一登录 | `/user/login`（导航「统一登录」） | `dx_auth` + `dx_portal_theme` |
| 登录方式 | 企业ID / 邮箱自动注册 / 微信 / 短信 / Google | **不要**劫持 `/user/register` |
| 绑定页 | `/dx/auth/bindings` | |
| 备案页脚 | 粤ICP备18100076号 | `dx-legal-footer.html.twig` |
| AI 客服 | `/ai/chat` 全宽布局 | `dx_ai_gateway` |
| OSS 皮肤 | `oss_flame` / `oss_base` | |
| 个人注册 | `personal_registration_enabled=false` | O6-A/O6-B **保持关闭** |

**不要合并**的旧分支：旧登录重写、助跑首页改版、会删 OE2 金库的 HA 应用代码、turnkey 编排大改、Gavias 厂商包（本地未跟踪，勿提交）。

---

## 3. 已完成核心功能

### 3.1 底座与平台
- Drupal 11 + Drush 13；`dcn_*` → `dx_*` 命名统一
- 租户开通：`dx_tenant` / `TenantProvisioner` / multisite
- 平台运营：AI 网关、配额、App Store 策展、订阅套餐、运维仪表盘

### 3.2 统一登录（`dx_auth`）
- 多通道网关 + 邮箱首次登录自动注册 + 冲突归并
- 主题登录页：`page--user--login.html.twig` + `login.js`

### 3.3 交钥匙交付（`dx_delivery`）
- 向导 `/deliver/wizard` + 对话 `/deliver/chat` → Blueprint 编排
- 步骤：开通 → Theme → Channel → trust → 能力启用 → pack → migrate
- L3：`handoff_todos` 人工工单；`/order` 别名 `/deliver`
- 验收 JSON 含 `ops` 手册/API/certs/L3 链接

### 3.4 DXEP 数据交换
- OpenAPI：`docs/openapi/dxep-v1.yaml`；公开文档 `/dx/api/docs`
- `dx_channel`：site / app-layout（**一律 token**，D10-B）
- `dx_migrate`：L1 HTML / L2 字段 + 审核队列
- Exchange 离线 ZIP；Webhook + 死信重试

### 3.5 多端壳
- Flutter 可配置壳 `clients/flutter_shell/` + `x-pack-flutter`
- 微信小程序 `clients/wechat-miniprogram/` + `x-pack-miniprogram`
- Android WebView 壳 `tools/android-packer/`（跑车助手等；微信/支付宝 H5 留 WebView）
- 证书就绪探测 `dx_certs`

### 3.6 行业与信任
- 舆情演示 `dx_opinion`；政务 trust `dx_trust`
- Theme Studio `dx_theme`（政企 packs）
- 支付 `dx_payment` + `topstar_app_pay`（场景检测：App/微信/H5/MWEB）

### 3.7 开源生态 OE（主项已落地）
- **OE1** DX-RAL / DPA、安装确认、`license_family` / `source_policy`
- **OE2** 开发者认证 `none→pending→certified→revoked`；伙伴金库 `/dx/ecosystem/partner`
- **OE2 凭证** L2 token：`/dx/ecosystem/credentials`；`dxl2_` 前缀、SHA-256 哈希、明文一次
- **OE3** L0 白名单导出 + `visibility.yml` 过滤 internal/partner
- **OE4** `tenant_kind`（gov/enterprise/industry/personal，personal 默认关）
- **L3 源码包** `/appstore/licenses/{id}/source` + DX-RAL 水印 + 审计

---

## 4. 关键文件（按域）

| 域 | 路径 | 作用 |
|----|------|------|
| 登录 | `web/modules/custom/dx_auth/` | 统一登录网关、绑定、自动注册 |
| 登录 UI | `web/themes/custom/dx_portal_theme/templates/page--user--login.html.twig` | 登录页与备案页脚 |
| 交付 | `web/modules/custom/dx_delivery/src/Service/DeliveryOrchestrator.php` | 交钥匙编排 + L3 工单 |
| 生态 | `web/modules/custom/dx_ecosystem/` | DPA/认证/伙伴金库/L2 凭证/L0 发布 |
| L2 凭证 | `.../PartnerCredentialStore.php`, `PartnerCredentialForm.php` | 签发/轮换/校验 token |
| L3 源码 | `web/modules/custom/dx_appstore/src/Service/SourceBundleService.php` | zip 打包 + 水印 + 审计 |
| 支付 | `dx_payment/src/Service/PaymentGateway.php`, `ClientDetector.php` | 收银台 + 场景路由 |
| 共享支付 | `web/modules/custom/topstar_app_pay/` | 跑车助手等 live 微信支付桥 |
| Channel | `web/modules/custom/dx_channel/` | DXEP 读 API |
| L0 导出 | `docs/l0-whitelist.yml`, `docs/visibility.yml`, `scripts/lib/l0_publish.php` | 公开树白名单与可见性 |
| OpenAPI | `docs/openapi/dxep-v1.yaml` | DXEP v1 契约 |
| 部署包 | `/home/challey/ops/projects/drupalX/pack-deploy.sh` | 打包 + `pm:enable` 核心模块 |
| Android | `tools/android-packer/template/.../MainActivity.java` | WebView 支付域名白名单 |
| 冒烟 | `scripts/ci/*.sh` | 28 个 CI 冒烟脚本 |

---

## 5. 技术决策与规范

### 5.1 战略拍板（`docs/decisions.md`）
- `D2-B` 向导+对话同属 MVP · `D3-B` Flutter 双端壳 · `D4-A` L3 人工 · `D5-B` 舆情可演示
- `D8-A` DXEP + `/api/dx/v1/` · `D9-A` 不暴露 Drupal JSON:API 原貌 · `D10-B` Channel 一律 token
- OE 全默认：`O1-B…O8-A`；**个人租户产品开关默认关**（O6-A）

### 5.2 生态四层可见性
```
L0 Public Framework  → 白名单导出 + /dx/api/docs
L1 Public Developer  → DXEP / Hooks / 示例
L2 Partner Vault     → 认证 + DPA + /dx/ecosystem/partner + credentials
L3 Tenant Source     → 许可 + DX-RAL 版本 + /appstore/licenses/{id}/source
```

### 5.3 配置项（`dx_ecosystem.settings`）
| 键 | 默认 | 说明 |
|----|------|------|
| `personal_registration_enabled` | `false` | **勿开**（O6-B 后续波次） |
| `require_ral_on_install` | `true` | 商店安装须 DX-RAL |
| `l2_composer_host` | `packages.drupalx.local` | L2 Composer 占位主机 |
| `l2_git_host` | `git.drupalx.local` | L2 Git 占位主机 |

### 5.4 L2 凭证规则
- 仅 `certified` + 当前 DPA 已签 + `access dx partner vault` 可签发
- Token：`dxl2_` + 48 hex；库内只存 SHA-256；轮换覆盖旧 hash；`revoke` 认证同步作废
- Drush：`dx:ecosystem-issue-credential` / `dx:ecosystem-verify-credential`

### 5.5 生产部署约定
```bash
/home/challey/ops/bin/pack drupalX          # 本地打包
/home/challey/ops/bin/deploy drupalX --pack  # 上传双机 + updatedb + cr
```
- pack 脚本 `pm:enable`：`dx_payment dx_oss dx_ecosystem dx_auth dx_delivery`
- 部署后若新模块 404：手动 `drush pm:enable dx_ecosystem`（首次 OE3 曾遇此情况）
- `role:perm:add` 对旧权限名的报错可忽略（历史 perm 名已变）

### 5.6 环境变量（`.env`，不入库）
- `DX_DB_*` / `DX_AI_*` 等见 `.env.example`
- AI 密钥：`drush dx:ai-keys-from-env`

---

## 6. 主要路由速查

| 路径 | 说明 |
|------|------|
| `/user/login` | 统一登录 |
| `/dx/auth/bindings` | 身份绑定 |
| `/ai/chat` | AI 客服 |
| `/deliver` · `/order` | 交钥匙交付台 |
| `/dx/api/docs` | 公开 OpenAPI（Swagger） |
| `/dx/ecosystem/partner` | L2 伙伴文档（403 匿名） |
| `/dx/ecosystem/credentials` | L2 Composer/Git 凭证 |
| `/appstore/licenses` | L3 许可列表（须登录） |
| `/appstore/licenses/{id}/source` | L3 源码 zip 下载 |

---

## 7. 冒烟命令（本地/CI）

```bash
./scripts/ci/ecosystem-smoke.sh
./scripts/ci/l0-publish-smoke.sh
./scripts/ci/l2-credential-smoke.sh
./scripts/ci/l3-source-smoke.sh
./scripts/ci/l3-handoff-smoke.sh
./scripts/ci/delivery-smoke.sh
```

---

## 8. 未完成 / 下一步

| 优先级 | 项 | 说明 |
|--------|-----|------|
| — | **O6-B 个人注册** | 架构已预留 `tenant_kind=personal`；**产品开关保持关** |
| — | 真实私有 Composer/Git 主机 | 当前仅凭证发放 + 占位域名，无 Satis 实例 |
| 低 | Phase DZ/EA/EB 标「进行中」 | 主能力已有，属深化/打磨 |
| 低 | Gavias Kiamo 主题包 | 本地未跟踪 vendor 包，独立升级线 |
| 低 | 部署脚本权限名清理 | `pack-deploy.sh` 中过时 perm 报错 |
| 可选 | AI 密钥配置 | Phase A 验收「填 Key 后可对话」仍待运维配置 |
| 可选 | 证书真实签名 SDK | `dx_certs` 仅就绪探测，签名在 CI |

**建议下一开发切片**（路线图主项已勾完）：
1. 交钥匙交付深化（蓝图 UI、L3 工单运营流）
2. 迁移 L2 审核与 Exchange 生产化
3. 跑车助手 Android 壳迭代（当前分支 `cursor/android-location-bae0` 有未提交 launcher/滚动修复）

---

## 9. Git / 分支备忘

- 主开发线：`master`（已 push `origin/master`）
- 近期关键提交：`55b3dd7` L2 凭证 + visibility · `9f433b6` L3 源码包 + handoff · `5dd7770` OE3 L0
- 后续：`8d6e799` 支付 WebView · `8a00f1e` topstar_app_pay · Android 壳 1.2.x 系列
- **未跟踪勿提交**：`gavias_*` / `features_kiamo/` / `gavias_kiamo` 主题

---

## 10. 文档索引

| 文档 | 内容 |
|------|------|
| `docs/roadmap.md` | 全阶段勾选状态 |
| `docs/decisions.md` | 战略拍板单 |
| `docs/ecosystem.md` | OE 入口与 Drush |
| `docs/open-ecosystem.md` | 四层模型与设计意图 |
| `docs/delivery.md` | 交钥匙交付台 |
| `docs/auth.md` | 统一登录行为 |
| `docs/data-exchange.md` | DXEP 字段与错误码 |
| `docs/public-framework.md` | L0 导出 |
