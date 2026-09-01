# 夜间窗口日志（nightlog）

> 并行六线（`M3-A`，文件所有权互斥）夜间自动推进的流水账。窗口 **22:00–08:00**。
> 基点：`master` `17a97ed`（已含 2026-08 的 22 分支集成结果）。回滚点 tag：`pre-merge-20260830`。
> **追加规则**：每晚在「## 窗口条目」下**新增一节**（最新在上），沿用下方模板；不改写历史条目（历史是快照）。
> 六线 worktree：`.worktrees/lane-{delivery-ops,migrate-exchange,clients-pack,ecosystem-l2,platform-auth,docs-ci}`（分支 `lane/<名>`）。

---

## 追加模板（复制此块起新条目）

```
## YYYY-MM-DD（周X）窗口 HH:MM–HH:MM

- 值守：自动 / <人>
- 目标：<本轮要推进的线或任务>
- 进展：
  - Lx <线名>：<做了什么 / commit 短哈希 / 验证数字>
- 中断与续做：<有无 WIP 被打断、如何续、是否丢弃既有文件>
- 门禁：`bash scripts/ci/run-all.sh --no-db` → EXIT=? · `php scripts/ci/merge-integrity-check.php` → ? duplicate(s)
- 待集成方：<composer / 合并 / updatedb / 构建 等本线禁做的动作>
- 遗留：<转入下一窗口的事项>
```

---

## 窗口条目

## 2026-09-02（周二）窗口 22:00–08:00

- 值守：自动（L6 文档与 CI 线收口）
- 目标：Phase Q 收口——CI 无人值守门禁、文档索引/roadmap 对齐、集成与验收报告、phpunit runner；吸收 L1–L5 跨线请求。
- 进展：
  - L6 `lane/docs-ci`：
    - `1a516d3` Q1+Q4——`scripts/ci/run-all.sh` 重构为 gate（无 DB）+ site（连库）两段、发现式 + 能力守卫；`scripts/ci/unit-tests.sh` 优雅跳过；`phpunit.xml.dist` glob 发现 `web/*/custom/*/tests/src/{Unit,Kernel}`；`composer.json` 只加 `require-dev: drupal/core-dev ^11.4`。
    - `548cc71` Q2——README 挂「F. 并行六线」；roadmap 勾 23 条（F/G/H/I/R），Q1–Q3 勾选、**Q4 标未验收**；`DEV_MEMORY.md` 九处补录；`visibility.yml`、`migrate.md`（G1/G2）、`channel.md`（G4）。
    - （本轮末提交）Q3——`docs/integration-report-2026-09.md`（六线 commit/验证摘录/跨线请求逐条/维护窗口动作去重/风险回退）+ 本 lane 文档 + 本 nightlog。
  - 跨线请求：L4（roadmap I1–I4 + DEV_MEMORY §5.3/§5.4/§6/§7 + CI 门禁纳入）全落实；L5（phpunit harness 🟡 待集成方装、auth-smoke offline + 两 pure-assertions 纳入 gate、DEV_MEMORY §7、README 索引、`dx:ai-readiness` 命名决策）落实；L1/L2/L3 逐条见报告 §3。
- 中断与续做：本轮无中断（L4 的中断续做发生在 09-01 窗口，见下）。
- 门禁：`bash scripts/ci/run-all.sh --no-db` → **EXIT=0**（gate pass 4 / fail 0 / skip 5；skip 均因各线产物尚未合入本线基点）· `php scripts/ci/merge-integrity-check.php` → **0 duplicate(s)**（135 文件）· `bash scripts/ci/unit-tests.sh` → **EXIT=0**（未装 phpunit，SKIP 并打印安装命令）· Q2 负例：注入同 FQCN → **EXIT=1**。
- 待集成方：`composer require --dev drupal/core-dev:^11.4 --dry-run` → 实装；逐线 `--no-ff` 合入（建议 L2→L4→L1→L3→L5→L6）每合一条 `merge-integrity-check.php`；合并前打 `pre-lane-merge-20260902 17a97ed`；维护窗口 `updatedb`（含 `dx_delivery_update_11001` + `dx_ecosystem_update_9003`）+ config 写入 + 连库 site 冒烟；构建窗口 Android/Flutter/小程序。
- 遗留：报告 §3 中标 ⏳ 的跨线契约（L1↔生态路径确认、L1↔主题 CSS 对照、L2↔L3 checksums、L2↔L1 Exchange 引用、L3 skill 文档同步、L3 packer-pipeline 索引、L3↔L2 field-contract、L3↔L5 门面 ends、部署脚本 `$HOME`、L1↔L3 出包参数）转后续窗口跟进；roadmap Q4 待集成方装 phpunit 后复验改勾。

