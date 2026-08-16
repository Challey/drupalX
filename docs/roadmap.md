# DrupalX 路线图

原则：底座能力已齐备；主叙事为 **政企交钥匙一键交付**（已拍板，见 [decisions.md](decisions.md)）。每条可独立验收。

**战略**：[turnkey-delivery.md](turnkey-delivery.md)（已确认） · [strategy.md](strategy.md)  
**数据接口与交换**：[data-exchange.md](data-exchange.md)（DXEP，已确认）  
**多端壳**：[flutter-shell.md](flutter-shell.md)（已确认） · [channel.md](channel.md)  
**交钥匙交付台**：[delivery.md](delivery.md)（Phase DX MVP）

---

## 开工顺序（D16-A + FS + DX）

1. ~~Phase DE Channel 只读 / FS1~~ ✅  
2. ~~FS2–FS5 Flutter 壳 + 打包 + 小程序~~ ✅  
3. ~~DE3 Ingest~~ ✅  
4. **Phase DX** 交钥匙交付台 ← 进行中 / MVP  
5. 并行：D5-B 舆情演示方案  

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
- [x] **DE4** Exchange 批次包 apply + 报告（JSON 包登记 / apply / changes / push）  
- [x] **DE5** Webhook 出站 MVP + Channel API 审计/限流（签名增强可继续加深）

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

### Phase DY — （已并入 DX）

> 原「对话下单」阶段已按 D2-B 并入 Phase DX MVP。

### Phase DZ — 旧站移植 L1/L2（进行中）

- [x] 迁移适配器框架 L1 HTML → DXEP Ingest（`dx_migrate`）
- [x] L2 字段加深 + 门户模板（`gov_news` / `ent_article` / `dx:migrate-l2`）
- [x] 导入审核队列 UI（`/admin/dx/migrate/review`）

### Phase EA — 多端交钥匙（进行中）

- [x] Channel API 最小集（= DXEP Channel，见 [data-exchange.md](data-exchange.md) / [channel.md](channel.md)；**一律 token**，D10-B）
- [x] 微信小程序官方模板（`clients/wechat-miniprogram` + pack 脚本）
- [x] Flutter/小程序同构冒烟（`clients-isomorph-smoke.sh`）
- [x] 安卓/iOS：Flutter 可配置壳（[flutter-shell.md](flutter-shell.md)，`clients/flutter_shell`）
- [x] 生产打包流水线门禁文档与冒烟（[packer-pipeline.md](packer-pipeline.md)）
- [x] 证书托管占位（`dx_certs` 路径引用；真实签名集成仍可加深）

### Phase EB — 行业能力加深（进行中）

- [x] **舆情可演示能力**（D5-B，`dx_opinion` `/opinion`）
- [x] 合规数据源模式（`licensed` + 合规提示；真实 SaaS 对接可替换 Endpoint）
- [x] 政务 trust 默认策略产品化（`dx_trust` + 商店门禁 + 交付编排）

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
- [x] OSS（阿里云 / 腾讯云）一键启用包与 CLI 工具（`dx_oss` + `drush dx:oss-upload`）
- [x] 社交发布与营销自动化（OpenGraph 微信卡片分享与 SEO 描述注入）

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
