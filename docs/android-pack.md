# X 项目 · Android App 打包（WebView 壳 1.3.0）

> **完整手册（独立文档）**：[skills/x-pack-android.md](skills/x-pack-android.md)
> Agent Skill：`x-pack-android`（`.cursor/skills/x-pack-android/SKILL.md`）
> 清单 schema：[manifest-pack.md](manifest-pack.md) · 三端同构：[isomorph-pack.md](isomorph-pack.md)

**X 项目** = DrupalX。把已登记应用打成 Android Studio 可打开的 WebView 工程（可选再编 APK）。

## 快速命令

```bash
cd /home/wwwroot/drupalX
bash scripts/x-pack-android.sh --list
bash scripts/x-pack-android.sh --validate --app=car_hailing_assistant
bash scripts/x-pack-android.sh --app=car_hailing_assistant \
  --start-url=https://www.topstar.run/driver
```

产物：`~/staging/drupalX/android/car_hailing_assistant-android-deploy-latest/`
＋ 仓库镜像 `upgrade/android/`（`.gitignore` 已忽略）＋ 同名 tar。
用 Android Studio 打开 → Sync → Build APK。

## 1.3.0 变化（只增不改）

壳模板版本从 1.2.x 升到 **1.3.0**（`template/app/build.gradle` 的 `SHELL_VERSION`、
`AndroidManifest.xml` 的 `x.shell_version`、`MainActivity.SHELL_VERSION` 三处一致）。
**已上架应用的 `applicationId` / `versionCode` / `version_name` 语义不变**；
1.3.0 只把原本硬编码在 `MainActivity.java` 里的东西搬到清单里，并新增可配置项：

| 能力 | 1.2.x | 1.3.0 | 清单字段 |
| --- | --- | --- | --- |
| 定位（`ACCESS_FINE/COARSE_LOCATION`、`GeolocationPermissions`） | 硬编码开 | 开关化，默认开 | `capabilities: [location]` |
| 语音（`RECORD_AUDIO` + WebView `onPermissionRequest`） | 硬编码开 | 开关化，默认开 | `capabilities: [microphone]` |
| 拍照/选图（`READ_MEDIA_IMAGES` + `READ_EXTERNAL_STORAGE≤32`） | 硬编码开 | 开关化，默认开 | `capabilities: [photo_upload]` |
| 支付/授权域名留在壳内 | 6 条硬编码 | 清单声明，默认= 同一集合 | `payment_hosts` / `payment_schemes` |
| 授权说明文案 | 写死在 `strings.xml` | 可按租户覆盖，默认= 现文案 | `permission_rationales` |
| 审计快照 | 仅 `x-app.json` 基础字段 | 增 `capabilities` / `payment_hosts` / `packed_at` | — |

**不声明新字段时，出包结果与 1.2.x 等价**——这条由 `tools/android-packer/tests/` 两套装用例把守（见下）。

## 接线：一份清单 → 三个文件

```
tools/android-packer/apps/<app>.manifest.yml
        │  tools/packer/validate_manifest.py（DX-PACK-MANIFEST 门禁 + 默认值合并）
        ▼
tools/android-packer/lib/shell_codegen.py（token / 结构块改写）
        ├─ MainActivity.java   // BEGIN DX_PAYMENT … END
        │     PAYMENT_HOSTS = new String[][] { {"wx.tenpay.com","exact"}, … }
        │     PAYMENT_SCHEMES = new String[] { "weixin", … }
        │     SHELL_VERSION   = "1.3.0"
        │     判定：PaymentHostPolicy.matchesAny(h, PAYMENT_HOSTS)
        ├─ AndroidManifest.xml // BEGIN DX_PERMISSIONS（按 capabilities 生成 uses-permission）
        │     meta：x.shell_version / x.payment_hosts / x.payment_schemes（便于审计与抓取核对）
        ├─ res/values/strings.xml  payment_scope_summary=<自动生成的隐私说明>，rationale 可覆盖
        └─ app/build.gradle    CAP_LOCATION / CAP_MICROPHONE / CAP_PHOTO_UPLOAD（BuildConfig 开关）
```

`match` 语义（`PaymentHostPolicy`，纯 JDK 实现，可离线编译验证）：

| `match` | 含义 | 例 |
| --- | --- | --- |
| `exact` | 主机名完全相等 | `wx.tenpay.com` |
| `domain` | 该域及其**任意深度**子域（含自身） | `pay.weixin.qq.com` |
| `child` | 该域的**子域**，不含自身 | `tenpay.com` |
| `contains` | 主机名包含该子串 | `alipay.com` |

默认 6 条规则 = 1.2.x 硬编码集合（`wx.tenpay.com:exact`、`tenpay.com:child`、
`pay.weixin.qq.com:domain`、`open.weixin.qq.com:exact`、`alipay.com:contains`、
`alipayobjects.com:contains`），**不得删改**：删掉任何一条都会让已交付客户当场支付失败。

