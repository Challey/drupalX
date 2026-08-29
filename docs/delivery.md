# 交钥匙交付台（`dx_delivery`）

> Phase DX MVP：向导 + 对话 → Blueprint → 一键编排。  
> 战略：[turnkey-delivery.md](turnkey-delivery.md)  
> 旧站 L1：[migrate.md](migrate.md) · 舆情演示：`dx_opinion` `/opinion`

## 入口

| 路径 | 说明 |
|------|------|
| `/deliver` · `/order` | 交付台首页 |
| `/deliver/wizard` | 页面选型下单 |
| `/deliver/chat` | 对话下单 |
| `/deliver/blueprint/{id}` | 蓝图确认 / 验收 |
| `/deliver/todos` | L3 工单看板（Phase F/F2，需 `access dx delivery todos`） |
| `/deliver/todos/{id}/{todo_id}/complete` | 单个工单签核确认页 |
| `/admin/dx/delivery` | 管理列表 |
| `/admin/dx/delivery?status=draft\|confirmed\|executed\|failed` | 管理列表四态分区（Phase F/F1） |

蓝图列表分区是纯查询参数 + 链接，不依赖 Views；`executed` 汇总机器状态 `running` + `completed`，未知值回落全部（旧书签不会报错）。默认无参数时的列与文案与历史一致。

## Drush

```bash
vendor/bin/drush pm:enable dx_delivery dx_migrate dx_opinion dx_trust dx_health -y
vendor/bin/drush dx:delivery-from-chat "做政府门户，要小程序和商城" --machine-name=govdemo
vendor/bin/drush dx:delivery-run 1 --skip-pack
vendor/bin/drush dx:delivery-list
vendor/bin/drush dx:delivery-report 1
vendor/bin/drush dx:delivery-export 1 /tmp/acceptance.json
vendor/bin/drush dx:delivery-todo-done 1 l3-integration
# 批量签核 + SLA 字段（F3）：重复执行幂等，已完成项返回 skipped
vendor/bin/drush dx:delivery-todo-done 1 --batch=l3-integration,l3-acceptance --owner=ops-lead --due=2026-09-15 --note=现场已联调
# 只改 SLA 字段，不动签核状态
vendor/bin/drush dx:delivery-todo-done 1 --batch=l3-integration --owner=liang --sla-only
vendor/bin/drush dx:migrate-l1 --dry-run
```

`dx:delivery-todo-done` 输出单行 JSON：`ok` / `mode`（`single`·`batch`·`sla`）/ `requested` / `completed` / `skipped` / `unknown` / `sla` / `todo_stats` / `todos`。单个模式保持历史语义（未知 id 直接报错且不写库）；批量模式下未知 id 会列进 `unknown` 并以非零退出，但已知项照常落库。

## 编排步骤

1. 开通租户（`TenantProvisioner`）  
2. 应用 Theme Studio pack + Channel layout profile  
3. **信任策略**（`dx_trust`：政府默认收紧 / 企业默认）  
3b. **能力启用**（`CapabilityEnabler`：commerce→`dx_payment`、opinion→`dx_opinion`、ai_chat→`dx_ai_gateway`、oss→`dx_oss`；租户侧 soft-fail）  
4. 若勾选 app/miniprogram → `pack-tenant-channels.sh`  
5. **移植**：L1/L2 调 `dx_migrate` → DXEP Ingest（L2 跟详情；无 URL 时用 fixture）；**L3 打开人工交接工单**（`handoff_todos`，不假装一键）  
6. 验收报告 JSON 存蓝图实体（含 `trust_policy` / `capabilities` / `migrate` / `handoff_todos` 步骤）  

蓝图页 `/deliver/blueprint/{id}` 以步骤清单展示验收（trust / capabilities / migrate 等）。

## L3 工单看板（F2）

`/deliver/todos` 聚合最近 30 个蓝图的 `handoff_todos`，可按状态（待办 / 已签核 / 已逾期 / 全部）与蓝图筛选。SLA 字段（`owner` / `due` / `remark` / `sla_updated_at`）直接存在 todo 数组里，蓝图表结构不变。

- 权限 `access dx delivery todos`（新权限，默认不发给匿名与 authenticated；`administer dx delivery` 同样可进）。安装与 `dx_delivery_update_11001()` 会授予 administrator。
- 蓝图未确认执行 / 没有工单 / 筛选后为空 → 渲染可读空态与下一步入口，不出现空表或 404。
- 无签核权限的用户只读：不渲染签核链接，但仍显示等价的 drush 命令。
- 页面上的签核走 `HandoffTodoService`（与 drush、`/deliver/blueprint/{id}/todos/{todo_id}/done` 同一条落库路径），公共方法签名未改。

## 验收报告 v3（F4）

`dx:delivery-export` 产物仍是单文件可解析 JSON，`spec_version` 为 `3.0`，四类交付物链接成块输出：

```json
"deliverables": {
  "spec": "DX-DELIVERABLES",
  "total": 4,
  "ok": 1,
  "missing": ["api_docs", "certs", "l3_source"],
  "items": {
    "ops_handbook": {"key": "...", "label": "运维手册", "type": "doc", "value": "docs/delivery.md", "url": "", "path": "...", "status": "ok", "note": "文件就绪"},
    "api_docs": {"type": "url", "status": "missing", "note": "站点未注册该路径的路由（对应模块未启用？）"}
  }
}
```

规则：四个键（`ops_handbook` / `api_docs` / `certs` / `l3_source`）恒定输出，每项八个列恒定输出，缺项标 `missing` 并写原因，绝不省键。`ops_handbook` 会校验仓库根下文件是否存在，`api_docs` / `certs` / `l3_source` 的站内路径会实时探测路由；外链不做探测。`dx:delivery-report` 同步输出 `spec_version` / `deliverables` / `todo_stats`。

`/deliver/blueprint/{id}/acceptance.json` 默认输出保持历史形状，需要交付物块时显式加 `?deliverables=1`。

## 冒烟

```bash
# 无需数据库、无需站点：纯函数 + JSON 结构 + 模板渲染断言
php web/modules/custom/dx_delivery/tests/pure-assertions.php

./scripts/ci/delivery-smoke.sh
./scripts/ci/migrate-smoke.sh
./scripts/ci/migrate-l2-smoke.sh
./scripts/ci/opinion-smoke.sh
./scripts/ci/desk-smoke.sh          # 含 F1 四态分区断言
./scripts/ci/delivery-todos-smoke.sh # 含 F3 批量/幂等 + F2 看板断言
./scripts/ci/delivery-ops-smoke.sh   # 含 F4 v3 结构深检
./scripts/ci/l3-handoff-smoke.sh
./scripts/ci/l3-source-smoke.sh
```

三个交付线冒烟脚本第一步都会跑 `tests/pure-assertions.php`，逻辑回归会在写库之前失败。
