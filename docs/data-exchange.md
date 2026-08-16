# DrupalX 政企标准数据接口与数据交换规范（待确认）

> 状态：**设计稿 · 确认后再开发实现**  
> 日期：2026-08-16  
> 规范代号：**DXEP**（DrupalX Exchange Protocol）  
> 版本目标：`v1.0`（确认后冻结字段；实现按次小版本演进）  
> 产品名：**DrupalX**（`dx_*` / `DX_*`）  
> 关联：[turnkey-delivery.md](turnkey-delivery.md) · [strategy.md](strategy.md) · [architecture.md](architecture.md) · [module-curation.md](module-curation.md)

---

## 1. 目的与范围

为政企门户交钥匙平台提供一套**统一、可审计、可对接**的：

1. **标准数据接口**（HTTP API）：多端（Web / 小程序 / 受控 App）、伙伴系统、交付引擎共用；  
2. **标准数据交换规范**（报文与批次包）：旧站移植、上下级单位同步、第三方业务系统对接。

**在范围内**

- 官方信息类：组织、栏目、通知公告、资讯、附件、办事入口元数据  
- 企业门户类：企业资料、产品、媒体稿  
- 交换控制：身份、权限范围、幂等、审计、错误码、版本  
- 交付相关：Blueprint 摘要只读（与交钥匙设计对齐，本规范不定义交付编排细节）

**不在本规范 v1 范围（占位）**

- 完整公文流转 / 电子印章业务语义  
- 舆情原始采集协议（仅预留 Capability 扩展位）  
- 任意第三方私有二进制协议

---

## 2. 设计原则（政企硬要求）

| 原则 | 含义 |
|------|------|
| 单一真源 | 租户站 Drupal 实体为权威；接口是投影，不是第二套库 |
| 半封闭 | 仅开放本规范定义的资源与动作；禁止「任意 SQL/任意实体 dump」式接口 |
| 可审计 | 每次写操作有 `request_id`、操作者、租户、资源、结果可追溯 |
| 可幂等 | 写接口支持 `Idempotency-Key`；交换包有 `package_id` |
| 分级可见 | 公开 / 内部 / 受限；政务默认可收紧 |
| 编码统一 | UTF-8；时间一律 UTC ISO-8601（`2026-08-16T01:00:00Z`），展示层再本地化 |
| 中英字段 | JSON 字段名 **snake_case 英文**；`title`/`summary` 等可含中文内容 |
| 稳定契约 | URL 与 schema 带版本；破坏性变更走 `/v2` |

对齐思路（**设计目标，不声称已获国标认证**）：政务信息资源目录化、交换可追踪、接口与内容分离——精神上参考常见政务信息资源交换做法；工程落点以 DXEP 本文为准。

---

## 3. 总体架构

```
┌──────────────┐  ┌──────────────┐  ┌──────────────┐
│ Web / 小程序  │  │ 旧站迁移适配  │  │ 上级/业务系统 │
│ 受控 App 壳  │  │ dx_migrate   │  │ 伙伴对接      │
└──────┬───────┘  └──────┬───────┘  └──────┬───────┘
       │ Channel 读       │ Ingest 写        │ Exchange 双向
       ▼                  ▼                  ▼
┌─────────────────────────────────────────────────────┐
│              DXEP Gateway（建议模块 dx_channel）      │
│  Auth · 限流 · 审计 · 版本 · 信封编解码 · 权限范围   │
└──────────────────────────┬──────────────────────────┘
                           │
                           ▼
              租户 Drupal 实体（权威数据）
```

三组接口共用同一资源模型与错误码，按场景分入口：

| 组 | 前缀（租户站） | 典型调用方 |
|----|----------------|------------|
| **Channel** | `/api/dx/v1/channel/*` | 小程序、PWA/安卓壳、前端 SPA |
| **Ingest** | `/api/dx/v1/ingest/*` | 迁移工具、CMS 推送、人工导入服务 |
| **Exchange** | `/api/dx/v1/exchange/*` | 批次包上下载、对端系统同步、Webhook 管理 |

平台控制面若需跨租户编排，另用 `/api/dx/v1/platform/*`（仅控制台凭证；v1 仅列资源清单，细节随交付引擎确认）。

---

## 4. 传输与协议约定

