# DrupalX 路线图

原则：底座能力已齐备；主叙事为 **政企交钥匙一键交付**（已拍板，见 [decisions.md](decisions.md)）。每条可独立验收。

**战略**：[turnkey-delivery.md](turnkey-delivery.md)（已确认） · [strategy.md](strategy.md)  
**开源生态**：[open-ecosystem.md](open-ecosystem.md)（已确认 · Phase OE）  
**数据接口与交换**：[data-exchange.md](data-exchange.md)（DXEP，已确认）  
**多端壳**：[flutter-shell.md](flutter-shell.md)（已确认） · [channel.md](channel.md)  
**交钥匙交付台**：[delivery.md](delivery.md)（Phase DX MVP）  
**分支集成**：[branch-integration-2026-08.md](branch-integration-2026-08.md)（2026-08-30 一次性并完 22 分支）

---

## 开工顺序（2026-08-30 刷新 · Phase F–Q 多线并行）

1. ~~Phase DE Channel 只读 / FS1~~ ✅  
2. ~~FS2–FS5 Flutter 壳 + 打包 + 小程序~~ ✅  
3. ~~DE3 Ingest~~ ✅  
4. ~~Phase DX~~ 交钥匙交付台 MVP ✅ → **深化入 Phase F**  
5. ~~Phase OE1–OE4~~ ✅（含 L2 凭证 / L3 源码包）→ **深化入 Phase I**  
6. **并行六线**（互斥文件所有权，见 [decisions.md](decisions.md) `M3`）：  
   L1 Phase F 交付运营化 · L2 Phase G 迁移与交换生产化 · L3 Phase H 多端出包 v2 ·  
   L4 Phase I 真实 L2 仓库对接 · L5 Phase R 登录与门面回归 · L6 Phase Q 质量与 CI

---

## Phase F — 交付运营化（线 L1）

> 目标：把交付台从「能跑通」推到「运营可持续接手」。**不碰现网登录与主题**。

- [ ] **F1** 蓝图列表分区：草稿 / 已确认 / 已执行 / 失败重试（`/admin/dx/delivery` + Views 配置）  
  验收：`./scripts/ci/desk-smoke.sh` 覆盖四态过滤
- [ ] **F2** L3 工单看板 `/deliver/todos`（基于 `dx_delivery.handoff_todos`，不改 `HandoffTodoService` 签名）  
  验收：`./scripts/ci/delivery-todos-smoke.sh` + 新看板路由冒烟
- [ ] **F3** `drush dx:delivery-todo-done --batch` 与工单 SLA 字段（owner / due / 备注）  
  验收：批量完成后再跑一次得幂等结果
- [ ] **F4** 验收报告 v3：ops 手册 / API / certs / L3 链接成块输出，可导出单文件  
  验收：`dx:delivery-export` 产物含四类链接且 JSON 可解析

## Phase G — 迁移与交换生产化（线 L2）

- [ ] **G1** L2 字段映射模板库可配置化（`gov_news` / `ent_article` 之外的行业模板）  
  验收：`dx:migrate-l2 --template=` 载入新模板不改动代码
- [ ] **G2** 审核队列批量操作（批量发布 / 批量丢弃 / 按外部 ID 重放）  
  验收：`migrate-review-smoke.sh` 新增批量路径
- [ ] **G3** Exchange 离线包完整性：SHA-256 校验 + apply 报告分页与失败重试  
  验收：篡改 ZIP 后 apply 拒收并给出错误码
- [ ] **G4** Webhook 真实 endpoint 配置 UI（现为 `fail.example.com` sink）+ 投递健康报表
  验收：`webhook-smoke.sh` 验证重试退避与死信入库

## Phase H — 多端出包 v2（线 L3）

- [ ] **H1** Android 壳 1.3.0：定位/语音/拍照权限回归 + 支付域名白名单可配置化  
  验收：`packer-smoke.sh` + 手工出包构建成功
- [ ] **H2** Flutter 壳组件目录 v2 + 布局引擎单元用例  
  验收：`flutter-shell-smoke.sh` 通过，新组件有 widget 测试
- [ ] **H3** 小程序同构冒烟扩展（L1/L2 数据一致字段清单）  
  验收：`clients-isomorph-smoke.sh` 覆盖新增字段
- [ ] **H4** 出包门禁文档与 `manifest.yml` schema 对齐（三端同一份 schema）  
  验收：`x-pack-{android,miniprogram,flutter}.sh --list` 与文档一致

## Phase I — 真实 L2 仓库对接（线 L4）

- [ ] **I1** 私有 Composer（Satis/artifactory）生成层 + `dxl2_` token 校验中间件（替掉 `packages.drupalx.local` 占位）  
  验收：`l2-credential-smoke.sh` 增加真实拉取回环用例
