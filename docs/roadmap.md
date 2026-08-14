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

## Phase A — AI 网关可用 ✅

- [x] **A1–A5** 提供商 / 配额 / 客服聊天 / Drush / 门户入口

## Phase B — AI 深化 ✅

- [x] 租户级密钥与配额覆盖
- [x] 流式输出（SSE）与多轮会话
- [x] 接入 `drupal/ai` Provider 管理器（稳定路径 + HTTP 回退）
- [x] 知识库 / 企业资料注入

## Phase C — App Store 与门户内容 ✅

- [x] 安装申请审批流与白名单 `pm:enable`
- [x] 产品 / 媒体内容类型落地与列表页
- [x] 行业 recipe（制造 / 零售 / 服务）

## Phase D — 商业化与中国场景（部分完成）

- [x] 微信 / 支付宝支付网关联调骨架（`dx_pay`，沙箱模拟支付 + notify）
- [x] 产品商城结账（`/store` → checkout → sandbox pay）
- [x] OSS（阿里云 / 腾讯云）一键启用包（`dx_oss` checklist + 连通性探测）
- [ ] 社交发布与营销自动化（下一批）
- [ ] 微信 / 支付宝 **生产** 预下单 API 正式签名对接

## Phase E — 平台运营（下一批）

- [ ] Composer 沙箱安装与安全扫描
- [ ] 开发者入驻、结算对账
- [ ] 套餐计费（AI token / 存储 / 站点数）
- [ ] 生产监控与多区域

---

## 验收速查

| 项 | 状态 |
|----|------|
| AI 客服 / SSE / 知识库 | ✅ |
| App Store 审批启用 | ✅ |
| `/store` 沙箱结账 | ✅ |
| `/admin/dx/oss` 启用清单 | ✅ |
| 填 AI Key 后可对话 | ⏳ |
| 支付生产签名 | ⏳ |
