# Theme Studio（门户门面）

主题 UI 是用户的**第一感知**：不只是配色，还包括留白、字号层级、导航密度与动效节奏。DrupalX 将 **Theme Studio** 作为产品板块，让租户在策展好的主题包之间**一键切换**。

## 定位

| 层 | 说明 |
|----|------|
| Shell 主题 | 始终是 `dx_portal_theme`（结构 / Twig / PWA） |
| Theme packs | 策展皮肤：token + 交互密度 + 视觉气质 |
| White-label | 在当前 pack 之上叠加品牌色 / Logo / 显示名 |

不做：租户任意上传未审核 CSS；不做安卓式旁加载主题市场。Pack 由平台策展（与 App Store 同一半封闭原则）。

## 内置 packs

| id | 气质 | 适合 |
|----|------|------|
| `portal` | 冷灰纸面 + 深绿松石（默认） | SME / 通用 |
| `slate` | 炭灰高对比编辑风 | 资讯 / 政务公告 |
| `harbor` | 港湾蓝绿、宽留白 | 政务 / 公共服务 |
| `ember` | 深墨 + 铜橙 CTA | SME 行动感 |
| `midnight` | 深色门面 | 演示 / 夜间 |
| `minimal` | 极简大品牌 | 品牌优先落地页 |

## 界面

| 入口 | 路径 | 权限 |
|------|------|------|
| Theme Studio（管理） | `/admin/dx/themes` | `administer dx theme studio` |
| 伙伴自助 | `/dx/themes` | `manage dx theme pack`（或 brand / tenant admin） |
| 预览 | Apply 前 Preview，或 `?dx_skin=harbor` | 管理预览会话 |

## Drush

```bash
vendor/bin/drush --uri=http://demo.drupalx.local dx:theme-list
vendor/bin/drush --uri=http://demo.drupalx.local dx:theme-list --format=json
vendor/bin/drush --uri=http://demo.drupalx.local dx:theme-apply harbor
vendor/bin/drush --uri=http://demo.drupalx.local dx:theme-status
vendor/bin/drush --uri=http://demo.drupalx.local dx:theme-status --format=json
```

## 工程落点

- 模块：`web/modules/custom/dx_theme`
- 目录：`dx_theme/data/catalog.yml`
- 皮肤 CSS：`web/themes/custom/dx_portal_theme/css/skins/*`
- 开通：`TenantProvisioner` 启用 `dx_theme`
- Onboarding hub 行：`theme_studio`
- 冒烟：`./scripts/ci/theme-smoke.sh`

## 与 Brand pack 的关系

Brand pack 管**品牌身份**（名 / 色 / Logo）；Theme Studio 管**门面气质与操作密度**。先选 pack，再微调白标色。
