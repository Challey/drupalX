# DrupalX 多端壳设计：Flutter 可配置 App + 类同小程序

> 状态：**已确认**（拍板 `F1-A … F6-A`，2026-08-16）  
> 日期：2026-08-16  
> 产品名：**DrupalX**（`dx_*` / `DX_*`）  
> 关联：[decisions.md](decisions.md)（D3-B / D10-B）· [data-exchange.md](data-exchange.md)（DXEP）· [turnkey-delivery.md](turnkey-delivery.md)  
> 相对现状：仓库已有 **WebView 安卓壳** / 小程序打包工具（`x-pack-android` / `x-pack-miniprogram`）；本文为正式多端路径 **Flutter 同源壳**（新客默认；WebView 仅存量）。

---

## 1. 你的思路（转述确认）

1. **安卓与 iOS 都用 Flutter**，几乎同一套方式生成 / 打包。  
2. App **整体是一个框架壳**；按钮、板块、列表、详情等 UI 元素全部 **可配置**。  
3. **界面结构与内容数据**通过 **JSON** 从 DrupalX 后台拉取或推送。  
4. 业务与版式变更优先走后台配置 → 客户端检测更新 → **壳内热更新配置**；商店大版本升级只在 **壳架构重大变更** 时发生。  
5. 因此打包变简单：交钥匙时主要是 **灌租户参数 + 出包**，而不是每个客户重写一套原生 UI。  
6. **微信小程序**用同类思路：固定宿主 + 配置驱动页面，内容走同一套 JSON/DXEP。

**结论（分析）**：方向正确，且与已拍板的 DXEP Channel、政企交钥匙、D3-B「接近原生」高度吻合。建议采纳为 **正式多端架构**，但必须加上三条硬约束（见 §3），否则会撞上架审核、安全与可维护性墙。

---

## 2. 为什么适合 DrupalX

| 诉求 | Flutter 可配置壳 | 纯 WebView 壳（现状） | 每客定制原生 |
|------|------------------|----------------------|--------------|
| 接近原生手感 | 强（自绘控件） | 弱–中 | 最强但贵 |
| 安卓/iOS 同源 | 一套 Dart | 两套或 Web 一套 | 双倍成本 |
| 少升商店包 | 配置热更新 | H5 热更新（壳仍旧） | 几乎每次改 UI 都升 |
| 政企多租户白标 | 启动灌 `tenant` + theme | 改 URL | 难规模化 |
| 与 DXEP 对齐 | Channel JSON 天然契合 | 只当浏览器 | 各自私有 API |
| 打包自动化 | 模板工程 + CI 签名 | 已有雏形 | 难 |

对 D3-B 的落地解释：**「接近原生」= Flutter 原生渲染 + 封闭组件目录**，不是「每个客户生成任意原生业务代码」。

---

## 3. 三条硬约束（必须先拍板认同）

### 3.1 只解释配置，不执行远程代码

JSON 只能驱动 **已编译进壳内的组件白名单**（如 `HeroBanner`、`NoticeList`、`ProductGrid`、`ServiceEntry`、`RichArticle`、`TabShell`…）。

禁止：下发 Dart/JS 源码并 `eval`、热补丁任意逻辑二进制、动态加载未审核插件。

否则：安全不可控；**Apple / 国内商店**易拒审。

### 3.2 配置分两层，不要揉成一团

| 层 | 名称 | 变频率 | 来源 |
|----|------|--------|------|
| L0 | **Shell 二进制** | 低（架构/原生能力变更才升） | App Store / 应用商店 / 企业签 |
| L1 | **Layout Manifest（版式）** | 中（栏目、首页模块、主题 token） | DXEP / 后台 `app_layout` |
| L2 | **Content（内容）** | 高（资讯、产品、附件） | DXEP Channel 已有资源 |

客户端启动：鉴权（D10-B token）→ 拉 L1（带版本与 checksum）→ 按 L1 去拉 L2。

### 3.3 原生能力走「能力开关」，不走远程发明

推送、分享、扫码、文件、生物识别等：壳内 **预先实现**，由 Manifest 的 `capabilities: ["push","share",…]` **打开/关闭**。未内置的能力不能靠 JSON「变出来」。

---

## 4. 目标架构

