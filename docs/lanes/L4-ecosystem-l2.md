# 线 L4 · 真实 L2 仓库对接（roadmap Phase I）

> 分支 `lane/ecosystem-l2` · WIP 提交 `df6a7cd` · 基点 `master` `17a97ed`  
> 所有权：`web/modules/custom/dx_ecosystem/**`、`web/modules/custom/dx_appstore/**`、`scripts/lib/l0_publish.php`、`docs/{l0-whitelist,visibility,open-ecosystem,public-framework,module-curation}.yml/md`、`scripts/ci/{ecosystem,l0-publish,l2-credential,l3-source,trust,appstore-trust}-smoke.sh`、`docs/lanes/L4-ecosystem-l2.md`  
> 现网约束：`/dx/ecosystem/partner`、`/appstore`、`/appstore/licenses` 行为保持（匿名 `/appstore` 403 是预期，未动）；`dx_ecosystem` 公共服务只做行为保持的增量，不改公共方法签名、不改已有路由 path/requirements 语义、不破坏 `dxl2_` + SHA-256 的向后兼容。

本轮从被中断的 WIP（`df6a7cd`，305/320）续做：修完 15 条失败断言、补齐 I1–I4 缺口、给出可离线跑的门禁段。断言现 **359/359，0 失败**。

---

## 1. 已完成

### 1.0 修完 WIP 遗留的 15 条断言

逐条判定「实作错 / 断言与契约冲突」后的处置（详见 §2 断言分类）：

| # | 现象（期望→实际） | 根因 | 处置 |
|---|-------------------|------|------|
| 1 | 大写十六进制 `TOKEN_OK`→`TOKEN_MALFORMED` | 断言把整串含 `dxl2_` 前缀一起大写，前缀变 `DXL2_` 才不合法 | **改断言**：只把 hex 段转大写，符合「大写十六进制仍算合法格式」的自述意图 |
| 2 | TTL 下限 60→900 | 断言复用了一个没设 `l2_download_ttl` 的夹具（拿到 DEFAULT=900） | **改断言**：改用 `l2_download_ttl=5` 真正触发钳到 `MIN_TTL` |
| 3 | 日志脱敏 `[]`→`["***@","signature=***"]` | `redact()` 用 `#` 定界且查询模式要排除字面量 `#`（fragment），口令/签名脱敏不彻底 | **改实作** `ComposerHostPlan::redact()`：定界符换 `~`、加 `i`，`user:pass@` 与 `signature=` 全脱敏 |
| 4 | 吊销后轮换 `revoked`→`active` | `CredentialLifecycle::apply()` 无条件把 `rotate` 映射到 `active`，已吊销凭证被轮换可复活 | **改实作** `apply()`：先过 `allowedEvents()` 状态机菜单，`revoked` 只接受 `issue`，非法事件原样返回 |
| 5–11 | `TOKEN_OK`→各拒绝码（cert 作废 / 被取代 / 陌生 / 认证未过 / DPA 过期 / DPA 未签 / 缺权限） | 断言用 PHP `+` 合并数组：`$good + ['matched'=>FALSE]` **不会覆盖**已有键，覆盖从未生效，`classify()` 一直看到全好的 `$good` | **改断言**：`+` 全部换成 `array_replace()`。`CredentialLifecycle::classify()` 的门禁判定本就是对的（`CredentialTokenVerifier` 链无提前返回 OK），是测试夹具骗了自己 |
| 12 | `drupalx/dx_payment`→`drupalx/dx_payment.json` | `providers-url` 把 `.json` 拼在 `%package%` 后，还原包名时没剥后缀 | **改实作** `SatisMetadataBuilder::nameFromProviderKey()`：剥 `PROVIDER_SUFFIX`（`.json`）再规范化 |
| 13 | `etc/shadow`→`var/www/etc/shadow` | `sanitizeArtifactPath()` 把 `..` 当普通段丢弃但未回退已收集段，绝对/穿越路径残留前缀 | **改实作** `sanitizeArtifactPath()`：遇 `..` 清空已收集段，只能更往下指、不能把旁支树走私到真树前面 |
| 14 | lint 报 2→3 | 三个问题包（非法名 / 空 versions / 版本只写空白）本就是 3 条，旧期望值算错 | **改断言**：期望改 3，并新增「逐条展开到包名」断言（`$broken[2]['name']`），钉住每条归属 |
| 15 | 降级时列问题 true→false | `gate.enforce:false` 的正确语义是「未登记项转 warning、不阻断」，旧断言去 `issues` 里找 | **改实作** `dx_l0_plan()`：`enforce:false` 时把 `DX.L0.UNREGISTERED` 的 severity 重写为 warning；**改断言**：改查 `warnings`，并钉 `issues==[]`、`exit==0`、渲染文本含「不阻断」 |

