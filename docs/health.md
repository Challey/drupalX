# 健康检查（`dx_health`）

```bash
vendor/bin/drush pm:enable dx_health -y
vendor/bin/drush dx:health
vendor/bin/drush dx:health-tenant demo
./scripts/ci/health-smoke.sh
```

交付编排末步会写入验收 `health`（平台模块探测；租户缺失时 soft-ok，适配 `--skip-provision`）。

## HTTP 探针

平台检查会内核请求 `/deliver` 与 `/admin/dx/channel/audit`（403/401 视为存活，404/5xx 失败）。