- [ ] **I2** L2 Git 主机下发与凭证吊销同步（认证作废 → token 失效）  
  验收：撤销后 `dx:ecosystem-verify-credential` 失败
- [ ] **I3** 凭证签发/轮换/使用审计报表（签发者、IP、调用次数、最后使用）  
  验收：`ecosystem-smoke.sh` 断言报表字段存在
- [ ] **I4** L0 公开树发布接 CI（白名单变更 → 自动校验可见性）  
  验收：`l0-publish-smoke.sh` 在无 `.env` 的 CI 环境可运行

## Phase R — 登录与门面回归（线 L5，现网保护）

> 约束：**只加测试与文档，不改行为**。任何行为变更先出变更说明并单独批准。

- [ ] **R1** 五种登录通道回归用例（企业ID / 邮箱首次登录自动注册 / 微信 / 短信 / Google）  
  验收：`l2-credential-smoke.sh` 无关；新增 `auth-smoke.sh` 通过
- [ ] **R2** `/dx/auth/bindings` 边界用例（重复绑定 / 解绑 / 冲突归并）  
  验收：Unit + Kernel 级测试入库
- [ ] **R3** `drush dx:ai-status` 就绪报表输出模型/密钥/配额三元组  
  验收：无密钥环境下输出可解析且提示缺项
- [ ] **R4** OSS 皮肤（`oss_base` / `oss_flame`）变量清单与 `theme-smoke.sh` 断言  
  验收：应用皮肤后关键 CSS 变量不丢失

## Phase Q — 质量与 CI（线 L6）

- [ ] **Q1** `scripts/ci/run-all.sh`：28+ 冒烟脚本分组执行、失败即停、结果汇总  
  验收：本地一次跑完并输出成/败清单
- [ ] **Q2** 合并体检入门禁：`merge-integrity-check.php`（实体 id / 类名 / 路由 / 服务 id 重复）  
  验收：注入重复实体时退出码 1
- [ ] **Q3** PHP 静态扫描（`php -l` 全仓 + 变更文件 `bash -n`）纳入 run-all  
  验收：无 `.env` 依赖，可在 CI 跑
- [ ] **Q4** 单元与 Kernel 测试 harness：引入 `phpunit` 开发依赖 + Drupal Kernel 测试目录约定（现无 runner）  
  验收：`web/modules/custom/*/tests/src/Unit` 可执行

---

## 开工顺序（历史·2026-08-16 版）

1. ~~Phase DE Channel 只读 / FS1~~ ✅  
2. ~~FS2–FS5 Flutter 壳 + 打包 + 小程序~~ ✅  
3. ~~DE3 Ingest~~ ✅  
4. **Phase DX** 交钥匙交付台 ← **深化（L3 交接工单 + `/order`）**  
5. 并行：D5-B 舆情演示方案  
6. **Phase OE** 开源生态 ← **OE3 完成 · L3 源码包下载已落地**  

---

## 多端壳 · Flutter

> 状态：**已确认**（F1–F6 全 A）。按 FS 开发。

### Phase FS — Flutter 可配置壳

- [x] **FS0** 拍板 flutter-shell §14（`F1-A…F6-A`）  
- [x] **FS1** DXEP `app-layout` + `site` + schema/OpenAPI（模块 `dx_channel`）  
- [x] **FS2** Flutter Shell MVP（`clients/flutter_shell/` 组件目录 v1 + 布局引擎）  
- [x] **FS3** Skill / 脚本 `x-pack-flutter`（灌参出工程；iOS 按 F5-A）  
- [x] **FS4** 小程序同构 L1/L2（`clients/wechat-miniprogram/`）  
- [x] **FS5** 交钥匙多端出包脚本（`scripts/pack-tenant-channels.sh`；蓝图验收含运维入口）  

---

## 并行规范 · DXEP 数据接口

> 状态：契约已确认；Channel 读路径 FS1 已实现（`dx_channel`）。

### Phase DE — 标准接口与交换（计划）

- [x] **DE1** 冻结 DXEP v1 字段/错误码 · OpenAPI 草稿（`docs/openapi/dxep-v1.yaml`；D10-B）
- [x] **DE2** Channel 只读 MVP（`dx_channel`：site / app-layout）
- [x] **DE3** Ingest upsert + Channel contents/products（L2）  
- [x] **DE4** Exchange 批次包 apply + 报告（JSON + 离线 ZIP `package.json`；download/export）  
- [x] **DE5** Webhook 出站 + HTTP 管理 + 死信重试（fail.example.com sink）+ Channel API 审计/限流

---

## 主线 · 交钥匙

> 状态：战略已确认；**Phase DX MVP 已落地**（见 [delivery.md](delivery.md)）。