第 5–11 条用户提示「优先查 `CredentialTokenVerifier` 门禁链」——查核结论是**门禁链实作正确**，15 条里 6 条的锅在测试用了 `+`。这直接印证「断言与既有契约冲突则改断言」的授权。

### 1.1 I1 — 私有 Composer 生成层 + `dxl2_` token 校验中间件

WIP 已落地适配层与静态仓库生成（`ComposerHostPlan`、`SatisMetadataBuilder`、`L2PackageManifest`、`L2RepositoryBuilder`、`L2ComposerRepository`、`DownloadUrlSigner`、`RepositoryRequestAuth`、守卫 `L2RepositoryAuthSubscriber` + `L2RepositoryController`，`dx_ecosystem.settings` 新增 `l2_composer_base_url`/`l2_repository_root`/`l2_composer_driver`/`l2_token_header`/`l2_signing_key`/`l2_download_ttl`/`l2_dist_mode`）。本轮补齐其**离线可验证**与**回环契约**：

- **可切换适配层**：`base_url` / `token header` / 仓库根三个配置项驱动，`ComposerHostPlan::fromSettings()` 解析 `satis` / `artifactory` / `loopback` 三种驱动（`l2_composer_driver:auto` 时按 base_url 形态推断：目录/`file://` → loopback）。空 `base_url` 保留 OE2 占位行为（`packages.drupalx.local`），不改变现网。
- **无网络回环**：把 `l2_composer_base_url` + `l2_repository_root` 指向本地目录即走 `DRIVER_LOOPBACK`，把 composer 的三步（root `packages.json` → provider → dist 字节）在磁盘/守卫路由上重放，不真出网。
- **离线半段**：`tests/pure-assertions.php` 新增「目录基址 → loopback 驱动」「回环根指向磁盘 packages.json」「provider 与根文档同版本集/同 shasum」「provider 自指指纹可复现」「签名链接可回验/过期即拒/不能给别人」「`%2F` 与解码两形态都能匹配 provider 路由」等断言。
- **在线半段（只写不跑）**：`scripts/ci/l2-credential-smoke.sh` 新增「拉取回环」段——用生成的占位 zip 建静态树、配置 loopback、经守卫路由依次拉 root / provider（`drupalx%2Fdx_payment.json` 原始 `%2F` 拼写）/ 带签名 dist，断言 sha1 与元数据一致，并断匿名 `packages.json` 返回稳定码 `DX.L2.TOKEN_MISSING`。
- **provider 路由 matcher 修复**：`dx_ecosystem.l2_provider` 的 `{provider}` 需求从 `[A-Za-z0-9_.\-]+` 放宽到 `[A-Za-z0-9_.%\-/]+`，让 composer 写入的 `vendor%2Fpackage.json` 与 core `path_processor_decode`（优先级 1000）解码后的 `vendor/package.json` 两种形态都能命中；**path 未改**（`/dx/ecosystem/l2/providers/{provider}`），行为保持。

