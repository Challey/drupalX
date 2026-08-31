# X 项目 · 出包清单 schema（三端统一，L3 Phase H4）

> **X 项目** = DrupalX。本文件是 `tools/packer/manifest-schema.json`（**DX-PACK-MANIFEST**）的说明，
> 三端（Android / 小程序 / Flutter）出包清单的唯一真源。
> 相关：[android-pack.md](android-pack.md) · [miniprogram-pack.md](miniprogram-pack.md) · [flutter-pack.md](flutter-pack.md) · [packer-pipeline.md](packer-pipeline.md)

## 为什么需要一份 schema

H4 之前，三个打包脚本各自用 `grep` / `awk` 从 YAML 里抠字段：

| 端 | 旧行为 | 后果 |
| --- | --- | --- |
| `x-pack-android.sh` | 自带 `yaml_get()` | 字段名与小程序不一致（`display_name` vs `label`） |
| `x-pack-miniprogram.sh` | `awk` 抠 `config:` 子树 | 缩进变化即静默取空值 |
| `x-pack-flutter.sh` | `grep -E '^shell_version:'` | 清单缺项时回落到脚本内写死的默认值 |
| `x-pack-miniprogram-portal.sh` | 位置参数 | `--list` 被当成 `api_base`，**直接产出了一个交付包** |

现在：**schema 在 `tools/packer/manifest-schema.json`，校验与合并在 `tools/packer/manifest_lib.py`，
三个打包脚本一律先过门禁、再读解析后的配置**。

## 校验入口（CI 用）

```bash
cd /home/wwwroot/drupalX

bash scripts/x-pack-manifest.sh --platforms              # 三端名单
bash scripts/x-pack-manifest.sh --list                   # android: … / flutter: … / miniprogram: …
bash scripts/x-pack-manifest.sh --list --json            # 机读注册表
bash scripts/x-pack-manifest.sh --list --platform=android --names-only   # 裸 id 列表
bash scripts/x-pack-manifest.sh --all                    # 全部清单校验（exit 1 = 有不合规）
bash scripts/x-pack-manifest.sh --platform=android --app=car_hailing_assistant
bash scripts/x-pack-manifest.sh --schema --platform=android   # 字段表（本文件表格由它抄写）
bash scripts/x-pack-manifest.sh --schema --json           # 机读字段表
bash scripts/x-pack-manifest.sh --resolve --platform=android \
  --app=car_hailing_assistant                            # 解析后的扁平配置（JSON）
bash scripts/x-pack-manifest.sh --json --all             # 无着色，CI 归档
bash scripts/x-pack-manifest.sh --no-yaml --all          # 强制走内置 YAML 子集解析器
python3 tools/packer/validate_manifest.py --all          # 同一入口的裸调用
```

退出码：`0` 全部通过 · `1` 有清单不合规 / 找不到清单 · `2` 用法错误。
依赖：`python3` 标准库即可；装了 PyYAML 就用 PyYAML，没装自动降级到内置解析器（`--no-yaml` 可强制验证降级路径）。

## 通用字段（三端同名同义）

| 字段 | 类型 | 必填 | 默认 | 说明 |
| --- | --- | --- | --- | --- |
| `app_id` | string | ✔ | — | 机名，等于文件名 `<app_id>.manifest.yml`；`id` 为历史别名（Flutter 清单沿用），二者等价 |
| `label` | string | ✔ | — | 人读名称（交付单、打包日志） |
| `brand_name` | string | | `=label` | 端上显示名 |
| `project_name` | string | | `=app_id` | 工程目录名 |
| `notes` | string | | — | 运营备注，不参与生成 |

## 端专属字段

### android（`tools/android-packer/apps/`）

| 字段 | 类型 | 必填 | 默认 | 说明 |
| --- | --- | --- | --- | --- |
| `application_id` | string | ✔ | — | Android applicationId，**已上架应用不得改动** |
| `start_url` | string(https) | ✔ | — | WebView 首屏 |
| `allowed_host` | string | | `=host(start_url)` | 允许留在壳内的主机 |
| `version_code` | integer | | `1` | 只增不减 |
| `version_name` | string | | `1.0.0` | 交付客户的版本号 |
| `shell_version` | string | | `1.3.0` | 模板（壳）版本 |
| `capabilities` | list | | `location,microphone,photo_upload` | 枚举三项；默认 = 今天线上行为 |
| `payment_hosts` | list&lt;host_rule&gt; | | 6 条 | **默认值等于 1.2.x 硬编码集合**，见 [android-pack.md](android-pack.md) |
| `payment_schemes` | list | | `weixin,wechat,alipays,alipay` | 交给系统（支付 App）的 scheme |
| `permission_rationales` | map&lt;string,string&gt; | | `{}` | 授权说明文案覆盖，键限 `location/microphone/photo` |

`host_rule = {host, match}`，`match ∈ exact | domain | child | contains`。

### miniprogram（`tools/miniprogram-packer/apps/`）

