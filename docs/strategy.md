# DrupalX 战略说明

> 更新日期：2026-08-16  
> 产品名：**DrupalX**（`dx_*` / `DX_*`）。基于 Drupal 11 开源底座升级改造，**不是** DrupalCN。  
> **战略调整设计稿（待确认）**：[turnkey-delivery.md](turnkey-delivery.md) — 确认后再开发交钥匙相关功能。  
> **数据接口与交换规范（待确认）**：[data-exchange.md](data-exchange.md)（DXEP）— 确认后再实现。

## 使命（调整中）

做成**面向政企门户与官方信息业务的交钥匙一键交付平台**：客户用自然语言或页面选型提出需求后，平台完成开通、门面、内容/业务移植、能力装配与多端打包，输出可验收交付物。

底座形态仍是：**企业级 Foundation + 策展半封闭 App Store**；前台体验从「ToB 开通配置」升级为「ToC 下单交付」，复杂业务能力继续通过 App Store 深化。

客户能够：

1. **下单式交付**政府或企事业门户（对话或向导）；
2. **自动或半自动移植**原网站资讯与可映射业务（分级，见交钥匙设计）；
3. 在底座上**选用已审核模块/功能**（商城、舆情、支付等），形成业务系统；
4. 同时获得 **Web 平台**，并**快速生成小程序与受控安卓/PWA 壳**（同源 API；非安卓式旁加载）。

以**专业、可信、规范**为前提，兼顾**快速、高效、高性价比**，实现 **平台 · 开发者 · 政企客户** 三方共赢。

与通用 Agent（如 Manus 类）的差异：DrupalX 不做任意任务助手，而做**政企门户垂直交钥匙 OS**。详见 [turnkey-delivery.md](turnkey-delivery.md) §3。

## 硬约束（五性 + UI）

| 支柱 | 含义（工程落点） |
|------|------------------|
| 安全性 | 租户库隔离、密钥不入库明文展示、商店策展审核、供应链锁定 Composer |
| 稳定性 | 版本集可复现、健康/备份/SLO、禁止未审核旁加载 |
| 快速性 | 一键开通、Foundation Pack、商店一键启用、**交钥匙蓝图一键执行**、多端模板 |
| 可扩展性 | 模块化 + App Store 上架扩展，而非改核心单体 |
| 可伸缩性 | 无状态 Web、每租户独立 DB、缓存/队列可水平扩展 |
| UI | 简洁、大气、美观；品牌清晰；少杂乱仪表盘感；**Theme Studio** 一键换门面 |

## 三方价值

```
政企客户 ──下单/选型/买模块──► 平台（策展·交付引擎·结算·运维）
                ▲                    │
                │ 许可启用            │ 审核上架 / 分成
                │                    ▼
                └────────────── 认证开发者
```

- **政企**：交钥匙出站 → Pack/门面 → 移植与能力装配 → Web / 小程序 / 受控 App 同内容；深水区进 Store。
- **开发者**：认证 → 审核上架 → 定价（买断/订阅）→ 平台结算分成（换取可达政企的可信货架）。
- **平台**：信任与版本门禁、交付编排与可观测、品牌与渠道；抽成合理换质量与稳定。

## App Store：苹果式半封闭（锁定）

**采用苹果式策展，明确避免安卓式旁加载/免审自由市场。**

| 维度 | DrupalX（采用） | 避免 |
|------|-----------------|------|
| 分发 | 认证提交 → 审核 → Composer lock + 白名单 → 许可后启用 | 租户任意 `composer require`、未审核包、旁加载 |
| 源码 | 底座与已分发的 Drupal 衍生模块遵守 GPL 等开源义务；**上架权、目录、审核、结算、控制面**由平台掌握 | 「全开源」被误解为免审上架 |
| 政务 | 默认可限制仅 `platform` / `security` trust tier | 对政务默认开放 `community` |

变现靠：**审核质量、交钥匙交付、支持、SaaS 控制面、分成**；不靠对已分发 GPL 衍生模块向租户隐瞒源码。

详见 [module-curation.md](module-curation.md)。

## 产品分层

1. **Turnkey Delivery（设计中）** — 交付台：对话/向导 → Blueprint → 一键编排 → 验收报告。见 [turnkey-delivery.md](turnkey-delivery.md)。
2. **Foundation Platform** — 控制面 `dx_platform` + 租户底座（组织/资讯/门户/IAM）+ Foundation Packs（SME / 政务 / 行业）。
3. **Curated App Store** — `dx_appstore` 策展半封闭：发现、购买、许可、分成（ToB 功能货架）。
4. **Multi-channel** — Web 主题（Theme Studio 策展 packs）+ PWA/受控安卓壳 + 微信小程序模板；共用 **DXEP Channel API**（规范见 [data-exchange.md](data-exchange.md)）。
5. **DXEP 数据交换** — 标准读/写/批次包接口，支撑多端、旧站移植与政企系统对接（确认后实现）。
6. **Add-ons** — AI 网关、支付、OSS、营销等：可被交钥匙勾选的增值能力。

### 产品板块 · Theme Studio（门面）

主题是用户第一感知：外观 + 便捷操作密度。平台策展政府气质 / 企业风气 / 通用 packs，租户在 `/admin/dx/themes` 或 `/dx/themes` **一键切换**；白标色叠在当前 pack 之上。交钥匙流水线将 Theme 选包作为 D3 阶段。详见 [theme-studio.md](theme-studio.md)。

## 多端「快速生成」含义

- **Web**：租户门户（主站，正式交付物）。
- **App**：启用 PWA + 品牌包 = 可安装应用壳；安卓侧为**受控壳工厂**（确认后落地），非完整原生工程生成器。
- **微信小程序**：官方模板工程对接 DXEP Channel API（同源内容；见 [data-exchange.md](data-exchange.md)）。

不做：租户侧可视化拖拽生成完整原生 iOS/Android 工程；不做安卓式任意来源安装包。

## 路线原则

**交付体验（Turnkey）← 叠在 → Foundation → Store → Channel → 体验（UI/五性）**。

历史 AI/运维字母阶段（A–DJ）与 Theme Studio（DV–DW）已完成并归档；**交钥匙开发波次自 Phase DX 起，须先确认** [turnkey-delivery.md](turnkey-delivery.md) §15。

完整计划见 [roadmap.md](roadmap.md)；技术结构见 [architecture.md](architecture.md)。
