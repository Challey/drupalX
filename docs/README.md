# DrupalX 文档索引

> 整理方案 R1（2026-08-18）· 阶段二复核 R2（2026-08-30 分支集成后补齐条目）。  
> 仓库入口：[../README.md](../README.md) · 路线图：[roadmap.md](roadmap.md) · 拍板：[decisions.md](decisions.md)  
> 开发上下文（新会话先读）：[../DEV_MEMORY.md](../DEV_MEMORY.md)

---

## 怎么读

| 目的 | 从这里开始 |
|------|------------|
| 产品是什么、主叙事 | [strategy.md](strategy.md) → [turnkey-delivery.md](turnkey-delivery.md) |
| 已拍板决议 | [decisions.md](decisions.md) |
| 阶段进度 | [roadmap.md](roadmap.md) |
| 开源分层 / DX-RAL | [open-ecosystem.md](open-ecosystem.md) |
| 数据接口 DXEP | [data-exchange.md](data-exchange.md) · [openapi/dxep-v1.yaml](openapi/dxep-v1.yaml) |
| 多端壳 / 出包 | [flutter-shell.md](flutter-shell.md) → [flutter-pack.md](flutter-pack.md) · [packer-pipeline.md](packer-pipeline.md) |
| 登录与身份 | [auth.md](auth.md) → [enterprise-login.md](enterprise-login.md) |
| 分支与合并历史 | [branch-integration-2026-08.md](branch-integration-2026-08.md) |
| 并行六线进度 / 集成验收 | [lanes/](lanes/) · [integration-report-2026-09.md](integration-report-2026-09.md) |
| 模块怎么用 | 下表「运维手册」 |

---

## A. 战略与拍板

| 文档 | 状态 | 说明 |
|------|------|------|
| [strategy.md](strategy.md) | 已确认 | 使命、五性、产品分层 |
| [turnkey-delivery.md](turnkey-delivery.md) | 已确认 | 交钥匙主叙事设计 |
| [open-ecosystem.md](open-ecosystem.md) | 已确认 | 开源四层 L0–L3、DX-RAL/DPA、受众波次 |
| [data-exchange.md](data-exchange.md) | 已确认 | DXEP v1 契约 |
| [flutter-shell.md](flutter-shell.md) | 已确认 | Flutter 可配置壳 + 小程序同构 |
| [decisions.md](decisions.md) | 已确认 | D / F / O / M 统一拍板单 |
| [roadmap.md](roadmap.md) | 活文档 | 阶段与验收进度（含 Phase F–Q 波次） |

---

## B. 规范与架构

| 文档 | 说明 |
|------|------|
| [architecture.md](architecture.md) | 混合 SaaS / multisite 架构 |
| [module-curation.md](module-curation.md) | App Store 策展准入 |
| [trust.md](trust.md) | 政务信任档位（`dx_trust`） |
| [auth.md](auth.md) | 统一登录行为与通道 |
| [public-framework.md](public-framework.md) | L0 公开框架与导出边界 |
| [l0-whitelist.yml](l0-whitelist.yml) | L0 公开树白名单（发布脚本读取） |
| [visibility.yml](visibility.yml) | 文档/路径可见性分级 `public` / `partner` / `internal` |
| [openapi/dxep-v1.yaml](openapi/dxep-v1.yaml) | DXEP OpenAPI |
| [api/index.html](api/index.html) | 公开 API 文档站入口 |

---

## C. 设计文 ↔ 运维文

成对阅读：左侧定意图，右侧写入口 / Drush / 冒烟。

| 设计（意图） | 运维（落地） |
|--------------|--------------|
| [turnkey-delivery.md](turnkey-delivery.md) | [delivery.md](delivery.md)（`dx_delivery`） |
| [open-ecosystem.md](open-ecosystem.md) | [ecosystem.md](ecosystem.md)（`dx_ecosystem` / OE1–OE4）· [public-framework.md](public-framework.md)（OE3） |
| [data-exchange.md](data-exchange.md) | [channel.md](channel.md)（`dx_channel`）· [migrate.md](migrate.md)（`dx_migrate`） |
| [flutter-shell.md](flutter-shell.md) | [flutter-pack.md](flutter-pack.md) · [packer-pipeline.md](packer-pipeline.md) · [certs.md](certs.md) |
| [auth.md](auth.md) | [enterprise-login.md](enterprise-login.md)（企业ID / 通道配置） |
| [module-curation.md](module-curation.md) | [trust.md](trust.md) · App Store 安装流（见 ecosystem） |
| [strategy.md](strategy.md) | [../DEV_MEMORY.md](../DEV_MEMORY.md)（现网保护项与运维速查） |

### 出包入口（多端）