| 字段 | 类型 | 必填 | 默认 | 说明 |
| --- | --- | --- | --- | --- |
| `source` | string | | `""` | 源码目录（绝对或相对仓库根） |
| `source_fallback` | list | | `[]` | 按序尝试的候选 |
| `appid` | string | | `touristappid` | `touristappid` 或 `wx[0-9a-f]{16}` |
| `config` | map | | `{}` | `apiBase` / `trafficApiBase` / `clientId`，注入 `config.js` |
| `pages_required` | list | | `[]` | 出包前必须存在的页面路径 |

### flutter（`tools/flutter-packer/apps/`）

| 字段 | 类型 | 必填 | 默认 | 说明 |
| --- | --- | --- | --- | --- |
| `application_id` | string | | `com.drupalx.<app_id>` | Android applicationId |
| `bundle_id` | string | | `=application_id` | iOS bundle id |
| `display_name` | string | | `=label` | 桌面显示名 |
| `layout_profile` | string | | `gov_default` | `gov_default` / `ent_default` |
| `shell_version` | string | | `1.0.0` | 注入 `assets/config/shell.json` |
| `catalog_version` | integer | | `2` | 与组件目录 `schema_version` 对齐，见 [flutter-shell.md](flutter-shell.md) |

## 已登记应用（与 `--list` 实测输出一致）

```
$ bash scripts/x-pack-android.sh --list
Registered X Android apps:
car_hailing_assistant

$ bash scripts/x-pack-flutter.sh --list
Registered X Flutter apps:
demo

$ bash scripts/x-pack-miniprogram.sh --list
Registered X mini-program apps:
car_hailing_assistant
drupalx_portal
```

三个 `--list` 现在都是 `python3 tools/packer/validate_manifest.py --list --platform=<端> --names-only`
的一层薄封装（不再 `find` / `ls` 目录扫描），所以文档、门禁、脚本不可能各说各话。
`scripts/ci/packer-smoke.sh` 第 5 步逐端 `diff` 二者输出，不一致即 FAIL。

`drupalx_portal` 是 H4 新增的**演练应用**：源码就在本仓库 `clients/wechat-miniprogram`，
让 CI 能在不接触外部仓库、不装微信开发者工具的前提下做一次真实出包。它不是客户契约文件。

## 兼容性规则

1. **未知字段 = warning**：运营在清单里加备注字段不会打断出包。
2. **必填缺失 = error**：`app_id` / `label` /（android）`application_id` `start_url`。
3. **默认值即线上行为**：老清单不写新字段（`shell_version` / `payment_hosts` / `capabilities`）时，
   生成结果与 1.2.x 等价——这条由 `tools/android-packer/tests/` 两套装用例把守（41 项 + 24 主机白名单等价矩阵）。
4. **密钥不入清单**：`bearer_token` / `token` 只能作为打包命令行参数注入。
5. **`--list` 之外不改命令行**：所有既有参数（`--app=` `--validate` `--out=` `--api-base=` `--start-url=` …）语义不变；
   新增项（`--mirror-dir=` `--payment-host=` `--capability=` `--shell-version=` `--layout-fixture=` `--names-only`）
   全部可选，且**未知参数一律 exit 1**（portal 脚本以前会把未知参数当 `api_base`）。

## 出包演练（产物只落临时目录）

```bash
W=$(mktemp -d)
bash scripts/x-pack-android.sh --app=car_hailing_assistant \
  --out="$W/android" --mirror-dir="$W/android-mirror" \
  --payment-host=pay.partner.example:domain
bash scripts/x-pack-flutter.sh --app=demo --api-base=https://demo.example.com \
  --token=dxc_placeholder --out="$W/flutter" --mirror-dir="$W/flutter-mirror" \
  --layout-fixture=assets/fixtures/app_layout_v2.json
bash scripts/x-pack-miniprogram.sh --app=drupalx_portal \
  --api-base=https://demo.example.com --out="$W/mp" --mirror-dir="$W/mp-mirror"
```

`--out=` / `--mirror-dir=`（或 `X_ANDROID_OUT_DIR` / `X_FLUTTER_OUT_DIR` / `X_MP_OUT_DIR` /
`X_ANDROID_MIRROR_DIR` / `X_FLUTTER_MIRROR_DIR` / `X_MP_MIRROR_DIR`）覆盖后，仓库 `upgrade/`
与 `~/staging/` 都不会被写入；`scripts/ci/packer-smoke.sh` 第 8 步会断言这一点。

三端产物目录、tar 与 mirror 现在都含同一份 `FILE-LIST.txt`（此前只有零散目录有，tar 与 mirror 缺）。

## 相关冒烟

* `bash scripts/ci/packer-smoke.sh` —— 本 schema + H1 壳回归 + 三端出包演练（H1/H4 验收）
* `bash scripts/ci/clients-isomorph-smoke.sh` —— 三端数据字段与组件目录同构（H3）
