# DXEP Channel（`dx_channel`）

> Phase FS1：Channel 只读网关 + App Layout L1。  
> 规范：[data-exchange.md](data-exchange.md) · 壳设计：[flutter-shell.md](flutter-shell.md)

## 端点

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/api/dx/v1/channel/site` | 站点名片 |
| GET | `/api/dx/v1/channel/app-layout` | Flutter/小程序版式；`?since_revision=` → 304 |

一律需要：`Authorization: Bearer <token>`（拍板 D10-B）。

## Drush

```bash
vendor/bin/drush pm:enable dx_channel -y
vendor/bin/drush dx:channel-token-create --id=flutter --scopes=channel:read
vendor/bin/drush dx:channel-token-list
vendor/bin/drush dx:channel-layout-status
vendor/bin/drush dx:channel-layout-bump
```

管理页：`/admin/dx/channel`

## 布局档案

- `gov_default` — 政务底栏（首页/资讯/公告/服务/我的）
- `ent_default` — 企业底栏（首页/产品/动态/我的）

配置项：`dx_channel.settings`（profile、revision、capabilities、min_shell_version）。

## 冒烟

```bash
./scripts/ci/channel-smoke.sh
# 或带 HTTP：
# DX_CHANNEL_SMOKE_BASE=https://demo.example.com ./scripts/ci/channel-smoke.sh
```

## OpenAPI

[openapi/dxep-v1.yaml](openapi/dxep-v1.yaml)
