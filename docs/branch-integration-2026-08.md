# 分支集成记录（2026-08-30）

> 一次性把 17 个本地 + 5 个远端未合并分支并入 `master`。  
> 规则来自用户拍板：**不冲突的就合并，有冲突的按最新的来**，并对现网保护项加一层守卫。  
> 回滚点：`git tag pre-merge-20260830`（= 合并前 master `2945234`）。  
> 集成线：`integration/all-branches`（17 个合并提交），落地后 master 快进。

可见性：`internal`（含现网实现细节与分支评价，不进 L0 公开树）。

---

## 1. 规则

| 编号 | 规则 | 应用范围 |
|------|------|----------|
| R1 | 无冲突 → `--no-ff` 直接合并 | 全部分支先行自动判定 |
| R2 | 有冲突 → 比较两侧「最后修改该文件的提交时间」，取更新一侧；同时间取 master | 非保护、非文档文件 |
| R3 | 现网保护项冲突一律保留 master；且合并结束后把保护路径整体钉回合并前状态（分支新增的重复类/实体一并丢弃） | `dx_auth` `dx_payment` `dx_ai_gateway` `dx_ecosystem` `dx_delivery` `dx_migrate`、门户主题的 `css/login.css` `js/login.js` `js/portal.js` `includes/login_i18n.php` `templates/page--user--login.html.twig` `templates/page--ai--chat.html.twig` `templates/includes/dx-legal-footer.html.twig` `css/skins/**`、`setup/nginx/**` `setup/ha/**` |
| R4 | `docs/**`、`README.md`、Skill 文档：保留 master 正文，分支侧的索引/链接/标题订正在本阶段二手工并入 | 22 处文档冲突 |

工具：`scripts/ops/integrate-branches.sh`（R1–R4）、`scripts/ops/guard-protected-paths.sh`（R3 钉回）、`scripts/ci/merge-integrity-check.php`（合并后结构体检）。

---

## 2. 分支处置表

「净落地」= 该合并提交相对集成线引入的文件数；R3 后续钉回会再清零部分文件。

| 分支 | 结果 | 冲突 | 净落地 | 说明 |
|------|------|------|--------|------|
| `cursor/android-location-bae0` | R1 干净 | 0 | 22 | 跑车助手 Android 壳加固（位置权限、语音/拍照、launcher 图标、WebView 滚动）+ `topstar_app_pay` 共享支付模块 12 文件 |
| `cursor/app-wechat-pay-bae0` | 祖先链已并 | 0 | 0 | 唯一提交 `8a00f1e` 已在上一条线内 |
| `cursor/ab-failover-routing-e280` | R1 干净 | 0 | 1 | `docs/domain-cutover.md` 生产 DNS TTL / 回滚记录；HA 应用代码命中 R3 未取 |
| `cursor/domain-www-apex-drupalx-16b9` | 链上并入 | 3 | 0 | nginx www/短闻模板与切流文档 master 已有更新版（`8d6e799` 之后的现网版本） |
| `cursor/portal-beian-footer-16b9` | R2/R3 | 4 | 0 | 备案页脚 twig 命中 R3；`docs/domain-cutover.md` 取 master |
| `cursor/portal-zhupao-name-16b9` | R1 干净 | 6 | 5 | 中文产品名「助跑」→ `dx_portal` 文案 + 用户协议页 `dx-portal-agreement.html.twig` 与新路由 |
| `cursor/portal-zhupao-type-16b9` | R2/R3 | 8 | 0 | 品牌字号：`style.css` 走链尾，主题 `libraries.yml`/`page*.twig` 判定取 master |
| `cursor/portal-zhupao-align-16b9` | R1 干净 | 4 | 6 | `style.css` 品牌排版对齐 + 主题 `info.yml` 名称/描述 + `.theme` 站点名默认值与 slogan 变量 |
| `cursor/portal-mobile-header-16b9` | 链上并入 | 8 | 0 | 移动端汉堡头已由 master 模板提供，本分支补的是 `style.css`（随 align 一并入） |
| `cursor/foreign-issuance-payment-770e` | R2/R3 | 11 | 2 | 收银台/支付网关命中 R3（master 的 `PaymentGateway` 更新）；仅 `docs/roadmap.md`、`style.css` 增量 |
| `cursor/enterprise-id-login-4508` | R2/R3 | 29 | 7 | `dx_auth` 企业 ID 登录全套命中 R3（master 已含）；净落 `credit_code`：`Tenant` 实体字段 + `dx_tenant` 配置/模式/表单 + `docs/enterprise-login.md` |
| `origin/cursor/x-login-methods-5e78` | R2/R3 | 34 | 7→0 | `dx_auth` 与登录资源命中 R3；其新增 `AuthProvidersForm.php` / `WeChatAuthService.php` / 2 个 Unit 测试被 R3 钉回丢弃（与 master 的 `AuthProviderSettingsForm` / `WechatAuthService` 重名同责） |
| `origin/cursor/x-login-polish-e7c1` | R2/R3 | 30 | 8 | `EnterpriseAuthController`、`EnterpriseAccountLinker` 改动命中 R3 后回退；`TenantSettingsForm.php` 按 R2 取分支版（较新，补企业 ID 绑定字段） |
| `cursor/x-login-continue-611f` | R2/R3 | 32 | 11 | 链尾：微信/阿里云短信/Google 登录接线在 master 已有；净落 recipes 安装 `dx_auth`、`TenantProvisioner` 租户启用 `dx_auth` + `dx_portal_theme`、`docs/DEPLOY.md` 段、AI 聊天页模板 |
| `cursor/restore-ai-chat-style-16b9` | R3 记录合并 | 4 | 0 | AI 聊天样式已在 master（`0e44e01`）；用 `-s ours` 记合并关系不留悬空分支 |
| `cursor/x-pack-miniprogram-103b` | R2/R4 | 2 | 1 | README 出包小节 |
| `cursor/x-pack-android-103b` | R2/R4 | 6 | 1 | README 增量；`x-pack-android.sh`、manifest、`MainActivity.java` 判定取 master（R2 时 master 更新） |
| `cursor/x-pack-skill-docs-103b` | R2/R4 | 3 | 2 | `docs/skills/README.md` + README 索引 |
| `origin/cursor/dx-delivery-mvp-2f8e` | R2/R3 | 14 | 7→0 | 分支版 `Blueprint`/`DeliveryRun` 实体与 master 的 `DeliveryBlueprint` 同时声明 `dx_blueprint` 实体类型 → 必崩，整组被 R3 钉回丢弃 |
| `origin/cursor/www-deliver-l3-handoff-6b02` | R2/R3/R4 | 9 | 11 | `scripts/ci/www-deliver-smoke.sh`（前端 CTA 门禁）+ 交付台文档；`delivery.css`/`DeliveryOrchestrator` 命中 R3 |
| `origin/cursor/turnkey-continue-e2e-49e4` | R2/R3/R4 | 8 | 16 | `clients/flutter_shell` 组件注册、小程序 `index.wxml`/`dxep.js` 同构修复、`clients-isomorph-smoke.sh`/`desk-smoke.sh` 扩展、`delivery-todos-smoke.sh`（已重写对齐 live API） |
| `cursor/docs-organize-0110` | R4 | 22 | 2 | `docs/README.md` 分层索引（本次文档整理基线）+ `docs/DEPLOY.md` 段 |