## 命令行

```bash
# 追加支付域名（可重复；只追加，不删除清单里的默认项）
bash scripts/x-pack-android.sh --app=car_hailing_assistant \
  --payment-host=pay.partner.example:domain

# 临时收敛能力（例如某客户不要语音）
bash scripts/x-pack-android.sh --app=car_hailing_assistant --capability=location,photo_upload

# 演练：产物与镜像都不落仓库/生产 staging
W=$(mktemp -d)
bash scripts/x-pack-android.sh --app=car_hailing_assistant \
  --out="$W/android" --mirror-dir="$W/android-mirror"

# 只校验清单，不出包
bash scripts/x-pack-android.sh --validate --app=car_hailing_assistant
```

| 参数 | 说明 |
| --- | --- |
| `--app=` / `--list` / `--validate` | 既有语义不变；`--list` 现在直接读门禁注册表（与文档同源） |
| `--start-url=` / `--allowed-host=` / `--application-id=` | 既有覆盖项 |
| `--shell-version=` | 覆盖模板版本（仅调试；正式出包写清单） |
| `--capability=` | 覆盖能力开关（逗号分隔） |
| `--payment-host=host:match` | **追加**一条支付规则，可重复 |
| `--out=` / `--mirror-dir=` | 重定向产物与仓库镜像；亦可用 `X_ANDROID_OUT_DIR` / `X_ANDROID_MIRROR_DIR` |
| 未知参数 | **一律 exit 1**，绝不出包 |

## 离线回归（不需要 Android SDK）

```bash
cd /home/wwwroot/drupalX
python3 tools/android-packer/tests/test_shell_pack.py           # 41 项：默认值等价 + 接线落点 + 残留 token 扫描
bash  tools/android-packer/tests/test_payment_host_policy.sh    # javac 编译 PaymentHostPolicy + 24 主机矩阵对拍 1.2.x 表达式
bash  scripts/ci/packer-smoke.sh                                # 端到端：门禁、三端 --list 一致、真实出包演练、无污染断言
```

`test_payment_host_policy.sh` 用**合成清单**（不声明任何新字段）出包，再从生成的
`MainActivity.java` 里解析 `PAYMENT_HOSTS` 表，用编译出来的 `PaymentHostPolicy`
逐个主机判定，与 1.2.x 的原始 Java 表达式对拍（主机矩阵在
`tools/android-packer/tests/whitelist-hosts.txt`，两套用例共用）。
没装 JDK 的机器上该用例自动 `SKIP` 并退出 0——`packer-smoke.sh` 会明确打印它是否真跑过。

## 需真机 / SDK 的构建步骤（本地无法完成）

```bash
cd ~/staging/drupalX/android/car_hailing_assistant-android-deploy-latest
export JAVA_HOME=/path/to/jdk-17
export ANDROID_HOME=/path/to/Android/Sdk
./gradlew :app:assembleDebug          # 首次需联网下载 Gradle 与 AGP 依赖
# 或：Android Studio 打开该目录 → Sync → Build APK(s)
```

装到真机后的回归清单（授权弹窗文案 + 三项能力）：

1. 打开 `START_URL` → 「定位」→ 系统弹窗出现且文案 = `strings.xml` 的 `location_rationale` → 允许后 H5 拿到坐标；
2. AI 问道语音 → `mic_title` / `mic_rationale` 弹窗 → 录音可用；
3. 现场热点/问题反馈选图 → `photo_title` / `photo_rationale` → 相册可选；
4. 微信支付 / 支付宝 H5 收银台**留在壳内**（不外跳），非白名单外链**交系统浏览器**；
5. `adb shell dumpsys package run.topstar.driver | grep -i permission` 复核权限集与 `capabilities` 一致。

`--assemble` 参数会在打包后直接调 Gradle，需要上述 SDK；本仓库 CI 不使用它。

## 排障

| 现象 | 原因 / 处置 |
| --- | --- |
| `ERROR: unreplaced token __XXX__` | 清单缺字段或 codegen 未覆盖该 token；`--validate` 先看门禁 |
| 支付页外跳到浏览器 | `payment_hosts` 未覆盖该主机；用 `--payment-host=host:mode` 复现，确认后写进清单 |
| 权限弹窗文案不对 | `permission_rationales` 覆盖键写错（仅接受 `location`/`microphone`/`photo`） |
| 生成的工程 `upgrade/` 里出现 | 演练请带 `--out=` / `--mirror-dir=`，勿直跑生产默认路径 |

上架、签名、`network_security_config.xml`、图标替换等 **全部细节** 见
[skills/x-pack-android.md](skills/x-pack-android.md)；并列工具：
[skills/x-pack-miniprogram.md](skills/x-pack-miniprogram.md)、[miniprogram-pack.md](miniprogram-pack.md)。
