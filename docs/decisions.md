# DrupalX 战略与规范 · 统一拍板单

> 状态：**已确认**  
> 拍板日期：2026-08-16  
> 选择：`D2-B, D3-B, D5-B, D10-B，其余默认`  
> 完整结果：`D1-A, D2-B, D3-B, D4-A, D5-B, D6-A, D7-A, D8-A, D9-A, D10-B, D11-A, D12-A, D13-A, D14-A, D15-A, D16-A`  
> 关联：[turnkey-delivery.md](turnkey-delivery.md) · [data-exchange.md](data-exchange.md) · [strategy.md](strategy.md) · [roadmap.md](roadmap.md)

---

## 已锁定决议摘要

| 编号 | 决议 |
|------|------|
| D1-A | 主叙事：政企门户交钥匙；SME 为子集套餐 |
| D2-B | MVP **同时**做页面向导 + 对话下单 |
| D3-B | 接近原生 = **Flutter 双端 JSON 可配置壳**（已确认，见 [flutter-shell.md](flutter-shell.md) F1–F6） |
| D4-A | 旧站移植：L1/L2 自动/半自动；L3 人工/集成 |
| D5-B | 舆情监控 **第一波就要可演示** |
| D6-A | 对外名称：**交钥匙**（模块仍 `dx_delivery`） |
| D7-A | `www` 营销/下单 与 `platform` 控制台分离 |
| D8-A | DXEP + `/api/dx/v1/` |
| D9-A | 对外不暴露 Drupal JSON:API 原始形状 |
| D10-B | Channel **一律 token**（含公开内容） |
| D11-A | 交换包默认非原子，失败进报告 |
| D12-A | `notice` 与 `article` 分类型 |
| D13-A | 金额用字符串十进制 + currency |
| D14-A | `dx_xmt_bridge` 过渡双写再切 DXEP |
| D15-A | 拍板前曾冻结实现；**现已拍板，按 D16 开工** |
| D16-A | 先 DXEP Channel 只读（DE2）→ 再交钥匙交付台（含向导+对话） |

### D3-B 范围（已冻结 · 2026-08-16）

采纳 [flutter-shell.md](flutter-shell.md) 推荐默认：`F1-A, F2-A, F3-A, F4-A, F5-A, F6-A`。

| 项 | 冻结结论 |
|----|----------|
| 主路径 | Flutter 安卓+iOS 同源壳；版式 L1 + 内容 L2（DXEP） |
| 远程边界 | 白名单组件 + 预置 capability；不执行远程代码 |
| WebView | `x-pack-android` 仅存量；新客默认 Flutter |
| 小程序 | 与 App 共用 L1/L2 JSON |
| iOS 出包 | 先交付可打开工程 + 文档；签名/上架客户或托管 CI |
| 原生能力 | 由 Layout `capabilities` 开关壳内预置插件 |
| DXEP 边界 | 只消费 Channel + `app-layout`；不旁加载 |

按 Phase FS 开发（先 FS1 `app-layout`，再壳与 `x-pack-flutter` Skill）。

---

## A. 产品战略（交钥匙）

### D1 · 主叙事

- [x] **A** 主叙事定为「政企门户交钥匙一键交付」；SME 降为子集套餐  
- [ ] **B** 政企与 SME 并列主叙事  

### D2 · 交付台双入口（MVP）

- [ ] **A** MVP 只做页面选型向导；对话下单放下一阶段  
- [x] **B** MVP 同时做向导 + 对话  

### D3 · 安卓交付边界

- [ ] **A** 接受「受控壳」（WebView/PWA 打包等）；不做通用原生工程生成器  
- [x] **B** 需要更接近原生的生成能力（需另定范围）  

### D4 · 旧站移植分级

- [x] **A** L1/L2 自动或半自动；L3（深业务系统）标人工/集成，不假装一键  
- [ ] **B** 对外宣传尽量「全自动」（风险更高）  

### D5 · 舆情监控进货架时机

- [ ] **A** 第一波只占位；合规与数据源方案后再做  
- [x] **B** 第一波就要可演示的舆情能力  

### D6 · 交付台对外名称

- [x] **A** 交钥匙  
- [ ] **B** 交付台  
- [ ] **C** Turnkey  

（内部模块名：`dx_delivery`。）

### D7 · 营销站与控制台域名

