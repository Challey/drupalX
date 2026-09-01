# X 项目 · 微信小程序打包（核心工具）

> **完整手册（独立文档）**：[skills/x-pack-miniprogram.md](skills/x-pack-miniprogram.md)
> Agent Skill：`x-pack-miniprogram`（`.cursor/skills/x-pack-miniprogram/SKILL.md`）
> 清单 schema：[manifest-pack.md](manifest-pack.md) · 三端同构：[isomorph-pack.md](isomorph-pack.md)

**X 项目** = DrupalX。把已登记应用打成可导入微信开发者工具的小程序包。

## 快速命令

```bash
cd /home/wwwroot/drupalX
bash scripts/x-pack-miniprogram.sh --list
bash scripts/x-pack-miniprogram.sh --validate --app=car_hailing_assistant
bash scripts/x-pack-miniprogram.sh --app=car_hailing_assistant \
  --api-base=https://www.topstar.run

# 无外部仓库依赖的演练（源码就在本仓库）
W=$(mktemp -d)
bash scripts/x-pack-miniprogram.sh --app=drupalx_portal \
  --api-base=https://demo.example.com --out="$W/mp" --mirror-dir="$W/mp-mirror"
```

产物：`~/staging/drupalX/miniprogram/car_hailing_assistant-mp-deploy-latest/` ＋ tar
＋ 仓库镜像 `upgrade/miniprogram/`（gitignored），三处都含 `FILE-LIST.txt`。

## 参数

| 参数 | 说明 |
| --- | --- |
| `--app=` / `--list` / `--validate` | `--list` 读 DX-PACK-MANIFEST 注册表；`--validate` 只跑 schema + 源码/页面结构检查 |
| `--api-base=` / `--traffic-api-base=` / `--appid=` | 覆盖清单值并注入 `config.js` |
| `--out=` / `--mirror-dir=` | 重定向产物与仓库镜像；亦可用 `X_MP_OUT_DIR` / `X_MP_MIRROR_DIR` |
| 未知参数 | 一律 exit 1 |

**H4 起不再用 `awk` / `grep` 抠 YAML**：清单先过 `tools/packer/validate_manifest.py`，
再由解析后的扁平配置取值（`source`、`config.apiBase`、`pages_required`…），缩进变化不会再静默取空值。

## 已登记应用

| app_id | 源码 | 性质 |
| --- | --- | --- |
| `car_hailing_assistant` | 应用仓库（清单 `source_fallback` / `CAR_HAILING_ROOT` 解析） | **已交付客户契约**，字段语义不得改 |
| `drupalx_portal` | `clients/wechat-miniprogram`（本仓库） | H4 新增的 CI 演练应用，非客户契约 |

门面壳（`clients/wechat-miniprogram`）与 Flutter 壳共用同一套 L1/L2 JSON：
`utils/dxep.js` 拉 `/api/dx/v1/channel/*`、按 `fixtures/` 离线兜底，
`utils/field_contract.js` 是 `clients/field-contract.json` 的**生成镜像**（勿手改）。

## 离线门禁（不需要微信开发者工具）

```bash
cd /home/wwwroot/drupalX
python3 tools/clients/isomorph_check.py mp          # app.json pages / require / fixture 注册 / wxml 标签配平
python3 tools/clients/isomorph_check.py catalog     # 组件目录 v2 ↔ wxml 渲染分支
python3 tools/clients/sync_fixtures.py --check      # Flutter 夹具 → 小程序夹具 是否漂移
bash scripts/ci/miniprogram-shell-smoke.sh          # 以上三项 + 结构断言
bash scripts/ci/packer-smoke.sh                     # 真实出包演练（产物只落临时目录）
```

## 需微信开发者工具的验证步骤（本地无法完成）

```bash
# 1) 出包（或用上面演练目录）
bash scripts/x-pack-miniprogram.sh --app=drupalx_portal --api-base=https://<tenant-host>
# 2) 微信开发者工具 → 导入项目 → 选择 …/drupalx_portal-mp-deploy-latest
#    appid 用 touristappid（体验版额度）或客户正式 appid
# 3) 详情页 Network 面板应看到 /api/dx/v1/channel/{site,app-layout,contents}
# 4) 关网或 token 留空 → 应回落 fixtures 渲染，不白屏
```

必须人工确认的项：`request` 合法域名白名单（小程序后台配置）、`app.json` 的 `pages` 顺序、
真机上 `index.wxml` 各 block 类型的排版、下拉刷新与分页。

## 排障

| 现象 | 处置 |
| --- | --- |
| `ERROR: cannot resolve mini-program source` | 清单 `source` / `source_fallback` 未命中；导出 `CAR_HAILING_ROOT` 或改清单 |
| `MISSING page: pages/x/x` | `pages_required` 与 `app.json` 不一致（门禁会同时列出两处） |
| `FAIL mp.wxml_tags:view :: …` | WXML 标签开合数量不匹配，报错含具体文件路径 |
| `DRIFT utils/… != …` / `FAIL N fixture mirror(s) out of sync` | 改了 Flutter 夹具没同步：`python3 tools/clients/sync_fixtures.py --write` |

部署、Manifest、权限、上架与排障等 **全部细节** 见
[skills/x-pack-miniprogram.md](skills/x-pack-miniprogram.md)；
并列工具：[skills/x-pack-android.md](skills/x-pack-android.md)、[android-pack.md](android-pack.md)。
