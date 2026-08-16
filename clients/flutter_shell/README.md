# DrupalX Flutter Shell

可配置壳：消费 DXEP `app-layout`（L1）与 Channel 内容（L2）。设计见 `docs/flutter-shell.md`。

## 配置

复制 `assets/config/shell.example.json` → `assets/config/shell.json`（打包时由 `x-pack-flutter` 注入）：

| 字段 | 说明 |
|------|------|
| `api_base` | 租户站根 URL |
| `bearer_token` | Channel token（D10-B） |
| `use_fixtures` | `true` 时离线读 `assets/fixtures`（开发默认） |
| `shell_version` | 与 `min_shell_version` 协商 |

## 本地运行

```bash
cd clients/flutter_shell
# 若尚无 android/ios 工程：
flutter create --project-name dx_flutter_shell --org com.drupalx --platforms=android,ios .
flutter pub get
flutter run
```

本仓库 WSL 若 Flutter 脚本为 CRLF，请在 Windows 侧或修好 line endings 后再 `flutter create`。

## 组件目录 v1

`hero_banner` · `notice_ticker` · `article_list` · `notice_list` · `product_grid` · `service_grid` · `profile_header` · `rich_html` · `web_link` · `empty` / 未知 type 跳过

## 打包

```bash
bash scripts/x-pack-flutter.sh --app=demo --api-base=https://demo.example.com --token=dxc_...
```
