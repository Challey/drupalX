# 健康检查（`dx_health`）

> 索引：[README.md](README.md) · 交付验收：[delivery.md](delivery.md)

```bash
vendor/bin/drush pm:enable dx_health -y
vendor/bin/drush dx:health
vendor/bin/drush dx:health-tenant demo
./scripts/ci/health-smoke.sh
```

交付编排末步会写入验收 `health`（平台模块探测；租户缺失时 soft-ok，适配 `--skip-provision`）。

## HTTP / 路由探针

平台检查优先校验关键路由存在（`/deliver`、审计页、Channel site API），再对 `/api/dx/v1/channel/site` 做一次轻量内核请求（401/403/200 均视为存活）。避免对 `/deliver` 全页探测导致健康检查递归。

## 栈就绪

```bash
vendor/bin/drush dx:stack-status
./scripts/ci/stack-status-smoke.sh
```