| 项 | 规范 |
|----|------|
| 协议 | HTTPS only（生产）；TLS1.2+ |
| 风格 | REST；资源名词复数；动作用 HTTP 方法 |
| 媒体类型 | 请求/响应默认 `application/json; charset=utf-8` |
| 批次包 | `application/vnd.dx.exchange+zip`（见 §10） |
| 分页 | `page`（从 1）+ `page_size`（默认 20，最大 100） |
| 过滤 | 查询参数：`updated_since`、`status`、`channel`、`q` 等 |
| 排序 | `sort=updated_at` / `-updated_at`（`-` 为降序） |
| 语言 | 响应可含 `Content-Language`；正文语言见资源 `locale` |

---

## 5. 统一响应信封

所有 JSON API（成功与业务失败）使用同一信封，便于政企网关与审计探针解析。

### 5.1 成功

```json
{
  "ok": true,
  "api_version": "1.0",
  "request_id": "req_01JABCDEFEXAMPLE",
  "tenant_id": "acme",
  "data": {},
  "meta": {
    "page": 1,
    "page_size": 20,
    "total": 128
  }
}
```

- 单对象：`data` 为对象。  
- 列表：`data` 为数组，`meta` 必含分页。  
- 无 body 的成功（如 204 语义）：仍返回 200 + `data: null`（便于统一客户端）；删除可用 `data: { "id": "...", "deleted": true }`。

### 5.2 失败

```json
{
  "ok": false,
  "api_version": "1.0",
  "request_id": "req_01JABCDEFEXAMPLE",
  "tenant_id": "acme",
  "error": {
    "code": "DX.AUTH.FORBIDDEN",
    "message": "Token scope does not allow ingest:write",
    "details": [
      { "field": "scope", "issue": "missing ingest:write" }
    ]
  }
}
```

HTTP 状态与 `error.code` 对照见 §12。`message` 可对终端用户本地化；`code` 稳定、机器可读。

---

## 6. 认证与授权

### 6.1 机制（v1）

| 方式 | 用途 |
|------|------|
| `Authorization: Bearer <access_token>` | Channel / Ingest / Exchange 主方式 |
| 站点公钥 + 请求签名（可选增强） | 服务间：`X-DX-Key-Id` + `X-DX-Signature` + `X-DX-Timestamp` |
| Session Cookie | **不**作为开放 API 主认证（仅同源管理 UI） |

Token 由租户或平台签发；载荷声明：

```json
{
  "sub": "client_mp_wechat",
  "tenant_id": "acme",
  "scopes": ["channel:read", "ingest:write"],
  "exp": 1780000000
}
```

### 6.2 权限范围（scopes）

| scope | 能力 |
|-------|------|
| `channel:read` | 读公开/已授权 Channel 资源 |
| `channel:read:internal` | 读内部可见内容 |
| `ingest:write` | 创建/更新/下线资源 |
| `exchange:package` | 上下载交换包 |
| `exchange:admin` | Webhook、密钥轮换 |
| `platform:tenant` | 仅控制面 |

政务租户默认：**Channel 公开读可用匿名或窄 scope；写与交换必须短时凭证 + IP 允许列表（可配置）。**

### 6.3 幂等与重放

- 写请求头：`Idempotency-Key: <uuid>`（24h 内相同 Key+路径+租户 → 返回首次结果）。  
- 签名模式：拒绝 `|now - X-DX-Timestamp| > 300s` 的请求；nonce 防重放（服务端短缓存）。

---

## 7. 资源模型（标准交换对象）

每个资源具备公共头字段；业务字段见分节。

### 7.1 公共头（所有资源）

| 字段 | 类型 | 说明 |
|------|------|------|
| `id` | string | DX 稳定 ID（建议 ULID/UUID）；对外主键 |
| `external_id` | string\|null | 对端/旧站原 ID，移植与对账用 |
| `type` | string | 资源类型枚举，见下表 |
| `status` | string | `draft` \| `published` \| `archived` |
| `visibility` | string | `public` \| `internal` \| `restricted` |
| `locale` | string | 默认 `zh-CN` |
| `title` | string | 标题 |
| `summary` | string\|null | 摘要 |
| `slug` | string\|null | URL 友好名（可选） |
| `channel` | string[] | 发布频道：`web` / `miniprogram` / `app` / `pwa` |
| `published_at` | string\|null | ISO-8601 |
| `updated_at` | string | ISO-8601 |
| `created_at` | string | ISO-8601 |
| `checksum` | string\|null | 正文+关键规范序列化后的 sha256（交换对账） |
| `extensions` | object | 经登记的扩展字段命名空间（见 §7.9） |

### 7.2 类型枚举 `type`

