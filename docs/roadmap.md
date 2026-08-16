# DrupalX 路线图

原则：底座能力已齐备；主叙事为 **政企交钥匙一键交付**（已拍板，见 [decisions.md](decisions.md)）。每条可独立验收。

**战略**：[turnkey-delivery.md](turnkey-delivery.md)（已确认） · [strategy.md](strategy.md)  
**数据接口与交换**：[data-exchange.md](data-exchange.md)（DXEP，已确认）  
**多端壳**：[flutter-shell.md](flutter-shell.md)（已确认） · [channel.md](channel.md)（FS1 `dx_channel`）

---

## 开工顺序（D16-A + FS）

1. **先** Phase DE（DXEP Channel 只读）— FS1 已落地 `dx_channel` site/app-layout  
2. 继续 **FS2** Flutter Shell MVP  
3. **再** Phase DX（交钥匙：向导 + 对话）  
4. 并行：D5-B 舆情演示方案  

---

## 多端壳 · Flutter

> 状态：**已确认**（F1–F6 全 A）。按 FS 开发。

### Phase FS — Flutter 可配置壳

- [x] **FS0** 拍板 flutter-shell §14（`F1-A…F6-A`）  
- [x] **FS1** DXEP `app-layout` + `site` + schema/OpenAPI（模块 `dx_channel`）  
- [ ] **FS2** Flutter Shell MVP（组件目录 v1 + 布局引擎） ← **下一步**  
- [ ] **FS3** Skill `x-pack-flutter`（灌参/出工程；iOS 按 F5-A）  
- [ ] **FS4** 小程序同构 L1/L2  
- [ ] **FS5** 交钥匙蓝图串联出包  

---

## 并行规范 · DXEP 数据接口

> 状态：契约已确认；Channel 读路径 FS1 已实现（`dx_channel`）。

### Phase DE — 标准接口与交换（计划）

- [x] **DE1** 冻结 DXEP v1 字段/错误码 · OpenAPI 草稿（`docs/openapi/dxep-v1.yaml`；D10-B）
- [x] **DE2** Channel 只读 MVP（`dx_channel`：site / app-layout）← **已完成（FS1）**
- [ ] **DE3** Ingest upsert + 审核队列
- [ ] **DE4** Exchange 批次包 apply + 报告
- [ ] **DE5** Webhook · 签名增强 · 限流审计
---

## 主线 · 交钥匙

> 状态：战略已确认；在 DE2 后启动。

### Phase DX — 交付台 MVP（计划 · 含对话）

> 拍板 D2-B：向导 + 对话同属 MVP（原 DY 并入）。

- [ ] Blueprint 实体与确认页（`dx_delivery`）
- [ ] 页面选型向导（站点类型 · 气质 · 能力 · 端）
- [ ] 需求对话 → 蓝图草稿（复用 AI 网关）
- [ ] 客户改勾选后确认执行
- [ ] 编排：开通 → Foundation Pack → Theme → 商店包启用
- [ ] 验收报告 v1

### Phase DY — （已并入 DX）

> 原「对话下单」阶段已按 D2-B 并入 Phase DX MVP。

### Phase DZ — 旧站移植 L1/L2（计划）

- [ ] 迁移适配器框架 + 常见门户模板（DXEP Ingest/Exchange）
- [ ] 导入审核队列与人工待办

### Phase EA — 多端交钥匙（计划）

- [ ] Channel API 最小集（= DXEP Channel，见 [data-exchange.md](data-exchange.md)；**一律 token**，D10-B）
- [ ] 微信小程序官方模板
- [ ] 安卓/iOS：Flutter 可配置壳（[flutter-shell.md](flutter-shell.md)，已确认）

### Phase EB — 行业能力加深（计划）

- [ ] **舆情可演示能力**（D5-B，第一波）+ 合规数据源
- [ ] 政务 trust 默认策略产品化

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
| 填 Key 后可对话 | ⏳ 待配置密钥 |
