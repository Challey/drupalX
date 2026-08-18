# DXEP Channel（`dx_channel`）

> Phase FS1：Channel 只读网关 + App Layout L1。  
> 设计（意图）：[data-exchange.md](data-exchange.md) · 壳设计：[flutter-shell.md](flutter-shell.md)  
> 索引：[README.md](README.md)

## 端点

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/api/dx/v1/channel/site` | 站点名片 |
| GET | `/api/dx/v1/channel/app-layout` | Flutter/小程序版式；`?since_revision=` → 304 |
| GET | `/api/dx/v1/channel/contents` | 资讯列表（`type=article\|notice`） |
| GET | `/api/dx/v1/channel/contents/{id}` | 详情（含 body） |
| GET | `/api/dx/v1/channel/products` | 产品列表 |
| PUT | `/api/dx/v1/ingest/resources/{type}/{external_id}` | 按外部 ID upsert（需 `ingest:write`） |

一律需要：`Authorization: Bearer <token>`（拍板 D10-B）。Ingest 另需 scope `ingest:write`。

## Drush

```bash
vendor/bin/drush pm:enable dx_channel -y
vendor/bin/drush dx:channel-token-create --id=ingest --scopes=channel:read,ingest:write
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

## Exchange（DE4）

| Method | Path | Scope |
|--------|------|-------|
| GET | `/api/dx/v1/exchange/changes` | `exchange:read`（或 `channel:read` / `ingest:write`） |
| POST | `/api/dx/v1/exchange/push` | `exchange:write` / `ingest:write` |
| GET/POST | `/api/dx/v1/exchange/packages` | read / write（POST 可 JSON 或 ZIP） |
| GET | `/api/dx/v1/exchange/packages/{id}` | read |
| GET | `/api/dx/v1/exchange/packages/{id}/download` | 离线 ZIP |
| POST | `/api/dx/v1/exchange/packages/{id}/apply` | write（`?dry_run=1`） |

Drush：

```bash
vendor/bin/drush dx:exchange-package-register web/modules/custom/dx_channel/data/packages/demo-package.json
vendor/bin/drush dx:exchange-package-register web/modules/custom/dx_channel/data/packages/demo-package.zip
vendor/bin/drush dx:exchange-package-export pkg_demo_zip_fixture /tmp/out.zip
vendor/bin/drush dx:exchange-package-apply pkg_demo_fixture --dry-run
vendor/bin/drush dx:exchange-package-list
```

离线 ZIP：根目录 `package.json`（与 JSON 包同 schema）。HTTP：`GET /api/dx/v1/exchange/packages/{id}/download`；`POST /api/dx/v1/exchange/packages` 可接受 `application/zip`。

冒烟：`./scripts/ci/exchange-smoke.sh`

## Webhook（DE5）

HTTP（Bearer，D10-B）：

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/api/dx/v1/webhooks` | 列表（secret 脱敏） |
| POST | `/api/dx/v1/webhooks` | 注册（secret 仅创建返回） |
| POST | `/api/dx/v1/webhooks/test` | 试发 `resource.published` |
| GET | `/api/dx/v1/webhooks/dead-letters` | 死信 |
| POST | `/api/dx/v1/webhooks/dead-letters/retry` | 重试死信 |
| DELETE | `/api/dx/v1/webhooks/{id}` | 撤销 |

Scope：`webhook:read` / `webhook:write`（`exchange:write` / `channel:read` 可读别名）。

Drush：

```bash
vendor/bin/drush dx:webhook-register https://example.com/hooks/dx
vendor/bin/drush dx:webhook-list
vendor/bin/drush dx:webhook-test
vendor/bin/drush dx:webhook-verify
vendor/bin/drush dx:webhook-dead-letters
vendor/bin/drush dx:webhook-retry
vendor/bin/drush dx:webhook-update-url wh_xxx https://example.com/hooks/dx
vendor/bin/drush dx:webhook-dead-letters-clear
```

对 `example.com` / localhost 为本地成功 sink；`fail.example.com` 为失败 sink（写入死信，便于重试冒烟）。包 apply 在 `require_review=false` 且资源 `published` 时派发 `resource.published`。

出站派发默认窗口限流：60 次 / 60 秒（`WebhookService::RATE_LIMIT`）。

冒烟：`./scripts/ci/webhook-smoke.sh`

## API 审计与限流

- 每 Token 窗口限流：120 次 / 60 秒（`ChannelAudit`）
- 审计：`drush dx:channel-audit`
- 冒烟：`./scripts/ci/channel-audit-smoke.sh`

管理页：`/admin/dx/channel/audit`

签名自检：`drush dx:webhook-verify`

统计：`drush dx:channel-audit-stats`
