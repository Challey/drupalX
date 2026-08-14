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

- [x] 租户级密钥与配额覆盖（平台默认 + 租户覆盖：`/admin/dx/tenant` · `ai_quota_override` / `ai_keys_override`）
- [x] 流式输出（SSE `/dx/ai/chat/stream`）与多轮会话（PrivateTempStore + `session_id`）
- [x] 接入 `drupal/ai` Provider 管理器（`prefer_ai_module` → ChatInput/ChatMessage，失败回退 HTTP）
- [x] 知识库 / 企业资料注入（产品节点摘要 + 公司名/行业；`drush dx:ai-knowledge`）

## Phase C — App Store 与门户内容 ✅

- [x] 安装申请审批流与白名单 `pm:enable`（Approve → Install；`ModuleInstallGate`；`dx:appstore-approve|install|reject|whitelist`）
- [x] 产品 / 媒体内容类型落地与列表页（门户样式 + `/portal` CTA + `dx:portal-seed`）
- [x] 行业 recipe（`recipes/dx_industry_{manufacturing,retail,services}`）

## Phase D — 商业化与中国场景（下一批）

- [ ] 微信 / 支付宝支付网关联调
- [ ] 产品商城结账
- [ ] OSS（阿里云 / 腾讯云）一键启用包
- [ ] 社交发布与营销自动化

## Phase E — 平台运营

- [ ] Composer 沙箱安装与安全扫描
- [ ] 开发者入驻、结算对账
- [ ] 套餐计费（AI token / 存储 / 站点数）
- [ ] 生产监控与多区域

---

## 验收（Phase B + C）

| 项 | 状态 |
|----|------|
| 租户可覆盖配额/密钥 | ✅ |
| `/dx/ai/chat/stream` SSE | ✅ |
| 多轮会话 `session_id` | ✅ |
| 产品知识注入 | ✅ |
| 安装申请审批 + 白名单启用 | ✅ |
| `/products` `/media-center` `/portal` | ✅ |
| 行业 recipe | ✅ |
| 填 Key 后可对话 | ⏳ 待配置密钥 |
