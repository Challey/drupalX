# X 项目 · 三端数据字段契约与同构冒烟（L3 Phase H2/H3）

> **X 项目** = DrupalX。本文件说明 `clients/field-contract.json`（**DX-FIELD-CONTRACT**）：
> Web / Flutter / 小程序三端**必须一致**的 L1/L2 数据字段清单，以及把这条清单变成可执行门禁的
> `scripts/ci/clients-isomorph-smoke.sh`。
> 相关：[channel.md](channel.md) · [flutter-shell.md](flutter-shell.md) · [miniprogram-pack.md](miniprogram-pack.md) · [manifest-pack.md](manifest-pack.md)

## 一句话

服务端 `dx_channel` 吐出的每个字段，凡是某一端要读的，都必须在契约里登记一次；
门禁会在**每个需要的端**上找到它、并核对类型。新增字段只写服务端 → CI 立刻指出**哪个端、哪个文件**缺。

## 文件

| 路径 | 角色 |
| --- | --- |
| `clients/field-contract.json` | 唯一真源（手工维护，81 个字段） |
| `clients/flutter_shell/lib/dxep/field_contract.dart` | Flutter 镜像（**生成物**，`mirror --write`） |
| `clients/wechat-miniprogram/utils/field_contract.js` | 小程序镜像（**生成物**） |
| `clients/flutter_shell/assets/fixtures/*.json` | L1/L2 fixture 源（6 组） |
| `clients/wechat-miniprogram/fixtures/*.js` | fixture 镜像（`sync_fixtures.py` 生成） |
| `tools/clients/isomorph_check.py` | 门禁本体（纯静态） |
| `tools/clients/sync_fixtures.py` | Flutter fixture → 小程序 fixture 同步 |

## 记法

`<resource>.<dotted.path>`，其中

* `[]` 表示列表元素：`content[].id`
* `*` 表示动态 map 键：`layout.pages.*.blocks[].type`
* 类型词表：`boolean` `integer` `number` `string` `nullable_string` `rfc3339` `list` `map` `opaque`（+ `enum`）
* `presence: conditional` 表示服务端只在满足条件时产出（如 `meta` 只在有分页/修订号时出现）
* `scope: product|detail|article` 表示只在某类 fixture 中要求出现
* `layout.pages.*.blocks[].props` 是 **free map**：内容由组件目录（H2）约束，不逐字段登记

## 每端怎么被证明（anchor）

| 端 | 锚定方式 | 缺项时输出 |
| --- | --- | --- |
| `server` | 在 `dx_channel` 的 Projector / Controller / Repository 里 grep 键名 | `fields.anchor:<path>:server :: end=server: /'sku'\s*(?:=>|\])/ not found in [...]` |
| `web` | `dx_portal`（**只读**）里已存在的显式 `anchors.web` 正则 | 同上，附候选文件 |
| `flutter` | 镜像文件里的 `'<path>'` 字面量 | 同上 |
| `miniprogram` | 镜像文件里的 `'<path>'` 字面量 | 同上 |

Web 端属于 L5/门面线，本线不可写，因此只允许「锚定既有 `dx_portal` 真实用法」：
当前 7 个字段（`id/title/sku/price/summary/url/created`）用显式正则锚定，其余字段 `ends` 不声明 `web`。
两端不一致处记在 `contract.divergences`（价格形态、详情链接来源、站点标题、发布时间格式化），
供后续门面线一次性收敛。

## 命令

```bash
cd /home/wwwroot/drupalX

# 全量（catalog + fields + fixtures + dart + mp）
python3 tools/clients/isomorph_check.py all

# 单模式排障
python3 tools/clients/isomorph_check.py catalog      # 组件目录 v2 ↔ 注册表 ↔ widget ↔ wxml ↔ fixture
python3 tools/clients/isomorph_check.py fields       # 81 字段 × 4 端锚点
python3 tools/clients/isomorph_check.py fixtures     # 6 组 fixture 两端逐字段比对
python3 tools/clients/isomorph_check.py dart         # 无 SDK 的 Dart 静态检查（括号/import）
python3 tools/clients/isomorph_check.py mp           # app.json pages / require / fixture 注册 / wxml 配平

# 镜像生成（改了契约必须跑）
python3 tools/clients/isomorph_check.py mirror --write
python3 tools/clients/isomorph_check.py mirror            # 只校验，CI 用
python3 tools/clients/sync_fixtures.py --write            # 同步小程序 fixture
python3 tools/clients/sync_fixtures.py --check            # 只校验漂移

# 官方入口（本线四个冒烟之一）
bash scripts/ci/clients-isomorph-smoke.sh
```

退出码：`0` 全通过 · `1` 有 FAIL · `2` 门禁自身无法运行（缺输入），后者绝不会被当成绿灯。

## 新增一个字段（唯一正确姿势）

1. 服务端 `SiteProjector` / `ContentProjector` / `AppLayoutRepository` 产出该键；
2. 在 `clients/field-contract.json` 的 `fields` 追加一条：`path` / `layer` / `resource` / `type` /
   `required` / `since` / `ends`（需要它的端）/ 必要时 `anchors`；
3. `python3 tools/clients/isomorph_check.py mirror --write`（两端镜像）；
4. 若 fixture 里有值，更新 `clients/flutter_shell/assets/fixtures/*.json` 后
   `python3 tools/clients/sync_fixtures.py --write`；
5. 漏了任何一步，`fields` / `fixtures` / `mirror` 三段中必有一段报出**端名 + 文件路径**。

实测（在临时副本里给契约加一个 `content.delivery_eta`，其余端不接线）：

```
FAIL fields.anchor:content.delivery_eta:flutter :: end=flutter: /'content\.delivery_eta'/ not found in
     ['clients/flutter_shell/lib/dxep/field_contract.dart', '.../envelope.dart', '.../channel_client.dart']
     - this end never names `delivery_eta` (add it, or drop 'flutter' from the field's ends)
FAIL fields.anchor:content.delivery_eta:miniprogram :: end=miniprogram: /'content\.delivery_eta'/ not found in
     ['clients/wechat-miniprogram/utils/field_contract.js'] ...
FAIL fields.mirror.flutter :: clients/flutter_shell/lib/dxep/field_contract.dart is out of sync with
     clients/field-contract.json: missing=content.delivery_eta extra=- retyped=-
```

补跑 `mirror --write` 后 `flutter` / `miniprogram` 两端即转绿（server 端仍需真的投影该键）。

## 组件目录同构（H2 侧）

`catalog` 模式把 `clients/flutter_shell/assets/config/component_catalog.json` 与以下四处对齐：

* `lib/layout/component_catalog.dart`（Dart 侧目录载入 + 校验）
* `lib/layout/block_registry.dart`（`switch` 分支 ↔ 目录组件）
* `lib/widgets/blocks/*.dart`（widget 文件存在性）
* `clients/wechat-miniprogram/utils/dxep.js` + `pages/index/index.wxml`（小程序渲染分支）

并断言 1.2.x 已交付的 12 个冻结类型（`hero_banner` … `error`）永不消失，
`app_layout_gov.json` 只含 `since: 1` 的类型（现网版式不会因为目录升级而变样）。

## 已知边界

* 门禁是**静态**的：它证明字段名/类型/接线存在，不证明运行时渲染正确 —— 那需要 `flutter test` 与微信开发者工具。
* Web 端只锚既有实现，字段是否真的在 twig 里被消费，仍以 `dx_portal`（L5 线）为准。
* `opaque` / free map 之下的子结构故意不下钻（`props` 由组件目录约束）。
