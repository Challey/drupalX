# 证书托管占位（`dx_certs`）

> EA 后续：打包流水线证书/描述文件的**路径引用**托管，不在配置中存私钥明文。

## Drush

```bash
vendor/bin/drush pm:enable dx_certs -y
vendor/bin/drush dx:certs-register demo_android ~/staging/drupalX/certs/android/demo.keystore --platform=android
vendor/bin/drush dx:certs-status
vendor/bin/drush dx:certs-packer-env android
```

`pack-tenant-channels.sh` 会尽力导出 `DX_CERT_*` 环境变量供 packer 使用。

## 冒烟

```bash
./scripts/ci/certs-smoke.sh
```