### Phase DX — 交付台 MVP（含对话）

> 拍板 D2-B：向导 + 对话同属 MVP（原 DY 并入）。

- [x] Blueprint 实体与确认页（`dx_delivery` / `dx_blueprint`）
- [x] 页面选型向导（`/deliver/wizard`）
- [x] 需求对话 → 蓝图草稿（启发式 + 可选 AI 网关）
- [x] 确认执行 + 验收报告 v1
- [x] 验收报告 v2 UI（步骤清单 / 摘要）
- [x] 健康检查步骤（`dx_health`）
- [x] 编排：开通 → Theme → Channel layout → 能力启用 → 可选 pack → L1 migrate
- [x] Foundation Pack / App Store 能力一键启用（`CapabilityEnabler` + catalog 条目）
- [x] **L3 人工交接工单**（蓝图 `handoff_todos`；`dx:delivery-todo-done`；`/order` 别名）

### Phase DY — （已并入 DX）

> 原「对话下单」阶段已按 D2-B 并入 Phase DX MVP。

### Phase DZ — 旧站移植 L1/L2（进行中）

- [x] 迁移适配器框架 L1 HTML → DXEP Ingest（`dx_migrate`）
- [x] L2 字段加深 + 门户模板（`gov_news` / `ent_article` / `dx:migrate-l2`）
- [x] 导入审核队列 UI（`/admin/dx/migrate/review`：筛选 / 外部 ID / 丢弃）
- [x] **L3 人工待办透明**（`dx_delivery_todo` + 验收 checklist 通过/待补；D4-A）

### Phase EA — 多端交钥匙（进行中）

- [x] Channel API 最小集（= DXEP Channel，见 [data-exchange.md](data-exchange.md) / [channel.md](channel.md)；**一律 token**，D10-B）
- [x] 微信小程序官方模板（`clients/wechat-miniprogram` + pack 脚本）
- [x] Flutter/小程序同构冒烟（`clients-isomorph-smoke.sh`；`content` / `rich_html` 双端对齐）
- [x] 安卓/iOS：Flutter 可配置壳（[flutter-shell.md](flutter-shell.md)，`clients/flutter_shell`）
- [x] 生产打包流水线门禁文档与冒烟（[packer-pipeline.md](packer-pipeline.md)）
- [x] 证书托管（`dx_certs` 路径引用 + 就绪/指纹探测；真实签名 SDK 仍属 CI 环境）

### Phase EB — 行业能力加深（进行中）

- [x] **舆情可演示能力**（D5-B，`dx_opinion` `/opinion`）
- [x] 合规数据源模式（`licensed` + `fixture://` 文件源 / example.com sink；真实 SaaS 可换 Endpoint）
- [x] 政务 trust 默认策略产品化（`dx_trust` + 商店门禁 + 交付编排）

### Phase OE — 开源生态与受众升级（进行中）

> 设计：[open-ecosystem.md](open-ecosystem.md)（已确认 `OE 全默认`）。

- [x] **OE0** 拍板 O1–O8
- [x] **OE1** DX-RAL + DPA 草案入库；catalog/license 字段；安装确认 UI
- [x] **OE2** 认证开发者门禁 + 伙伴文档鉴权（`pending`→`certified`；`/dx/ecosystem/partner`）  
- [x] **OE2 凭证** L2 Composer/Git token（`/dx/ecosystem/credentials`；哈希存储）
- [x] **OE3** L0 公开框架白名单与公开 API 文档发布
- [x] **OE4** `tenant_kind`（含 `personal` 默认关闭）贯通开通 / trust / 套餐
- [x] **L3 租户源码包** 下载 + DX-RAL 水印 + 审计（`/appstore/licenses/{id}/source`）

### 统一登录（`dx_auth`）

> 说明：[auth.md](auth.md)

- [x] 企业ID / 微信 / 短信 / Google 网关
- [x] 邮箱首次登录自动注册（从 2026-08-13 `dx_portal` LoginRegister 归档迁回）
- [x] `/dx/auth/bindings` 绑定状态页
- [x] 冲突归并 + 绑定页短信/扫码微信


---

### Phase DV — Theme Studio（门户门面） ✅

> 主题 UI 是第一感知：策展 packs + 一键切换 + 预览 + 伙伴自助。

- [x] **DV1** 模块 `dx_theme`：catalog · apply · preview · Drush `dx:theme-*`
- [x] **DV2** 六套 packs（`portal` / `slate` / `harbor` / `ember` / `midnight` / `minimal`）
- [x] **DV3** Gallery UI `/admin/dx/themes` · `/dx/themes`
- [x] **DV4** [theme-studio.md](theme-studio.md) · `theme-smoke.sh`