| type | 中文 | 现有/规划 Drupal 锚点 |
|------|------|------------------------|
| `org_profile` | 组织/单位资料 | `dx_company` + `dx_tenant.settings` |
| `category` | 栏目/分类 | taxonomy（规划） |
| `notice` | 通知公告 | 规划 bundle 或 `dx_media` 子类 |
| `article` | 资讯/新闻 | `dx_media` |
| `product` | 产品/服务项 | `dx_product` |
| `service_entry` | 办事/服务入口 | 规划 |
| `attachment` | 附件元数据 | file/media |
| `capability_ref` | 已启用能力引用 | App Store 许可投影 |
| `delivery_blueprint` | 交付蓝图摘要 | `dx_delivery`（只读投影） |

### 7.3 `org_profile`

```json
{
  "id": "org_01J...",
  "type": "org_profile",
  "title": "示例市人民政府办公室",
  "org_code": "11320000000000000X",
  "org_type": "government",
  "region_code": "320100",
  "contact": {
    "address": "……",
    "phone": "025-********",
    "email": "office@example.gov.cn",
    "website": "https://www.example.gov.cn"
  },
  "brand": {
    "display_name": "示例市政府",
    "logo_url": "https://…/logo.png",
    "theme_pack": "gov_steady"
  }
}
```

- `org_type`：`government` \| `institution` \| `enterprise` \| `other`  
- `org_code`：统一社会信用代码或单位自定义编码（可空，但企业场景强烈建议）

### 7.4 `category`

```json
{
  "id": "cat_01J...",
  "type": "category",
  "title": "通知公告",
  "parent_id": null,
  "path": "/notices",
  "sort": 10,
  "content_types": ["notice", "article"]
}
```

### 7.5 `notice` / `article`

共用正文结构；`notice` 增加文号与效力字段。

```json
{
  "id": "art_01J...",
  "type": "article",
  "status": "published",
  "visibility": "public",
  "title": "关于进一步优化政务服务的通知",
  "summary": "……",
  "body": {
    "format": "html",
    "html": "<p>……</p>",
    "text": "纯文本回退……"
  },
  "category_ids": ["cat_01J..."],
  "cover_url": null,
  "attachment_ids": ["att_01J..."],
  "author_name": "办公室",
  "source": "本站",
  "doc_number": null,
  "effective_from": null,
  "effective_to": null,
  "channel": ["web", "miniprogram"],
  "external_id": "old-cms-12345"
}
```

- `body.format`：`html` \| `markdown` \| `plain`（v1 入库以 html 为主，markdown 可转换）  
- 导入 HTML **必须**经消毒流水线（交付战略中的内容安全）

### 7.6 `product`

```json
{
  "id": "prd_01J...",
  "type": "product",
  "title": "智能巡检终端",
  "sku": "SKU-001",
  "price": {
    "amount": "12999.00",
    "currency": "CNY"
  },
  "body": { "format": "html", "html": "……", "text": "……" },
  "channel": ["web", "miniprogram", "app"]
}
```

金额用**字符串十进制**，避免浮点误差。

### 7.7 `service_entry`

```json
{
  "id": "svc_01J...",
  "type": "service_entry",
  "title": "不动产登记预约",
  "entry_kind": "link",
  "url": "https://…",
  "open_mode": "new_window",
  "department": "自然资源局",
  "online": true,
  "sort": 1
}
```

`entry_kind`：`link` \| `form` \| `miniprogram_path` \| `api`（后两者 v1 可只存元数据）。

### 7.8 `attachment`

```json
{
  "id": "att_01J...",
  "type": "attachment",
  "title": "附件1.pdf",
  "mime": "application/pdf",
  "size": 204800,
  "sha256": "…",
  "url": "https://…/files/…",
  "access": "public"
}
```

大文件可走 OSS；交换包内用相对路径 + 清单（§10）。

### 7.9 `extensions`

禁止随意塞业务字段进根级。扩展必须带命名空间：

```json
"extensions": {
  "dx.commerce": { "stock": 12 },
  "dx.opinion": { "monitor_keywords": ["营商环境"] }
}
```

未登记命名空间的写入 → `DX.SCHEMA.EXTENSION_UNKNOWN`。

---

## 8. Channel API（标准读接口）

