# DrupalX 战略说明

> 更新日期：2026-08-15  
> 产品名：**DrupalX**（`dx_*` / `DX_*`）。基于 Drupal 11 开源底座升级改造，**不是** DrupalCN。

## 使命

做成**企业级基础平台 + 策展半封闭 App Store**，让中小微企业与政府部门能够：

1. **快速搭建**自己的基础信息与业务系统；
2. 在底座上**选用已审核模块/功能**，快速或直接形成业务系统；
3. 同时获得 **Web 平台**，并**快速生成可安装 App（PWA）与微信小程序**（同源 API，非安卓式旁加载）。

以**快速、高效、高性价比**实现 **平台 · 开发者 · 政企客户** 三方共赢。

## 硬约束（五性 + UI）

| 支柱 | 含义（工程落点） |
|------|------------------|
| 安全性 | 租户库隔离、密钥不入库明文展示、商店策展审核、供应链锁定 Composer |
| 稳定性 | 版本集可复现、健康/备份/SLO、禁止未审核旁加载 |
| 快速性 | 一键开通、Foundation Pack、商店一键启用、多端模板 |
| 可扩展性 | 模块化 + App Store 上架扩展，而非改核心单体 |
| 可伸缩性 | 无状态 Web、每租户独立 DB、缓存/队列可水平扩展 |
| UI | 简洁、大气、美观；品牌清晰；少杂乱仪表盘感；**Theme Studio** 一键换门面 |

## 三方价值

```
政企客户 ──开通/选包/买模块──► 平台（策展·结算·运维）
                ▲                    │
                │ 许可启用            │ 审核上架 / 分成
                │                    ▼
                └────────────── 认证开发者
```

- **政企**：分钟级出站 → Pack 出基础系统 → Store 选业务能力 → Web / PWA / 小程序同内容。
- **开发者**：认证 → 审核上架 → 定价（买断/订阅）→ 平台结算分成（换取可达政企的可信货架）。
- **平台**：信任与版本门禁、计费与可观测、品牌与渠道；抽成合理换质量与稳定。

## App Store：苹果式半封闭（锁定）

**采用苹果式策展，明确避免安卓式旁加载/免审自由市场。**

| 维度 | DrupalX（采用） | 避免 |
|------|-----------------|------|
| 分发 | 认证提交 → 审核 → Composer lock + 白名单 → 许可后启用 | 租户任意 `composer require`、未审核包、旁加载 |
| 源码 | 底座与已分发的 Drupal 衍生模块遵守 GPL 等开源义务；**上架权、目录、审核、结算、控制面**由平台掌握 | 「全开源」被误解为免审上架 |
| 政务 | 默认可限制仅 `platform` / `security` trust tier | 对政务默认开放 `community` |

变现靠：**审核质量、支持、SaaS 控制面、分成**；不靠对已分发 GPL 衍生模块向租户隐瞒源码。

详见 [module-curation.md](module-curation.md)。

## 产品分层

1. **Foundation Platform** — 控制面 `dx_platform` + 租户底座（组织/资讯/门户/IAM）+ Foundation Packs（SME / 政务 / 行业）。
2. **Curated App Store** — `dx_appstore` 策展半封闭：发现、购买、许可、分成。
3. **Multi-channel** — Web 主题（Theme Studio 策展 packs）+ PWA「App」+ 微信小程序模板；共用 Channel API（规划中）。
4. **Add-ons** — AI 网关、支付、OSS、营销等：增值能力，**不是**产品主叙事。

### 产品板块 · Theme Studio（门面）

主题是用户第一感知：外观 + 便捷操作密度。平台策展多套 portal packs（`portal` / `slate` / `harbor` / `ember` / `midnight` / `minimal`），租户在 `/admin/dx/themes` 或 `/dx/themes` **一键切换**；白标色叠在当前 pack 之上。详见 [theme-studio.md](theme-studio.md)。

## 多端「快速生成」含义

- **Web**：租户门户（主站）。
- **App**：启用 PWA + 品牌包 = 可安装应用壳（非完整原生工程生成器）。
- **微信小程序**：官方模板工程对接 Channel API（同源内容）。

不做：租户侧可视化拖拽生成完整原生 iOS/Android 工程；不做安卓式任意来源安装包。

## 路线原则

**Foundation → Store → Channel → 体验（UI/五性）**；历史 AI/运维字母阶段（A–DJ）已完成并归档，战略波次自 **DK** 起。

完整计划见 [roadmap.md](roadmap.md)；技术结构见 [architecture.md](architecture.md)。
