# DEV_MEMORY — DrupalX 开发记忆

> 供新会话快速恢复上下文。详文档见 `docs/`。  
> 更新：2026-08-30（分支全部并入 master）· 仓库：`git@github.com:Challey/drupalX.git` · 工作区：`/home/wwwroot/drupalX`

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

**历史上「先别合」的旧分支已于 2026-08-30 一次性收完**（`M1-A`）：不冲突直接并、冲突取更新一侧、
现网保护项钉回 master。逐分支结果与回退命令见 [docs/branch-integration-2026-08.md](docs/branch-integration-2026-08.md)。

**仍不得提交**：`gavias_*` / `gaviasthemer` / `gva_blockbuilder` / `features_kiamo`（厂商包，已进 `.gitignore`，见 `M2-A`）。
**不得直接改主工作副本**：它就是你生产 docroot（`root /home/wwwroot/drupalX/web`）；开发在 `.worktrees/` 里做（见 `M3-A`）。

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
| 共享支付 | `web/modules/custom/topstar_app_pay/` | 跑车助手等 live 微信支付桥（需 `pm:enable`） |
| 租户字段 | `dx_platform/src/Entity/Tenant.php`（`credit_code`） | 统一社会信用代码，组内改需 `drush updatedb` |
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

## 8. 未完成 / 下一步（2026-08-30 刷新）

> 路线主项已勾完；以下按**并行六线**组织，详任务卡见 [docs/roadmap.md](docs/roadmap.md) Phase F/G/H/I/R/Q。

| 线 | 波次 | 下一步 |
|----|------|--------|
| L1 | Phase F 交付运营化 | 蓝图列表四态分区 · `/deliver/todos` 工单看板 · `dx:delivery-todo-done --batch` · 验收报告 v3 |
| L2 | Phase G 迁移与交换 | L2 映射模板可配置 · 审核队列批量 · Exchange 包 SHA-256 校验 · Webhook 真实 endpoint UI |
| L3 | Phase H 多端出包 v2 | Android 壳 1.3.0（已并定位/语音/图标，待回归）· Flutter 组件目录 v2 · 小程序同构扩展 · manifest schema 三端对齐 |
| L4 | Phase I 真实 L2 仓库 | Satis/私有 Composer 生成层 + `dxl2_` 校验中间件 · 凭证审计报表 · L0 发布接 CI |
| L5 | Phase R 登录与门面回归 | **只加测试与文档**：五通道回归 · 绑定页边界 · `dx:ai-status` 报表 · OSS 皮肤断言 |
| L6 | Phase Q 质量与 CI | `run-all.sh`（已建）· `merge-integrity-check.php`（已建）· phpunit runner（现无） |

运维待办（不属开发线）：

| 优先级 | 项 | 说明 |
|--------|-----|------|
| 高 | 本次合并落地 | `git merge --ff-only integration/all-branches` 后 `deploy drupalX --pack` + `drush updatedb`（`credit_code` 新字段）+ `pm:enable topstar_app_pay` |
| 中 | AI 密钥 | Phase A 验收「填 Key 后可对话」仍待运维配置（`drush dx:ai-status` 可查） |
| 低 | 个注开关 | O6-B 架构已留 `tenant_kind=personal`，**产品开关保持关** |
| 低 | 部署脚本权限名 | `pack-deploy.sh` 旧 perm 报错可忽略 |
| 可选 | 证书真实签名 SDK | `dx_certs` 仅就绪探测，签名在 CI |

**建议下一开发切片**：L1 工单看板 + L2 审核队列批量（两者都不碰现网登录/主题）。

---

## 9. Git / 分支备忘

- 主开发线：`master`；**2026-08-30 后无 dangling 分支**（17 本地 + 5 远端全并完）
- 回滚点：`git tag pre-merge-20260830`（= 合并前 master `2945234`）
- 集成线：`integration/all-branches`（17 个合并提交），工具 `scripts/ops/integrate-branches.sh` + `scripts/ops/guard-protected-paths.sh`
- 并行开发：`.worktrees/lane-<线>` + `lane/<名>` 分支，**文件所有权互斥**；每轮先 `rebase master` 再串行 `--no-ff` 回收
- 每次合并后必跑：`php scripts/ci/merge-integrity-check.php`（防重复实体 id / 类名 / 路由 / 服务 id）
- **未跟踪勿提交**：`gavias_*` / `gaviasthemer` / `gva_blockbuilder` / `features_kiamo`（已在 `.gitignore`）

---

## 10. 文档索引

完整分层索引见 [docs/README.md](docs/README.md)。高频入口：

| 文档 | 内容 |
|------|------|
| `docs/roadmap.md` | 全阶段勾选状态 + Phase F–Q 任务卡 |
| `docs/decisions.md` | 战略拍板单（D / F / O / **M**） |
| `docs/branch-integration-2026-08.md` | 分支处置表 + 合并规则 + 回退命令 |
| `docs/ecosystem.md` | OE 入口与 Drush |
| `docs/open-ecosystem.md` | 四层模型与设计意图 |
| `docs/delivery.md` | 交钥匙交付台 |
| `docs/auth.md` | 统一登录行为 |
| `docs/enterprise-login.md` | 企业 ID（统一社会信用代码）登录 |
| `docs/data-exchange.md` | DXEP 字段与错误码 |
| `docs/public-framework.md` | L0 导出 |

---

## 11. 本次分支集成结论（2026-08-30）

| 项 | 结果 |
|----|------|
| 规责 | `M1-A`：R1 不冲突直并 · R2 冲突取更新一侧 · R3 现网保护项钉回 master · R4 文档保留 master 正文 |
| 合并提交 | 17（覆盖 22 个分支引用，含本地与远端同名） |
| 净变更 | 65 文件 / +4865 / −41 |
| R3 钉回丢弃 | 13 个分支新增文件（与 master 既有实现重名同责，如重复声明 `dx_blueprint` 实体的 `Entity/Blueprint.php`） |
| 体检 | 保护路径零差异 · `php -l`/`bash -n` 全过 · 无冲突标记 · `merge-integrity-check.php` 0 重复 |
| 需你复核的 5 项 | 见 `docs/branch-integration-2026-08.md` §4（主要是「助跑」品牌文案进主题、`topstar_app_pay` 新模块、`credit_code` 字段需 updatedb） |