面向多端只读，高缓存友好。

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/api/dx/v1/channel/site` | 站点名片：`org_profile` 摘要 + theme + 能力列表 |
| GET | `/api/dx/v1/channel/categories` | 栏目树 |
| GET | `/api/dx/v1/channel/contents` | 内容列表；`type`、`category_id`、`channel` 过滤 |
| GET | `/api/dx/v1/channel/contents/{id}` | 单篇详情（含 body） |
| GET | `/api/dx/v1/channel/products` | 产品列表 |
| GET | `/api/dx/v1/channel/products/{id}` | 产品详情 |
| GET | `/api/dx/v1/channel/services` | 服务入口列表 |
| GET | `/api/dx/v1/channel/attachments/{id}` | 附件元数据（下载走 `url`） |

**列表默认不返回 `body.html`**，仅 `summary`；详情才返回全文，降低小程序流量。

缓存建议：公开资源 `Cache-Control: public, max-age=60` + `ETag`；内部资源禁止共享缓存。

---

## 9. Ingest API（标准写接口）

面向移植与系统推送；需 `ingest:write`。

| 方法 | 路径 | 说明 |
|------|------|------|
| PUT | `/api/dx/v1/ingest/resources/{type}/{external_id}` | **按外部 ID upsert**（移植主路径） |
| POST | `/api/dx/v1/ingest/resources/{type}` | 创建（平台分配 `id`） |
| PATCH | `/api/dx/v1/ingest/resources/by-id/{id}` | 按 DX `id` 补丁更新 |
| POST | `/api/dx/v1/ingest/resources/by-id/{id}/publish` | 发布 |
| POST | `/api/dx/v1/ingest/resources/by-id/{id}/archive` | 下线/归档 |
| POST | `/api/dx/v1/ingest/attachments` | multipart 或先拿上传凭证再登记 |

Upsert 语义：同一 `tenant + type + external_id` 唯一；重复 PUT 覆盖并刷新 `checksum` / `updated_at`。

写入口必须支持：

- 可选 `dry_run=true`：只返回校验结果不落库  
- 可选 `review=true`：写入审核队列（`status=draft`），不直接公开  

---

## 10. Exchange 规范（标准数据交换）

### 10.1 在线同步（增量）

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/api/dx/v1/exchange/changes` | `updated_since` 增量变更流 |
| POST | `/api/dx/v1/exchange/push` | 对端推送一批资源（数组，≤100） |
| GET | `/api/dx/v1/exchange/packages` | 批次包列表 |
| POST | `/api/dx/v1/exchange/packages` | 登记并上传批次包 |
| GET | `/api/dx/v1/exchange/packages/{package_id}` | 包状态与校验报告 |
| POST | `/api/dx/v1/exchange/packages/{package_id}/apply` | 应用包（可 `dry_run`） |

`changes` 项示例：

```json
{
  "change_id": "chg_01J...",
  "op": "upsert",
  "resource": { "id": "art_…", "type": "article", "…": "…" }
}
```

`op`：`upsert` \| `archive` \| `delete`（物理删默认禁用，仅 `archive`）。

### 10.2 批次交换包（离线/大迁移）

**文件名**：`dxex-{tenant}-{package_id}-v1.zip`

**包内结构**：

```
manifest.json
resources/*.json          # 每个资源一个文件，或 ndjson 分卷
files/**                  # 附件二进制（可选）
checksums.sha256
```

**`manifest.json`**：

```json
{
  "spec": "DXEP",
  "spec_version": "1.0",
  "package_id": "pkg_01J...",
  "tenant_id": "acme",
  "created_at": "2026-08-16T01:00:00Z",
  "source": {
    "system": "legacy-cms",
    "base_url": "https://old.example.gov.cn"
  },
  "counts": { "article": 1200, "attachment": 340 },
  "mode": "full_snapshot",
  "default_visibility": "public",
  "require_review": true
}
```

`mode`：`full_snapshot` \| `incremental`。

应用规则：

1. 校验 zip 与 `checksums.sha256`  
2. 校验 manifest `spec_version` 兼容  
3. 按类型依赖序导入：`org_profile` → `category` → `attachment` → 内容类 → `service_entry`  
4. 失败条目写入报告，**默认不因单条失败整包回滚**（可配置 `atomic=true`）  
5. 全程审计：`package_id` + 每条 `external_id` 结果  

### 10.3 Webhook（出站）

租户可登记对端 URL；资源发布/归档后推送：

```json
{
  "event": "resource.published",
  "occurred_at": "2026-08-16T01:05:00Z",
  "tenant_id": "acme",
  "resource": { "id": "art_…", "type": "article", "checksum": "…" }
}
```

签名头同 §6；失败重试：指数退避，最多 8 次，之后进死信队列可人工重放。

