# 健康检查（`dx_health`）

```bash
vendor/bin/drush pm:enable dx_health -y
vendor/bin/drush dx:health
vendor/bin/drush dx:health-tenant demo
./scripts/ci/health-smoke.sh
```

交付编排末步会写入验收 `health`（平台模块探测；租户缺失时 soft-ok，适配 `--skip-provision`）。
