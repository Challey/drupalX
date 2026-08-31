# X 项目 · Flutter App 打包（核心工具）

> **X 项目** = DrupalX。把可配置 Flutter 壳灌入租户 `api_base` + Channel Bearer token，产出可 `flutter run` / 出包的工程副本。

设计：[flutter-shell.md](flutter-shell.md)（组件目录 v2 见其 §16）· Channel：[channel.md](channel.md)
· 清单 schema：[manifest-pack.md](manifest-pack.md) · 三端字段契约：[isomorph-pack.md](isomorph-pack.md)

## 命令

```bash
cd /home/wwwroot/drupalX

bash scripts/x-pack-flutter.sh --list
bash scripts/x-pack-flutter.sh --validate --app=demo

bash scripts/x-pack-flutter.sh --app=demo \
  --api-base=https://demo.example.com \
  --token=dxc_... \
  --tenant=demo
```

产物默认：`~/staging/drupalX/flutter/<app>-flutter-deploy-latest/` ＋ tar ＋ 仓库镜像
`upgrade/flutter/<app>-flutter-deploy-latest/`（gitignored）。三处内容逐字节相同，且都含 `FILE-LIST.txt`。

## 参数

| 参数 | 说明 |
| --- | --- |
| `--app=` / `--list` / `--validate` | `--list` 直接读 DX-PACK-MANIFEST 注册表（与文档、门禁同源） |
| `--api-base=` / `--token=` / `--tenant=` | 注入 `assets/config/shell.json`；`--token=` 只走命令行，**永不写进清单** |
| `--shell-version=` | 覆盖清单里的 `shell_version`（调试用） |
| `--layout-fixture=` | 离线演示版式，**必须**是 `assets/fixtures/*.json`（默认 `app_layout_gov.json`）；越界路径直接 exit 1 |
| `--out=` / `--mirror-dir=` | 重定向产物/镜像，亦可用 `X_FLUTTER_OUT_DIR` / `X_FLUTTER_MIRROR_DIR`（CI 演练用） |
| 未知参数 | 一律 exit 1 |

## 门禁与注入内容

出包前 `--platform=flutter` 先过 `tools/packer/validate_manifest.py`（DX-PACK-MANIFEST），
再解析成一份扁平配置（schema 默认 ← 清单 ← 命令行覆盖）。生成的 `assets/config/shell.json`：

```json
{
  "api_base": "https://demo.example.com",
  "tenant_id": "demo",
  "bearer_token": "dxc_...",
  "shell_version": "1.0.0",
  "use_fixtures": false,
  "poll_seconds": 60,
  "layout_fixture": "assets/fixtures/app_layout_gov.json"
}
```

`--validate` 会检查 `pubspec.yaml`、`lib/main.dart`、`assets/config/component_catalog.json`
与所选 `layout_fixture` 是否都在壳内，并打印
`OK validate demo (shell 1.0.0 / catalogue 2 / profile gov_default)`。
`catalogue` 数字就是组件目录的 `schema_version`（H2 起为 2），Flutter 清单的 `catalog_version`
默认值与之对齐，改了目录必须同时改这里，否则 `scripts/ci/packer-smoke.sh` 会红。

## 登记应用

1. 新增 `tools/flutter-packer/apps/<id>.manifest.yml`（字段表见 [manifest-pack.md](manifest-pack.md)）
2. `bash scripts/x-pack-flutter.sh --validate --app=<id>` → `--app=<id>` 打包

## 构建（需 Flutter SDK，本仓库 CI 不执行）

```bash
cd ~/staging/drupalX/flutter/demo-flutter-deploy-latest
flutter --version                 # 需要 Flutter 3.16+（首次 pub get 要联网）
flutter pub get
flutter test                      # 19 个离线用例（见 docs/flutter-shell.md §16.4）
flutter run -d linux              # 或 -d <android-device-id>
flutter build apk --release
flutter build ipa                 # iOS 需 Apple 账套（F5-A：客户或托管 CI 签名）
```

若工程里没有 `android/` `ios/` 目录（仓库不入库）：
`flutter create --project-name dx_flutter_shell --org com.drupalx --platforms=android,ios .`

**没有 SDK 时的等价门禁**（本线实际执行，纯静态）：

```bash
python3 tools/clients/isomorph_check.py catalog   # 目录 ↔ 注册表 ↔ widget 文件 ↔ wxml ↔ 夹具
python3 tools/clients/isomorph_check.py dart      # Dart 语法/import 静态一致性
bash scripts/ci/flutter-shell-smoke.sh
```

## 原则

- 壳只消费 DXEP Channel；不执行远程代码（封闭组件目录，F2-A）
- 不把平台 `.env` 打进包；仅注入 Channel token（`assets/config/shell.json` 视为机密）
- iOS：先出工程（F5-A）；签名由客户或托管 CI
- 旧 WebView `x-pack-android` 仅存量；新客默认本工具

## Agent Skill

Cursor skill：`x-pack-flutter`（`.cursor/skills/x-pack-flutter/`）。