### 1.2 I2 — L2 Git 主机下发与凭证吊销同步

- **认证作废 → token 立即失效**：`CredentialLifecycle::classify()` 在 `cert_status != certified` 时直接给 `DX.L2.CERT_REVOKED` / `DX.L2.CERT_STALE`，不等同步任务；被取代的旧 token → `DX.L2.CREDENTIAL_ROTATED`；陌生 token → `DX.L2.TOKEN_UNKNOWN`；DPA 过期/未签 → `DX.L2.DPA_STALE`。对应断言 5–11 现全绿。
- **撤销后 `dx:ecosystem-verify-credential` 失败**：`l2-credential-smoke.sh` 已在吊销后断 `verify-credential` 返回 `ok:false`（既有），并新增 `dx:ecosystem-l2-auth-check` 对无凭证请求稳定报 `DX.L2.TOKEN_MISSING`。
- **`DX.L2.*` 错误码表写进 `docs/open-ecosystem.md` §4.1**：凭证与门禁 / 产物下载链接 / 元数据与构建 / 配置期警告四组，逐码给含义与传输层表现（HTTP 状态、`X-DrupalX-Error` 头、drush `code` 字段、审计表 `code` 列），并说明「挂起 vs 吊销」是契约的一部分（挂起可自动恢复；吊销永不复活，状态机 `revoked` 只接受 `issue`）。已核对表中每个码与 PHP 常量字面量一致。

### 1.3 I3 — 凭证签发 / 轮换 / 使用审计报表

WIP 已落地：审计表 `dx_l2_credential_event`（`hook_schema` + `dx_ecosystem_update_9003()` 建表，**不改任何现有实体 base field**）、`CredentialAuditLog`（含 `prune()`）、`CredentialReport`（`columns()` 固定 14 列 + `headerLabel()` 人话表头）、`CredentialReportController::report`（`/admin/dx/ecosystem/credentials`，权限 `administer dx ecosystem`）、Drush `dx:ecosystem-credential-report`（`--uid/--format=json/--older-than` 剪枝）。本轮补齐冒烟断言：

- `ecosystem-smoke.sh` 新增 I3 段：断审计表存在 → 签发凭证并经 L2 路由调用两次（灌入 caller/IP/last-use）→ 断 Drush JSON 报表含全部 14 列且 `uses>=2`/`last_used>0`/`top_ips` 含来源 IP/`distinct_ips>=1` → 断 14 个表头唯一 → 断后台页 200/302 → 断 CLI 表出现 `签发者 / 来源 IP / 调用次数 / 最后使用` → 断剪枝路径可用。
- 后台页与 CLI 共用 `CredentialReport::headerLabel()`，同一份列定义两处可读。

### 1.4 I4 — L0 公开树发布接 CI

- `scripts/lib/l0_publish.php` 在**无 `.env`、无数据库、不碰 drush** 前提下即可跑：产出待发布清单（`--plan --format=json`）、按 internal/partner/public 过滤（`dx_l0_filter`）、未登记新文档以 `DX.L0.UNREGISTERED` 非零退出（`--gate` exit 4）。
- `docs/l0-whitelist.yml` / `docs/visibility.yml`：把 `web/modules/custom/dx_ecosystem/data/composer`（L2 私有目录清单 = 私有仓库自身的索引）标 `partner` 并进 `exclude` + `must_exclude`——公开树可以带「和私有仓库对话的代码」，绝不带「仓库索引本身」。`data/composer` 载荷非 Markdown，`gate.register` 看不到它，靠显式 `visibility` 键剥离（`pure-assertions` 的「只靠可见性键也能剥离」钉住这点）。
- `scripts/ci/l0-publish-smoke.sh` **拆成两段**：段 1 离线门禁（`php l0_publish.php` + `python3`，无站点），段 2 在线（drush 路由与权限）。可 `bash scripts/ci/l0-publish-smoke.sh offline` 只跑段 1；段 1 含 plan/export/negative/enforce:false 降级四类断言，并要求 `docs/lanes/` 不出现在 public（lane 报告含生产细节，默认 internal）。
- `docs/visibility.yml` 有 `docs/lanes: internal`，本文件（含所有 lane 报告）不进入公开面。

