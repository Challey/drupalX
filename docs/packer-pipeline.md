# 多端打包流水线（EA）

> Flutter 壳 + 微信小程序出包；证书托管仍为后续项。

## 脚本

| 脚本 | 作用 |
|------|------|
| `scripts/pack-tenant-channels.sh` | 交钥匙一键：Flutter + 小程序 |
| `scripts/x-pack-flutter.sh` | Flutter shell 配置注入与打包入口 |
| `scripts/x-pack-miniprogram-portal.sh` | 小程序 portal 模板出包 |

## 用法

```bash
bash scripts/pack-tenant-channels.sh \
  --api-base=https://demo.drupalx.local \
  --token=dxc_... \
  --tenant=demo \
  --app=demo
```

产物目录（默认）：

- `~/staging/drupalX/flutter/demo-flutter-deploy-latest`
- `~/staging/drupalX/miniprogram/portal-mp-deploy-latest`

## 门禁冒烟

```bash
./scripts/ci/packer-smoke.sh
```

仅校验脚本存在、参数帮助可用，以及 Flutter/小程序工程夹具完整；**不**要求本机 Android SDK / 证书。