```
┌─────────────────────────────────────────────────────────┐
│  Flutter Shell（Android APK/AAB + iOS IPA 同源工程）      │
│  · 组件目录（封闭）· 路由 · Token 存储 · 布局引擎         │
│  · 配置缓存 · 版本协商 · 有限原生插件（推送等）           │
└───────────────────────────┬─────────────────────────────┘
                            │ HTTPS + Bearer（D10-B）
                            ▼
┌─────────────────────────────────────────────────────────┐
│  DrupalX 租户站 · DXEP Gateway（dx_channel）              │
│  GET /api/dx/v1/channel/site                             │
│  GET /api/dx/v1/channel/app-layout   ← L1 新增           │
│  GET /api/dx/v1/channel/contents|products|… ← L2         │
│  （可选）推送：布局/内容变更通知 → 壳内刷新               │
└─────────────────────────────────────────────────────────┘

微信小程序：同一 L1/L2 JSON；渲染器用小程序组件目录实现「同构解释」
```

交钥匙打包时只注入：

- `tenant_id` / `api_base` / 初始 theme  
- 应用显示名、图标、启动图、bundle id / applicationId  
- 签名与商店元数据（CI 密钥不进业务 JSON）

---

## 5. Layout Manifest（L1）草案

> 确认后进 OpenAPI；字段可微调，原则不变。

```json
{
  "spec": "DX-APP-LAYOUT",
  "spec_version": "1.0",
  "layout_id": "lay_01J...",
  "tenant_id": "acme",
  "revision": 42,
  "min_shell_version": "1.2.0",
  "checksum": "sha256:…",
  "theme": {
    "pack": "gov_steady",
    "primary": "#1A365D",
    "logo_url": "https://…/logo.png",
    "display_name": "示例市政府"
  },
  "capabilities": ["share", "push"],
  "navigation": {
    "type": "tab",
    "items": [
      { "id": "home", "label": "首页", "icon": "home", "page": "page_home" },
      { "id": "news", "label": "资讯", "icon": "article", "page": "page_news" },
      { "id": "services", "label": "服务", "icon": "grid", "page": "page_services" },
      { "id": "mine", "label": "我的", "icon": "person", "page": "page_mine" }
    ]
  },
  "pages": {
    "page_home": {
      "blocks": [
        { "type": "hero_banner", "props": { "source": "channel:site.brand" } },
        { "type": "notice_ticker", "props": { "query": { "type": "notice", "limit": 5 } } },
        { "type": "service_grid", "props": { "query": { "type": "service_entry" } } },
        { "type": "article_list", "props": { "query": { "type": "article", "limit": 10 } } }
      ]
    },
    "page_news": {
      "blocks": [
        { "type": "article_list", "props": { "query": { "type": "article" }, "detail_route": "article_detail" } }
      ]
    }
  },
  "routes": {
    "article_detail": { "type": "article_detail", "id_param": "id" }
  }
}
```

**更新探测**：`GET …/app-layout?since_revision=42` → `304` / 新 revision；或推送 `layout.updated`。  
壳比较 `min_shell_version`：过低则提示「请升级商店版本」，拒绝用过新布局（防白屏）。

---

## 6. 内容层（L2）与 DXEP

不另发明内容协议：直接用已确认的 DXEP Channel 资源（`article` / `notice` / `product` / `service_entry` / `org_profile`…）。

块组件的 `query` 映射为 Channel 列表/详情调用。列表默认无全文、详情拉 `body`（与 DXEP 一致）。

---

## 7. 壳内更新策略（你要的「基本不大升级」）

| 变更类型 | 是否需商店发版 | 机制 |
|----------|----------------|------|
| 改文案/发资讯/上下架产品 | 否 | L2 拉取 |
| 改首页模块顺序、栏目、主题色、开关推送 | 否 | L1 revision 热更新 |
| 新增 **已有** 组件的 props | 否（向后兼容） | L1 + 旧壳忽略未知 props |
| 新增 **新组件类型** | **是**（或灰度：旧壳跳过未知 type 并打点） | 升 Shell，写入组件目录 |
| 新原生能力（如 NFC） | 是 | 升 Shell + capability |
| 安全漏洞 / 系统 API 强制 | 是 | 升 Shell |

推荐策略：**未知 `type` 安全跳过 + 遥测**，避免整页崩溃；运营上尽量少发明新组件，多复用目录。

---

## 8. 打包与交钥匙流程（确认后的 Skill 目标）