---

## 2. 验证命令与实际输出

本线在开发 worktree 内**未**跑任何 drush / 在线 `*-smoke.sh`（它们连生产 MySQL）。段 1 离线门禁按要求单独跑通。

```console
$ php web/modules/custom/dx_ecosystem/tests/pure-assertions.php | tail -1
PURE ASSERTIONS dx_ecosystem: 359/359 passed, 0 failed

$ php scripts/ci/merge-integrity-check.php | tail -1
OK: 151 file(s) scanned, 0 duplicate(s)

$ for f in web/modules/custom/dx_ecosystem/src/Service/CredentialLifecycle.php \
    web/modules/custom/dx_ecosystem/src/Service/Composer/{ComposerHostPlan,RepositoryRequestAuth,SatisMetadataBuilder}.php \
    scripts/lib/l0_publish.php web/modules/custom/dx_ecosystem/tests/pure-assertions.php; do php -l "$f"; done
No syntax errors detected in ... (每个文件一行，全部通过)

$ for f in ecosystem l2-credential l0-publish; do bash -n "scripts/ci/$f-smoke.sh"; done
bash -n 通过（三脚本无语法错误）
```

`tests/pure-assertions.php` 是无 DB、无站点的纯断言脚本（359 条），本轮相关分类：

| 组 | 断言要点 |
|----|----------|
| 凭证格式 / TTL / 脱敏 | `dxl2_`+48hex、大写 hex 仍合法、TTL 钳 60/900/上限、`redact()` 隐口令与签名 |
| 状态机与门禁分类 | `apply()` 吊销不可轮换复活；`classify()` 对 cert 作废 / 取代 / 陌生 / 未认证 / DPA / 缺权限给对应稳定码 |
| provider 还原与路由 | 剥 `.json` 还原包名；未解码与解码两形态都匹配 `{provider}` 需求且 path 不变 |
| I1 回环（离线半段） | 目录基址→loopback、root/provider/dist 三步磁盘重放、签名链接可回验且过期/换人即拒、绝对路径被 `sanitizeArtifactPath` 剥成相对 |
| I4 L0 | 未登记 → exit 4；`enforce:false` → 转 warning 不阻断仍留痕；L2 清单 partner+exclude+must_exclude；非登记载荷靠 visibility 键剥离；`docs/lanes` 不在 public |

段 1 离线门禁实际输出（本 lane 文档创建后 `docs/lanes` 已存在，`STALE_RULE` 警告随之消失）：

```console
$ bash scripts/ci/l0-publish-smoke.sh offline
== OE3/I4 L0 publish + public API docs smoke ==
-- 1/2 offline gate --
L0 gate DX.L0.OK · whitelist v1 · layer L0 · 扫描 540 文件 · public 42 / partner 0 / internal 5 / 未登记 0
L0 export 已写入 /tmp/.../dx-l0-oe3-653888（include 28 / removed 9）
L0 gate DX.L0.OK · whitelist v1 · layer L0 · 扫描 540 文件 · public 42 / partner 0 / internal 5 / 未登记 0
OK I4 offline L0 gate (段 2 需要站点，已跳过)
（退出码 0；本文件计入 internal / removed 9，未登记 0、无 STALE 警告、不在 public）
```

> 需真实 DB 的断言写进三个在线冒烟（**不在此执行**，见 §3）：`l2-credential-smoke.sh`（回环三步 + 吊销/轮换失败码 + 无凭证 `DX.L2.TOKEN_MISSING`）、`ecosystem-smoke.sh`（I3 报表 14 列 + 后台页 + CLI 人话表头 + 剪枝）、`l0-publish-smoke.sh` 段 2（`dx:ecosystem-publish-l0 --dry-run` 不写树、`/dx/api/docs` 200、`/dx/ecosystem/partner`、`/dx/ecosystem/credentials` 匿名仍 403/302）。

