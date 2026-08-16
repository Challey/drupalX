# X 项目 · Flutter App 打包（核心工具）

> **X 项目** = DrupalX。把可配置 Flutter 壳灌入租户 `api_base` + Channel Bearer token，产出可 `flutter run` / 出包的工程副本。

设计：[flutter-shell.md](flutter-shell.md) · Channel：[channel.md](channel.md)

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

产物默认：`~/staging/drupalX/flutter/<app>-flutter-deploy-latest/`

## 登记应用

1. 新增 `tools/flutter-packer/apps/<id>.manifest.yml`
2. `--validate` → `--app=` 打包

## 原则

- 壳只消费 DXEP Channel；不执行远程代码
- 不把平台 `.env` 打进包；仅注入 Channel token
- iOS：先出工程（F5-A）；签名由客户或托管 CI
- 旧 WebView `x-pack-android` 仅存量；新客默认本工具

## Agent Skill

Cursor skill：`x-pack-flutter`（`.cursor/skills/x-pack-flutter/`）。