- [x] **A** 分离：`www`（或营销域）下单/介绍；`platform` 控制台  
- [ ] **B** 同一域不同路径即可  

---

## B. 数据接口与交换（DXEP）

### D8 · 规范代号与路径

- [x] **A** 采用 **DXEP** + `/api/dx/v1/`  
- [ ] **B** 改名/改前缀  

### D9 · 是否对外暴露 Drupal JSON:API 原始形状

- [x] **A** 不暴露；对外只认 DXEP 资源模型  
- [ ] **B** DXEP 为主，同时保留 JSON:API 给内部/高级集成  

### D10 · Channel 公开内容读取

- [ ] **A** 允许匿名读 `visibility=public`  
- [x] **B** 一律需要 token（含公开内容）  

### D11 · 交换包单条失败策略

- [x] **A** 默认继续导入（非原子），失败进报告  
- [ ] **B** 默认整包原子回滚；可选非原子  

### D12 · 通知公告 vs 资讯类型

- [x] **A** `notice` 与 `article` 分类型（文号等挂 notice）  
- [ ] **B** v1 先统一 `article`，文号进 `extensions`  

### D13 · 金额字段表示

- [x] **A** 字符串十进制（如 `"12999.00"`）+ `currency`  
- [ ] **B** 数字类型  

### D14 · 与现有 `dx_xmt_bridge`

- [x] **A** 过渡期双写，再切 DXEP  
- [ ] **B** 一次性切到 DXEP（桥接适配 DXEP）  

---

## C. 开发冻结与开工顺序

### D15 · 确认前是否冻结开发

- [x] **A** 冻结交钥匙 + DXEP 实现（仅文档；OpenAPI 草稿可选） — *拍板前有效；现已确认*  
- [ ] **B** 允许先做无争议底座  

### D16 · 确认后的优先开工顺序

- [x] **A** 先 DXEP Channel 只读（DE2）→ 再交付台（向导+对话，DX）  
- [ ] **B** 先交付台 → 再 DXEP  
- [ ] **C** 两条并行  

---

## D. 开源生态与受众升级（已确认）

> 设计稿：[open-ecosystem.md](open-ecosystem.md)  
> 拍板：`OE 全默认` = `O1-B, O2-A, O3-A, O4-A, O5-A, O6-A, O7-A, O8-A`（2026-08-17）

### O1 · 应用源码许可结构

- [ ] **A** 纯 GPL + ToS 制裁  
- [x] **B** 双包拆分：GPL 适配层 + DX-RAL 业务库  
- [ ] **C** 平台双许可 + 第三方走 B  

### O2 · 框架公开层许可

- [x] **A** 公开框架模块 GPL-2.0+  
- [ ] **B** 非衍生脚本 Apache-2.0 + 模块 GPL  

### O3 · 禁止第四方强度

- [x] **A** 合同 + 安装确认 + 下载审计 + 违约下架  
- [ ] **B** A + 水印/泄漏追踪  
- [ ] **C** 仅 ToS 文字  

### O4 · 文档分级

- [x] **A** public / partner / internal  
- [ ] **B** 仅 public / internal  

### O5 · 认证开发者门槛

- [x] **A** 核验 + DPA + 审核  
- [ ] **B** 仅 DPA  

### O6 · 个人用户波次

- [x] **A** `tenant_kind=personal` 预留、默认关闭  
- [ ] **B** 同期开放个人注册  

### O7 · 确认后开工顺序

- [x] **A** OE1 协议 → OE2 金库 → OE3 公开面  
- [ ] **B** 先 OE3  
- [ ] **C** 先 OE4 personal  

### O8 · 对外主叙事

- [x] **A** 仍以政企交钥匙为主口号  
- [ ] **B** 立即改「全行业开源应用平台」主口号  


---

## 签字栏

| 项 | 内容 |
|----|------|
| 选择结果（交钥匙） | `D2-B, D3-B, D5-B, D10-B，其余默认` |
| 是否全盘默认 | 否（四项例外） |
| 拍板 | 用户确认（会话 2026-08-16） |
| 日期 | 2026-08-16 |
| 开源生态（D 区） | **已确认** `O1-B…O8-A`（会话 2026-08-17） |

下一步：OE2 L2 Composer/Git 凭证与 L0 文档可见性已落地。个人注册产品开关（O6-B）保持关闭。