```
交钥匙蓝图指定 channels: [web, app, miniprogram]
        │
        ▼
平台生成租户 Layout 初稿（按气质 pack + 站点类型）
        │
        ▼
Flutter 模板工程注入：api_base, tenant, app 名/图标, applicationId/bundleId
        │
        ├─ Android：CI → AAB/APK（企业签或上架签）
        └─ iOS：CI → IPA（需 Apple 账套；企业签/TestFlight）
        │
        ▼
微信小程序：同 L1/L2，用小程序组件目录渲染；x-pack-miniprogram 灌 api_base
```

建议 Skill / 工具命名（确认后实现）：

| 端 | Skill（建议） | 说明 |
|----|---------------|------|
| Flutter 双端 | `x-pack-flutter` | 生成/灌参/可选云构建 |
| 微信小程序 | 演进现有 `x-pack-miniprogram` | 改为消费同一 Layout JSON |
| 旧 WebView 安卓 | `x-pack-android` | **过渡保留**；新客默认 Flutter |

---

## 9. 微信小程序「类似做法」

| 点 | App（Flutter） | 小程序 |
|----|----------------|--------|
| 宿主 | Flutter Shell 二进制 | 微信运行时（无法自带二进制热更） |
| L1/L2 | 同 JSON | **同 JSON**（强烈建议） |
| 组件目录 | Dart widgets | WXML/组件映射表（同名 `type`） |
| 热更新 | L1/L2 任意 | L1/L2 可；**基础库与组件集**仍受微信审核限制 |
| 打包 | `x-pack-flutter` | `x-pack-miniprogram` 灌配置 |

小程序也做不到「架构大改也不提审」——微信侧仍有版本发布；但 **页面结构与内容** 可与 App 共用后台，减少双份运营。

---

## 10. 风险与对策

| 风险 | 对策 |
|------|------|
| 苹果拒审「远程改 UI」 | 封闭组件目录；不执行远程代码；重大体验变更仍发版 |
| 布局 JSON 写炸白屏 | schema 校验 + `min_shell_version` + 未知块跳过 + 本地上一版回滚 |
| Token（D10-B）泄漏 | 短时 token、证书钉扎可选、不进 git；企业分发走 MDM |
| 政企内网 | 支持可配置 `api_base`；离线缓存最近 L1/L2 |
| 与旧 WebView 双轨 | 新交付默认 Flutter；WebView 仅维护存量 |
| iOS 构建依赖 Mac/账套 | 文档写清：平台可出工程，上架签名可客户自建或托管 CI |

---

## 11. 与现有 WebView 打包的关系

- **短期**：WebView 工具可继续服务演示/存量。  
- **中期**：交钥匙「要 App」默认走 Flutter 壳。  
- **长期**：WebView 降为 fallback（仅内网极老设备等），不再作为 D3-B 主叙事。

---

## 12. 实施计划（确认后；无日历工期）

### Phase FS0 — 确认本设计（当前）

- 拍板 §14  
- 冻结组件目录 v1 清单（够政务/企事业门户首页）

### Phase FS1 — 契约

- DXEP 增加 `GET /channel/app-layout`  
- Layout schema + 示例（政府 / 企业各一份）  
- OpenAPI 草稿并入 DE1

### Phase FS2 — Flutter Shell MVP

- 工程模板：`clients/flutter_shell/`  
- 实现组件目录 v1 + 布局引擎 + token 登录/刷新  
- 缓存与 revision 更新  
- 演示：灌一租户即可跑通 Android 模拟器

### Phase FS3 — 打包 Skill

- `x-pack-flutter`：manifest 灌参、出工程、可选 assemble  
- 文档 / Cursor Skill（确认后再写 Skill 文件）  
- iOS 出包路径说明（含账套前提）

### Phase FS4 — 小程序同构

- 小程序组件映射表对齐 L1 `type`  
- `x-pack-miniprogram` 改为灌同一 layout revision

### Phase FS5 — 交钥匙串联

- Delivery Blueprint 勾选 app/miniprogram → 自动生成初稿 Layout + 触发打包流水线

---

## 13. 组件目录 v1（已冻结）

> **v2 校正（L3 Phase H2，2026-08-30）**：真正的单一真源是
> `clients/flutter_shell/assets/config/component_catalog.json`（`schema_version: 2`），
> 下表只是它的人类视图。**1.2.x 实际编译进壳、能渲染的是 12 项**（下表 `since=1`）；
> 三个 detail 类型当年只写在设计里、注册表从未 `case` 过，v2 才补齐 —— 因此目录里它们标
> `since: 2`，`app_layout_gov.json`（现网版式夹具）也**禁止**使用它们（CI `catalog` 模式断言）。
> 详见 §16。

