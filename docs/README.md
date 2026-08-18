# DrupalX 文档索引

> 整理方案 R1（2026-08-18）：分层索引 + 设计/运维对照；不改战略结论。  
> 仓库入口：[../README.md](../README.md) · 路线图：[roadmap.md](roadmap.md) · 拍板：[decisions.md](decisions.md)

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
| [decisions.md](decisions.md) | 已确认 | D / F / O 统一拍板单 |
| [roadmap.md](roadmap.md) | 活文档 | 阶段与验收进度 |

---

## B. 规范与架构

| 文档 | 说明 |
|------|------|
| [architecture.md](architecture.md) | 混合 SaaS / multisite 架构 |
| [module-curation.md](module-curation.md) | App Store 策展准入 |
| [trust.md](trust.md) | 政务信任档位（`dx_trust`） |
| [openapi/dxep-v1.yaml](openapi/dxep-v1.yaml) | DXEP OpenAPI |

---

## C. 设计文 ↔ 运维文

成对阅读：左侧定意图，右侧写入口 / Drush / 冒烟。

| 设计（意图） | 运维（落地） |
|--------------|--------------|
| [turnkey-delivery.md](turnkey-delivery.md) | [delivery.md](delivery.md)（`dx_delivery`） |
| [open-ecosystem.md](open-ecosystem.md) | [ecosystem.md](ecosystem.md)（`dx_ecosystem` / OE1） |
| [data-exchange.md](data-exchange.md) | [channel.md](channel.md)（`dx_channel`） |
| [flutter-shell.md](flutter-shell.md) | [flutter-pack.md](flutter-pack.md) · [packer-pipeline.md](packer-pipeline.md) · [certs.md](certs.md) |
| [module-curation.md](module-curation.md) | [trust.md](trust.md) · App Store 安装流（见 ecosystem） |

### 出包入口（多端）

1. 设计：[flutter-shell.md](flutter-shell.md)  
2. 单租户 Flutter 灌参：[flutter-pack.md](flutter-pack.md) / Skill [skills/x-pack-flutter.md](skills/x-pack-flutter.md)  
3. 交钥匙一键多端：[packer-pipeline.md](packer-pipeline.md)  
4. 证书路径引用：[certs.md](certs.md)

---

## D. 能力运维手册

| 文档 | 模块 / 能力 |
|------|-------------|
| [delivery.md](delivery.md) | 交钥匙交付台 |
| [ecosystem.md](ecosystem.md) | 开源生态协议与安装确认 |
| [channel.md](channel.md) | Channel / Ingest / Exchange / Webhook 入口摘要 |
| [migrate.md](migrate.md) | 旧站移植 L1/L2 |
| [theme-studio.md](theme-studio.md) | Theme Studio 门面包 |
| [health.md](health.md) | 健康检查 |
| [certs.md](certs.md) | 证书托管 |
| [opinion.md](opinion.md) | 舆情演示 |
| [flutter-pack.md](flutter-pack.md) | Flutter 打包命令 |
| [packer-pipeline.md](packer-pipeline.md) | 多端打包流水线 |
| [skills/x-pack-flutter.md](skills/x-pack-flutter.md) | Cursor Skill 入口（薄） |

---

## E. 部署与切流

| 文档 | 说明 |
|------|------|
| [DEPLOY.md](DEPLOY.md) | 生产打包与部署 |
| [domain-cutover.md](domain-cutover.md) | 生产域名切流（www / 短闻） |

---

## 文档可见性（OE / O4-A）

长期按 [open-ecosystem.md](open-ecosystem.md) 分级：`public` / `partner` / `internal`。  
当前仓库 `docs/` 默认按**运维内部 + 已确认战略**存放；公开面拆分属 Phase OE3。
