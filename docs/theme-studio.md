# Theme Studio（门户门面）

主题 UI 是用户的**第一感知**：不只是配色，还包括留白、字号层级、导航密度与动效节奏。DrupalX 将 **Theme Studio** 作为产品板块，让租户在策展好的主题包之间**一键切换**。

## 定位

| 层 | 说明 |
|----|------|
| Shell 主题 | 始终是 `dx_portal_theme`（结构 / Twig / PWA） |
| Theme packs | 策展皮肤：token + 交互密度 + 视觉气质 |
| White-label | 在当前 pack 之上叠加品牌色 / Logo / 显示名 |

不做：租户任意上传未审核 CSS；不做安卓式旁加载主题市场。Pack 由平台策展（与 App Store 同一半封闭原则）。

## 风格类型（两大类）

### 政府 · 领导人气质

按施政气质选门面，而不是「随便换个颜色」。

| id | 气质 | 视觉要点 | 适合 |
|----|------|----------|------|
| `gov_steady` | **沉稳** | 深蓝克制、节奏舒缓 | 务实稳健政务门户 |
| `gov_passion` | **激情** | 朱红有力、标题醒目 | 动员号召 / 活动宣传 |
| `gov_resolve` | **魄力** | 炭灰高对比 + 决断红线、紧凑 | 强调执行与决断 |
| `gov_open` | **亲民** | 柔和政务蓝、宽留白、圆角 | 便民服务 / 沟通型 |
| `gov_solemn` | **庄重** | 典礼红金点缀于冷灰纸面 | 仪式感 / 正式公告 |

### 企业 · 公司风气

按老板性格与组织氛围选门面。

| id | 风气 | 视觉要点 | 适合 |
|----|------|----------|------|
| `ent_drive` | **进取** | 墨绿进取、CTA 有力 | 扩张型 / 奋斗型团队 |
| `ent_fashion` | **时尚** | 黑白高对比 + 电光强调 | 潮流品牌 / 设计驱动 |
| `ent_innovate` | **创新** | 清新青蓝、轻盈留白 | 科技 / 产品创新 |
| `ent_trust` | **稳健** | 冷静灰蓝、层级清晰 | 金融、制造、B2B |
| `ent_warm` | **温暖** | 暖墨 + 柔和铜调 | 人情味 / 客户温度 |

### 通用 · 经典

| id | 说明 |
|----|------|
| `portal` | 默认冷灰 + 深绿松石 |
| `midnight` | 深色演示 / 夜间 |
| `minimal` | 极简品牌优先 |
| `slate` / `harbor` / `ember` | 经典别名（CLI 仍可用；画廊默认隐藏） |

## 界面

| 入口 | 路径 | 权限 |
|------|------|------|
| Theme Studio（管理） | `/admin/dx/themes` | `administer dx theme studio` |
| 伙伴自助 | `/dx/themes` | `manage dx theme pack`（或 brand / tenant admin） |
| 预览 | Apply 前 Preview，或 `?dx_skin=gov_steady` | 管理预览会话 |

画廊按 **政府 / 企业 / 通用** 三大分组展示，卡片标注气质标签（沉稳、进取…）。

## Drush

```bash
vendor/bin/drush --uri=http://demo.drupalx.local dx:theme-list
vendor/bin/drush --uri=http://demo.drupalx.local dx:theme-list --format=json
vendor/bin/drush --uri=http://demo.drupalx.local dx:theme-apply gov_steady
vendor/bin/drush --uri=http://demo.drupalx.local dx:theme-apply ent_innovate
vendor/bin/drush --uri=http://demo.drupalx.local dx:theme-status --format=json
```

## 工程落点

- 模块：`web/modules/custom/dx_theme`
- 目录：`dx_theme/data/catalog.yml`（`families` + `persona`）
- 皮肤 CSS：`web/themes/custom/dx_portal_theme/css/skins/{gov_*,ent_*}.css`
- 冒烟：`./scripts/ci/theme-smoke.sh`

## 与 Brand pack 的关系

Brand pack 管**品牌身份**（名 / 色 / Logo）；Theme Studio 管**门面气质与操作密度**。建议：先按政企气质选 pack，再微调白标色。