| type | 用途 | since |
|------|------|-------|
| `tab_shell` / 导航由 navigation 描述 | 底栏/侧栏 | 1（宿主，不作 block） |
| `hero_banner` | 品牌首屏 | 1 |
| `notice_ticker` | 通知滚动 | 1 |
| `article_list` / `article_detail` | 资讯 | 1 / 2 |
| `notice_list` / `notice_detail` | 公告（含文号区） | 1 / 2 |
| `product_grid` / `product_detail` | 产品 | 1 / 2 |
| `service_grid` | 办事/服务入口 | 1 |
| `rich_html` / `content` | 消毒后 HTML 正文（`content` 为 Channel 别名） | 1 |
| `web_link` | 外开系统浏览器（白名单域） | 1 |
| `profile_header` | 组织名片 | 1 |
| `empty` / `error` | 占位 | 1 |

未列类型：不解释（跳过 + 打点）。

---

## 14. 拍板结果（已确认）

选择：`F1-A, F2-A, F3-A, F4-A, F5-A, F6-A`（推荐默认全选）

| 编号 | 决议 |
|------|------|
| F1-A | Flutter 双端 + JSON 可配置壳为主路径（落实 D3-B） |
| F2-A | 只允许白名单组件 + 预置 capability；不执行远程代码 |
| F3-A | 新客默认 Flutter；WebView（`x-pack-android`）仅存量 |
| F4-A | 小程序与 App 共用 L1/L2 JSON |
| F5-A | iOS：平台先保证可打开的 Flutter 工程 + 文档；签名/上架由客户或托管 CI |
| F6-A | 拍板前曾冻结 Skill；**现已确认，按 Phase FS 开工** |

### 勾选留档

### F1 · 是否采纳「Flutter 双端 + JSON 可配置壳」为主路径？

- [x] **A** 是（推荐；落实 D3-B）  
- [ ] **B** 继续以 WebView 为主  

### F2 · 远程能力边界

- [x] **A** 只允许白名单组件 + 预置 capability（推荐）  
- [ ] **B** 允许远程下发更多动态逻辑（风险高，不推荐）  

### F3 · 与现有 `x-pack-android`（WebView）

- [x] **A** 新客默认 Flutter；WebView 仅存量  
- [ ] **B** 两者长期并行，由客户选  
- [ ] **C** 立即废弃 WebView 工具  

### F4 · 小程序

- [x] **A** 与 App 共用 L1/L2 JSON（推荐）  
- [ ] **B** 小程序单独维护另一套页面配置  

### F5 · iOS 出包

- [x] **A** 平台先保证「可打开的 Flutter 工程 + 文档」；签名/上架客户或托管 CI  
- [ ] **B** 平台必须代构建 IPA（需准备 Apple 账套与 Mac runner）  

### F6 · 确认前是否冻结 Skill/工程开发？

- [x] **A** 冻结；仅文档 — *拍板前有效；现已确认*  
- [ ] **B** 允许先搭 Flutter 空壳仓库不接业务  

---

## 15. 确认后动作

- [x] 本文改为 **已确认**；回写 [decisions.md](decisions.md) D3-B 范围  
- [x] [roadmap.md](roadmap.md) Phase FS0 完成，FS1+ 可开发  
- [x] FS1：DXEP `GET /channel/app-layout` + `/channel/site`（模块 `dx_channel`，见 [channel.md](channel.md)）  
- [x] FS2：`clients/flutter_shell/` MVP  
- [x] FS3：Skill `x-pack-flutter`（见 [flutter-pack.md](flutter-pack.md)）  
- [x] FS4：小程序同构（`clients/wechat-miniprogram/`）  
- [x] FS5：`scripts/pack-tenant-channels.sh` 串联 Flutter + 小程序出包（蓝图 UI 归 Phase DX）

---

## 16. 组件目录 v2 与离线用例（L3 Phase H2，2026-08-30）

### 16.1 单一真源

`clients/flutter_shell/assets/config/component_catalog.json` —— **DX-COMPONENT-CATALOG**，
`schema_version: 2`，18 个组件（12 个 `since: 1` 冻结 + 6 个 v2 新增）。目录同时驱动：