## 2026-09-01（周一）窗口 22:00–08:00

- 值守：自动（六线并行推进 + 定时任务重建）
- 目标：建 6 条 lane worktree、夜间定时任务（22:00–08:00），推进 L1–L5 实作。
- 进展：
  - L1 `lane/delivery-ops`（Phase F 交付运营化）：`f4d6801` dx_delivery 交付运营（F1 四态/F2 看板 `/deliver/todos`/F3 `dx:delivery-todo-done --batch`+SLA/F4 验收报告 v3）+ `0bff85d` CI 冒烟与 lane 记录。离线 `pure-assertions` 160 条全绿。
  - L2 `lane/migrate-exchange`（Phase G）：`5cc0d1a` G1 声明式模板库 + G2 审核批量、`3797c75` G3 SHA-256 台账 + G4 webhook endpoint、`79de13e` lane 文档。纯 PHP 422 条全绿（含默认映射回归 181）。
  - L3 `lane/clients-pack`（Phase H 多端出包 v2）：6 提交（manifest schema 门禁 / 组件目录 v2 / field-contract / Android 壳 1.3.0 / packer 门禁 / 演练写 /tmp）。四静态冒烟本机绿（isomorph 1144、flutter 114、mp 133+19+300、packer 41）。
  - L5 `lane/platform-auth`（Phase R）：`49dd84c` dx_auth 五通道 + 绑定回归、`66c71ac` `dx:ai-readiness` 命令 + 测试、`6bc5afa` auth/theme 冒烟 + Phase R 文档 + lane 记录。pure-assertions：dx_auth 450 + dx_ai_gateway 67 全绿；auth-smoke offline EXIT=0；theme-smoke OFFLINE 段 EXIT=0；**零行为变更**。
- 中断与续做：**L4 `lane/ecosystem-l2`（Phase I）曾中断续做**——接手时 worktree 有未提交的 WIP `df6a7cd`（L2 composer 仓库层 / 凭证生命周期 / 审计报表，断言 305/320）。本轮**未丢弃任何既有文件**，续做 `6ff2ae5` 修完 15 条失败断言 + 补齐 I1–I4，`6221072` 收尾至 **359/359**；`merge-integrity` 151 文件 0 重复、`l0-publish-smoke.sh offline` L0 gate OK。
- 门禁：各线本机跑各自离线 harness（pure-assertions / merge-integrity / offline 冒烟），**均未连生产 MySQL、未跑 drush、未跑 composer**；连库断言只写进 smoke 脚本待窗口。
- 待集成方：同 09-02（本轮尚无统一报告，动作分散在各 lane 文档）。
- 遗留：**定时任务因目录 / 任务丢失需重建**——夜间 22:00–08:00 的调度在窗口内检测到 worktree 目录/计划任务缺失，已按六线布局重建（`lanes12` 项：6 条 lane worktree + 夜间定时任务均已重建就绪）；L4 WIP 续做后需 L6 在 09-02 窗口把 Phase I 成果纳入 roadmap 勾选与 CI 门禁（已于 09-02 完成）。

---

## 索引

- 六线 lane 文档：`docs/lanes/L1-delivery-ops.md`、`L2-migrate-exchange.md`、`L3-clients-pack.md`、`L4-ecosystem-l2.md`、`L5-platform-auth.md`、`L6-docs-ci.md`。
- 集成与验收报告：`docs/integration-report-2026-09.md`。
- 任务卡：`docs/roadmap.md`（Phase F/G/H/I/R/Q）。索引：`docs/README.md`「F. 并行六线」。项目记忆：`DEV_MEMORY.md`。
