# 证书托管（`dx_certs`）

> EA：打包流水线证书/描述文件的**路径引用**托管，不在配置中存私钥明文。  
> 就绪探测会检查路径是否存在/可读、文件大小、可选过期日，并计算 **SHA-256**（仅指纹，非密钥）。

## Drush

```bash
vendor/bin/drush pm:enable dx_certs -y
vendor/bin/drush dx:certs-register demo_android \
  web/modules/custom/dx_certs/data/fixtures/demo.keystore \
  --platform=android --label=Demo
vendor/bin/drush dx:certs-status
vendor/bin/drush dx:certs-check --id=demo_android
vendor/bin/drush dx:certs-packer-env android
vendor/bin/drush dx:certs-revoke demo_android
```

`dx:certs-packer-env` 导出：

| 变量 | 说明 |
|------|------|
| `DX_CERT_PATH` | 解析后的路径 |
| `DX_CERT_PATH_REF` | 原始引用 |
| `DX_CERT_READY` | `1` / `0` |
| `DX_CERT_SHA256` | 文件指纹 |
| `DX_CERT_VAULT` | vault root（已展开 `~`） |

`pack-tenant-channels.sh` 会尽力导出 `DX_CERT_*` 供 packer 使用。真实签名仍依赖本机 SDK / CI 密钥库。

## 冒烟

```bash
./scripts/ci/certs-smoke.sh
```