未合并清单在阶段一结束时应为空：`git branch --no-merged master` 与 `git branch -r --no-merged origin/master` 均为空。

---

## 3. 合并后体检

| 项 | 结果 |
|----|------|
| 保护路径与合并前 master 差异 | 0（`css/style.css`、`info.yml`、`.theme` 属非保护主题文件，见下节复核项） |
| `php -l` / `bash -l` 变更文件 | 全部通过 |
| 冲突标记残留扫描 | 无 |
| `scripts/ci/merge-integrity-check.php` | `OK: 0 duplicate(s)`（实体 id / 类名 / 路由名 / 路由路径+方法 / 服务 id）；合并前 master 同为 0，说明未引入新的结构冲突 |
| 净变更 | 65 文件、+4865 / −41（含 `icon.png` 二进制与文档） |
| R3 钉回丢弃的分支新增文件 | 13（见 `guard-dropped` 清单，全部因与 master 既有实现重名同责） |

---

## 4. 复核项（如需回退，逐条点名）

1. **中文品牌名「助跑」进入主题与门户文案**：`dx_portal_theme.info.yml`（`name: DrupalX 助跑 Portal`）、`.theme`（`site_name` 兜底值与新增 `site_slogan` 变量）、`css/style.css`（品牌排版 + `.dx-nav-toggle` 移动端汉堡样式，配套 markup master 已有）。属 `DEV_MEMORY.md` §2 曾标注「助跑首页改版」范畴，因判定为无冲突而并入。回退：`git checkout pre-merge-20260830 -- web/themes/custom/dx_portal_theme/css/style.css web/themes/custom/dx_portal_theme/dx_portal_theme.info.yml web/themes/custom/dx_portal_theme/dx_portal_theme.theme`
2. **新模块 `topstar_app_pay`**（跑车助手等 App 内微信支付桥）：需 `drush pm:enable topstar_app_pay` 才生效，未启用则对现网零影响。
3. **`Tenant` 实体新增 `credit_code` 字段 + `dx_tenant.settings.credit_code`**：落地后需 `drush updatedb`；`TenantProvisioner` 现在为租户站启用 `dx_auth` 并 `theme:enable dx_portal_theme`（保留 `gavias_kiamo` 为默认）。
4. **recipes 安装列表加 `dx_auth`**：仅影响新开租户。
5. **`scripts/ci/delivery-todos-smoke.sh`**：分支原版依赖被丢弃的 `dx_delivery.todo` 服务，已重写为 `dx_delivery.handoff_todos` + `dx:delivery-todo-done <bp> <todo_id>` 的 live 行为校验。

---

## 5. 落地方式

```bash
# 集成验证通过后（本仓库主工作副本即生产 docroot，快进=改线上代码）
git checkout master
git merge --ff-only integration/all-branches
/home/challey/ops/bin/deploy drupalX --pack     # 需单独批准
```

回滚：`git reset --hard pre-merge-20260830`（并同步回退线上包）。