| 消费方 | 文件 |
| --- | --- |
| Flutter 注册表 | `lib/layout/block_registry.dart`（`known` / `v1Types` / `v2Types` 全部来自目录） |
| Flutter 目录载入与校验 | `lib/layout/component_catalog.dart`（Dart 镜像常量 + 运行时校验） |
| 布局引擎 | `lib/layout/layout_engine.dart`（能力门控、未知 type 跳过、渲染计划顺序） |
| 小程序同构渲染 | `clients/wechat-miniprogram/utils/dxep.js` + `pages/index/index.wxml` |
| CI 静态门禁 | `tools/clients/isomorph_check.py catalog`（133 项） |

### 16.2 v2 新增

`article_detail` · `notice_detail` · `product_detail` · `search_bar` · `quick_actions` · `nearby_service`
（最后者带 `capability: location`）。

**能力词表跨层同名**：`location` / `microphone` / `photo_upload` / `share` 与 Android 壳 1.3.0 的
`capabilities` 用同一套词（`share` 是宿主能力、不落 block，属目录显式例外）。
带 `capability` 的组件只有当 L1 `layout.capabilities` 声明同名能力时才渲染 —— 未声明即跳过，
不会因后台误发而弹权限。

### 16.3 夹具（不联网即可跑通）

| 夹具 | 用途 |
| --- | --- |
| `assets/fixtures/app_layout_gov.json` | **v1 冻结版式**：只含 `since: 1` 类型，线上 1.2.x 壳也必须能渲染 |
| `assets/fixtures/app_layout_v2.json` | v2 演示版式：含新组件与能力门控样例，只能下发给 shell ≥ 1.3.0 |
| `assets/fixtures/site.json` / `contents_list.json` / `content_article.json` / `content_product.json` | L1 站点 + L2 列表/详情 |

每份 Flutter 夹具都由 `tools/clients/sync_fixtures.py` 生成对应的小程序端
`clients/wechat-miniprogram/fixtures/*.js`，CI 用 `--check` 阻止两端漂移。

### 16.4 离线用例

```bash
cd /home/wwwroot/drupalX

# 有 Flutter SDK 的窗口里跑（需联网拉 pub 依赖，本线未执行）
cd clients/flutter_shell && flutter pub get && flutter test

# 无 SDK 的静态等价门禁（本线实际执行）
python3 tools/clients/isomorph_check.py catalog   # 目录 ↔ 注册表 ↔ widget 文件 ↔ wxml ↔ 夹具
python3 tools/clients/isomorph_check.py dart      # Dart 括号配平 + import 解析（三方查 pubspec）
bash scripts/ci/flutter-shell-smoke.sh
```

三个 Dart 测试文件（19 个用例）已入库，`flutter test` 一旦获授权即可直接运行：

* `test/layout_engine_test.dart`（7）：版式解析、封闭目录、`min_shell_version` 协商、渲染顺序、
  未知 type 丢弃、能力门控、空页不崩、v2 对已交付版式只做加法；
* `test/component_catalog_test.dart`（8）：`schema_version=2` 与注册表镜像一致、目录 ↔ Dart 镜像逐组件相等、
  v1 冻结项永不消失/不改版本、每个组件文件存在且声明其 widget 类、**每个 widget 文件都必须被目录收录**、
  widget 只读目录声明的 props、能力词表与 Android 壳对齐；
* `test/field_contract_test.dart`（4）：Dart 镜像 == `clients/field-contract.json`、L2 夹具覆盖全部必填字段、
  时间戳 RFC3339 且价格保持 decimal 字符串、壳依赖的 envelope 键已登记。

`component_catalog_test.dart` 就是任务卡要求的「注册表与 `lib` 下文件一一对应」的可执行版本：
它在没有 SDK 时由 `isomorph_check.py catalog` 以同样断言把守（双向：目录有组件必须有文件，文件必须被目录收录）。

---

*FS0–FS5 壳与出包链路已通；Phase DX 交付台 MVP 已落地（见 [delivery.md](delivery.md)）；
Phase H 起：组件目录 v2（§16）+ Android 壳 1.3.0（[android-pack.md](android-pack.md)）
+ 三端数据字段契约（[isomorph-pack.md](isomorph-pack.md)）+ 统一出包清单（[manifest-pack.md](manifest-pack.md)）。*