1. 设计：[flutter-shell.md](flutter-shell.md)  
2. 单租户 Flutter 灌参：[flutter-pack.md](flutter-pack.md) / Skill [skills/x-pack-flutter.md](skills/x-pack-flutter.md)  
3. 交钥匙一键多端：[packer-pipeline.md](packer-pipeline.md)  
4. 证书路径引用：[certs.md](certs.md)

---

## D. 能力运维手册

| 文档 | 模块 / 能力 |
|------|-------------|
| [delivery.md](delivery.md) | 交钥匙交付台（`/deliver` · `/order` · L3 工单） |
| [ecosystem.md](ecosystem.md) | 开源生态协议、安装确认、L2 凭证、L3 源码包 |
| [auth.md](auth.md) | 统一登录与绑定页 |
| [enterprise-login.md](enterprise-login.md) | 企业 ID（统一社会信用代码）登录与租户绑定 |
| [channel.md](channel.md) | Channel / Ingest / Exchange / Webhook 入口摘要 |
| [migrate.md](migrate.md) | 旧站移植 L1/L2 + 审核队列 |
| [theme-studio.md](theme-studio.md) | Theme Studio 门面包（政企 10+ 套） |
| [health.md](health.md) | 健康检查与租户健康摘要 |
| [certs.md](certs.md) | 证书托管与就绪探测 |
| [opinion.md](opinion.md) | 舆情演示 |
| [flutter-pack.md](flutter-pack.md) | Flutter 打包命令 |
| [android-pack.md](android-pack.md) | Android WebView 壳出包 |
| [miniprogram-pack.md](miniprogram-pack.md) | 微信小程序出包 |
| [packer-pipeline.md](packer-pipeline.md) | 多端打包流水线与门禁 |
| [manifest-pack.md](manifest-pack.md) | 三端出包清单 schema（DX-PACK-MANIFEST） |
| [isomorph-pack.md](isomorph-pack.md) | 三端字段契约与同构冒烟（DX-FIELD-CONTRACT） |
| [skills/README.md](skills/README.md) | Agent Skill 总览（Flutter / Android / 小程序） |

---

## E. 部署与切流

| 文档 | 说明 |
|------|------|
| [DEPLOY.md](DEPLOY.md) | 生产打包与部署 |
| [domain-cutover.md](domain-cutover.md) | 生产域名切流（www / 短闻） |
| [automatic-load-balancing.md](automatic-load-balancing.md) | 双机 A/B 负载与故障切换 |
| [branch-integration-2026-08.md](branch-integration-2026-08.md) | 分支集成记录与合并规则 |

---

## F. 并行六线 · lane 文档与集成

> 2026-08-30 后按 `M3-A` 六线并行开发（文件所有权互斥，见 [decisions.md](decisions.md)）。
> 每条线在各自 `lane/*` 分支交付一份 lane 文档（`docs/lanes/`），记录已完成 / 验证输出 /
> 需维护窗口执行的命令 / 跨线请求处置 / 风险与回退。`docs/lanes` 可见性为 `internal`。

| 线 | 波次 | lane 文档 | 分支 |
|----|------|-----------|------|
| L1 | Phase F 交付运营化 | [lanes/L1-delivery-ops.md](lanes/L1-delivery-ops.md) | `lane/delivery-ops` |
| L2 | Phase G 迁移与交换 | [lanes/L2-migrate-exchange.md](lanes/L2-migrate-exchange.md) | `lane/migrate-exchange` |
| L3 | Phase H 多端出包 v2 | [lanes/L3-clients-pack.md](lanes/L3-clients-pack.md) | `lane/clients-pack` |
| L4 | Phase I 真实 L2 仓库 | [lanes/L4-ecosystem-l2.md](lanes/L4-ecosystem-l2.md) | `lane/ecosystem-l2` |
| L5 | Phase R 登录与门面回归 | [lanes/L5-platform-auth.md](lanes/L5-platform-auth.md) | `lane/platform-auth` |
| L6 | Phase Q 质量与 CI | [lanes/L6-docs-ci.md](lanes/L6-docs-ci.md) | `lane/docs-ci` |

| 文档 | 说明 |
|------|------|
| [lanes/nightlog.md](lanes/nightlog.md) | 夜间维护窗口进展流水（按晚追加） |
| [integration-report-2026-09.md](integration-report-2026-09.md) | 六线集成与验收报告：commit 列表、验证摘录、跨线请求处置、DB/配置动作合并清单、风险与回退 |

---

## 文档可见性（OE / O4-A · OE3 已落地）

按 [visibility.yml](visibility.yml) 分级 `public` / `partner` / `internal`，由
[l0-whitelist.yml](l0-whitelist.yml) + `scripts/lib/l0_publish.php` 决定公开树内容；
发布命令 `bash scripts/publish-l0-tree.sh`，冒烟 `./scripts/ci/l0-publish-smoke.sh`。  
新增内部文档（部署、切流、HA、分支集成、集成工具）必须同步登记 `visibility.yml`。
