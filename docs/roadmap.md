# DrupalX 路线图

原则：**AI 能力优先打通可演示闭环**，再铺 App Store / 商业化。每条可独立验收。

---

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
- [x] 企业ID（统一社会信用代码）登录：`dx_auth` + Topstar 紧凑登录 UI（微信/手机仍为 stub）

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