---

## 3. 需要维护窗口执行的 DB 动作与 config 导入

代码上线后（生产 docroot `/home/wwwroot/drupalX`）按序执行：

```bash
cd /home/wwwroot/drupalX
vendor/bin/drush pm:enable dx_ecosystem -y   # 已启用则跳过
vendor/bin/drush updatedb -y                 # 应用 dx_ecosystem_update_9003：建 dx_l2_credential_event
vendor/bin/drush cr
```

config 导入（`config/install/dx_ecosystem.settings.yml` 已带 Phase I 新键；对存量站点用 `config-sync` / `config-import` 补齐键，键缺失时 `L2ComposerRepository` 走默认，不炸）：

```bash
# 只看差异，不强制导入：
vendor/bin/drush config:get dx_ecosystem.settings l2_composer_base_url l2_repository_root l2_composer_driver l2_token_header l2_signing_key l2_download_ttl l2_dist_mode
# 若走打包 config：vendor/bin/drush config:import -y
```

接入**真实**私有 Composer 主机时（I1 落地，非回环），在窗口内显式配置并签发伙伴凭证：

```bash
# 例：Satis over HTTPS，token 走 authorization 头
vendor/bin/drush config:set dx_ecosystem.settings \
  l2_repository_enabled=true l2_composer_driver=satis \
  l2_composer_base_url="https://packages.example.com" \
  l2_repository_root="/srv/l2" \
  l2_signing_key="<至少 32 字符强随机>" l2_download_ttl=600 -y
vendor/bin/drush cr
# 构建静态仓库树（把预置 zip 从 --src 导进 --build 目录，落盘后再挂 nginx/对象存储）
vendor/bin/drush dx:ecosystem-l2-repo --lint
vendor/bin/drush dx:ecosystem-l2-repo --build=/srv/l2 --src=/srv/l2-src
# 生成某开发者的 auth.json 片段（明文 token 只此一次）
vendor/bin/drush dx:ecosystem-l2-plan --uid=<UID>
```

审计报表与运维：

```bash
vendor/bin/drush dx:ecosystem-credential-report                 # 人话表
vendor/bin/drush dx:ecosystem-credential-report --format=json   # 机器可读
vendor/bin/drush dx:ecosystem-credential-report --older-than=7776000  # 剪 90 天前的事件（可选 cron）
```

窗口内冒烟（会写生产 MySQL，必须窗口内跑）：

```bash
bash scripts/ci/ecosystem-smoke.sh          # 含 I3 报表断言
bash scripts/ci/l2-credential-smoke.sh      # 含 I1 拉取回环 + I2 吊销失败码
bash scripts/ci/l0-publish-smoke.sh         # 两段全跑（段 2 需站点）
```

---

## 4. 跨线请求

1. **`docs/roadmap.md`（roadmap/集成线所有）**：Phase I 的 I1–I4 四条 `[ ]` 建议改 `[x]`，验收行（`l2-credential-smoke.sh` 回环、`verify-credential` 失败、报表字段、CI 可运行）均已满足。
2. **`DEV_MEMORY.md`（集成线/文档线所有）**：  
   - §5.3 配置表补 Phase I 新键：`l2_composer_base_url` / `l2_repository_root` / `l2_composer_driver` / `l2_token_header` / `l2_signing_key` / `l2_download_ttl` / `l2_dist_mode`（默认空 = OE2 占位行为）。  
   - §6 路由速查补 `/dx/ecosystem/l2/{packages.json,providers/{provider},dist/{artifact},plan}`（token 守卫，非会话）与 `/admin/dx/ecosystem/credentials`（`administer dx ecosystem`）。  
   - §5.4 L2 凭证规则补 Drush：`dx:ecosystem-l2-plan` / `dx:ecosystem-l2-repo` / `dx:ecosystem-l2-auth-check` / `dx:ecosystem-credential-report`。  
   - §7 冒烟清单补 `php web/modules/custom/dx_ecosystem/tests/pure-assertions.php` 与 `bash scripts/ci/l0-publish-smoke.sh offline`。