### Phase DW — 政企气质主题包 ✅

> 政府按领导人气质、企业按公司风气归纳多套门面并落地。

- [x] **DW1** catalog `families`：government / enterprise / universal + `persona`
- [x] **DW2** 政府 5 套：`gov_steady|passion|resolve|open|solemn`
- [x] **DW3** 企业 5 套：`ent_drive|fashion|innovate|trust|warm`
- [x] **DW4** 画廊按大类分组 · docs · theme-smoke 覆盖政企 apply

## 已完成 · 底座

- [x] 命名统一：`dcn_*` / `DCN_*` → `dx_*` / `DX_*`（模块、主题、配置、文档、脚本）

- [x] Drupal 11 + 本机/生产 MySQL（平台库 / 租户库分离）
- [x] 混合 SaaS 租户开通骨架（独立库 + multisite）
- [x] 门户主题 `dx_portal_theme` + 一键部署通道
- [x] App Store 实体与策展种子（骨架）
- [x] 生产 LNMPa `open_basedir` / 门户主题上线

---

## Phase A — AI 网关可用（当前冲刺）✅

> 目标：配置密钥 → 调用国产/海外模型 → 门户可见客服聊天 → 有配额与用量记录。

- [x] **A1** 提供商配置完善：模型名、系统提示词、failover、连接测试（`/admin/dx/ai-gateway`）
- [x] **A2** 用量与配额：按月计数、`dx_ai_usage` 流水表、超额拒绝
- [x] **A3** 客服体验：`/ai/chat` 页面 + 区块 + 匿名可用 + CSRF + 限流
- [x] **A4** 运维入口：`drush dx:ai-test` / `dx:ai-usage` / `dx:ai-keys-from-env`；平台仪表盘用量卡片
- [x] **A5** 门户首页露出「AI 客服」入口（已同步生产）

**你需要做的一步：** 在 `.env` 或后台填入至少一个 `DX_AI_DEEPSEEK_KEY`（或其它）并「测试连接」。

```bash
# 示例
echo 'DX_AI_DEEPSEEK_KEY=sk-...' >> /home/wwwroot/drupalX/.env
cd /home/wwwroot/drupalX && vendor/bin/drush dx:ai-keys-from-env
vendor/bin/drush dx:ai-test deepseek
```

## Phase B — AI 深化 ✅

- [x] 租户级密钥与配额覆盖（环境密钥 / 平台配额默认 + 站点覆盖，可一键回退）
- [x] 流式输出（SSE）与多轮会话（20 条 / 16,000 字符上下文上限）
- [x] 接入 `drupal/ai` 1.4 Provider 管理器（标准配置元素、流式 / 非流式、失败回退）
- [x] 知识库 / 企业资料与产品目录注入（企业设定与已发布产品摘要）

## Phase C — App Store 与门户内容 ✅

- [x] 安装申请审批流与白名单 `pm:enable` 租户站执行（`dx:appstore-approve` / 审批按钮）
- [x] 产品 / 媒体内容类型落地与列表页（`/products` / `/media-center` 现代卡片布局）
- [x] 行业 recipe 与演示数据填充（制造 / 零售 / 服务：`drush dx:portal-seed`）

## Phase D — 商业化与中国场景 ✅

- [x] 微信 / 支付宝支付网关联调（`dx_payment` 模块与统一结算桥接）
- [x] 产品商城一键结账收银台（`/product/{node}/checkout` 与订单生成 API）
- [x] 外卡发行支付 sheet（上层默认 Card，指纹钱包分组 Google Pay / Apple Pay）
- [x] OSS（阿里云 / 腾讯云）一键启用包与 CLI 工具（`dx_oss` + `drush dx:oss-upload`）
- [x] 社交发布与营销自动化（OpenGraph 微信卡片分享与 SEO 描述注入）
- [x] 企业信用 ID 登录（Topstar compact UI + `dx_auth`；微信/手机占位待接）

## Phase E — 平台运营 ✅

- [x] Composer 沙箱锁定与安全审计扫描（`drush dx:appstore-audit`）
- [x] 开发者入驻、许可生成与分成结算对账（`dx_license` + `dx_revenue_share` 自动化）
- [x] 租户订阅套餐计费（Starter / Growth / Enterprise 套餐模型与后台列表）
- [x] 平台全景运维监控与仪表盘看板（用量、租户状态、套餐分布）

---

## 验收（Phase A）

| 项 | 状态 |
|----|------|
| `/ai/chat` 可打开 | ✅ 生产 200 |
| 首页有 AI 入口 | ✅ |
| 用量可查 `drush dx:ai-usage` | ✅ |
| 填 Key 后可对话 | ⏳ 待配置密钥（`drush dx:ai-status` 可查就绪） |