---

## 11. 与交钥匙 / 多端的关系

| 场景 | 使用本组接口 |
|------|----------------|
| 小程序/安卓壳拉首页与列表 | Channel |
| 旧站一键移植 L1/L2 | Ingest upsert + Exchange package |
| 交付验收「内容已迁入」 | Exchange apply 报告写入 Acceptance Report |
| App Store 能力启用后的业务数据 | 仍经 DXEP；扩展进 `extensions` 或后续子规范 |
| XMT 等现有桥接 | 逐步适配为 DXEP 资源投影（过渡期可双写） |

---

## 12. 错误码（稳定字典）

| code | HTTP | 含义 |
|------|------|------|
| `DX.OK` | 200 | 成功（通常不出现在 error 块） |
| `DX.AUTH.UNAUTHORIZED` | 401 | 未认证 |
| `DX.AUTH.FORBIDDEN` | 403 | 无 scope / 可见性不足 |
| `DX.AUTH.REPLAY` | 401 | 签名重放或时钟偏移 |
| `DX.REQ.VALIDATION` | 400 | 字段校验失败 |
| `DX.REQ.IDEMPOTENCY_CONFLICT` | 409 | 同 Key 不同正文 |
| `DX.RES.NOT_FOUND` | 404 | 资源不存在 |
| `DX.RES.CONFLICT` | 409 | 版本/唯一键冲突 |
| `DX.SCHEMA.EXTENSION_UNKNOWN` | 400 | 未登记扩展 |
| `DX.EXCHANGE.PACKAGE_INVALID` | 400 | 包损坏或清单非法 |
| `DX.EXCHANGE.APPLY_PARTIAL` | 200 | 包部分成功（`ok:true` 且 meta 含失败列表）或 207 风格：v1 用 200 + `meta.failed` |
| `DX.RATE.LIMITED` | 429 | 限流 |
| `DX.SYS.INTERNAL` | 500 | 未归类内部错误 |

---

## 13. 安全、合规与运维基线

1. 全量写操作与包应用写入审计表（谁、何时、何租户、何资源、何结果）。  
2. 附件 MIME 白名单；可执行类型默认拒绝。  
3. HTML 消毒；外链附件扫描策略可配置。  
4. 生产强制 HTTPS；CORS 仅登记前缀。  
5. 限流：按 token + IP；匿名 Channel 更严。  
6. 密钥轮换：`exchange:admin`；旧 Key 有重叠窗口。  
7. 日志默认脱敏：token、手机号、身份证等。

---

## 14. 实现映射（确认后开发）

| 组件 | 建议 |
|------|------|
| 模块 | `dx_channel`（Gateway + Channel 读） |
| 子模块/服务 | Ingest / Exchange 可同模块命名空间 `Drupal\dx_channel\…` |
| OpenAPI | `docs/openapi/dxep-v1.yaml`（确认后生成，实现以 yaml 为契约测试源） |
| 契约测试 | CI 对示例请求/响做 schema 校验 |
| 迁移 | `dx_migrate` 只生产 DXEP 包或调 Ingest，不直写 SQL |

不新建 `dcn_*`；不把 Drupal JSON:API 原始实体形状直接暴露给政企客户（避免耦合内部字段名）。

---

## 15. 分阶段落地（无日历工期）

> 确认本规范后再编码。可与交钥匙 Phase 并行，但 **EA（多端）必须以 Channel 为准**。

| 阶段 | 内容 |
|------|------|
| **DE1** | 冻结 v1 字段与错误码；产出 OpenAPI 草稿 |
| **DE2** | Channel 只读 MVP（site / contents / products） |
| **DE3** | Ingest upsert + 审核队列 |
| **DE4** | Exchange 批次包 apply + 报告 |
| **DE5** | Webhook + 签名增强 + 限流审计仪表 |

---

## 16. 待确认清单（请拍板）

**已合并到统一拍板单** → [decisions.md](decisions.md)（B 区 D8–D14 + C 区 D15–D16）。

请在该页勾选后回复；本节不再单独维护选项。

---

## 17. 确认后文档动作

1. 本文状态改为 **已确认 · v1.0 冻结**；  
2. 增补 `docs/openapi/dxep-v1.yaml`；  
3. 回写 [roadmap.md](roadmap.md) DE1–DE5 与 Phase EA「Channel API = DXEP Channel」；  
4. 开开发分支实现 `dx_channel`。

---

*本文只定接口与交换契约，不包含实现代码。*