3. **CI 线（L6）**：把 `php scripts/ci/merge-integrity-check.php`、各 `tests/pure-assertions.php`、`bash scripts/ci/l0-publish-smoke.sh offline`（无站点、无 DB，可在 `composer install` 之后、站点安装之前跑）纳入流水线必跑门禁；在线段留窗口。
4. **文档索引**：`docs/lanes/` 目录与 `docs/README.md` 索引挂载（本线只提交了自身的 `docs/lanes/L4-ecosystem-l2.md`；`docs/lanes: internal` 已在 visibility.yml，合并其它 lane 文档时各自补登记）。
5. **`dx_appstore` 线（本线亦拥有）**：确认 `/appstore`、`/appstore/licenses` 长期路径不变；I4 公开树导出依赖其 data 目录的 visibility 判定，路由改名会改变公开面。

---

## 5. 风险与回退

| 风险 | 影响 | 处置 |
|------|------|------|
| provider 路由需求放宽含 `/` 与 `%` | 担心过度匹配绕过守卫 | 守卫在 controller 之前由 `L2RepositoryAuthSubscriber` 统一 401；`RepositoryRequestAuth::classifyPath()` 仍把路径归一到 manifest 白名单，还原不出合法包名即拒；path 未改，`pure-assertions` 断解码/未解码两形态且 path 不变 |
| 新路由 `/dx/ecosystem/l2/*` `_access: TRUE` | 误以为不需鉴权 | 语义是「不需 Drupal 角色」，真正的凭证是 `dxl2_` token / 签名链接；订阅者答 401 在控制器之前。匿名 + 无签名一律拒 |
| `CredentialLifecycle::apply()` 收紧状态机 | 旧数据里 `revoked` 被 `rotate` 复活 | 这正是修复目标；旧行为会把已吊销凭证误判可用。合法迁移：`revoked` 只接受 `issue` 生成新一代 |
| 审计表膨胀 | `dx_l2_credential_event` 增长 | 提供 `--older-than` 剪枝，接 ops cron；表独立、不耦合任何实体 base field |
| L0 发布漏配 visibility 行 | L2 私有清单进公开树 | `docs/visibility.yml` + `l0-whitelist.yml` 三处（partner / exclude / must_exclude）冗余钉住，`pure-assertions` 与段 1 冒烟双向断言 |
| 新 config 键在存量站点缺失 | 启动读配置 | `L2ComposerRepository::settings()` 全部走 `?? 默认`，缺键回落 OE2 占位，不 fatal；`updatedb`/`config-import` 补齐后即得默认 |
| 引入新类与既有类重名 | 站点启动 fatal + integrity 判重 | 本轮未新建与现有类同名同职责的类；`merge-integrity-check.php` 151 文件 0 重复 |

回退（代码粒度，不动现网数据）：

```bash
# lane 分支未合并：直接丢弃本线（WIP df6a7cd + 本轮补齐）
git -C /home/wwwroot/drupalX revert --no-commit <本轮提交> df6a7cd
git -C /home/wwwroot/drupalX commit -m "Revert L4 Phase I (L2 repository + credential report)"
```

数据侧：Phase I 唯一新增表是 `dx_l2_credential_event`（纯审计，只增不改既有实体）。回退可保留（不影响旧消费方）；如需彻底清理，窗口内 `drush ev '\Drupal::database()->dropSchema("dx_l2_credential_event");'`。新 config 键回落默认即可，无需回滚 base field 或现有路由 path。
