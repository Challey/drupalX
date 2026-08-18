# DrupalX 统一登录

模块：`dx_auth`（DrupalX Auth）  
入口：`/user/login`（导航文案为「统一登录」）  
绑定页：`/dx/auth/bindings`（需登录）  
管理：`/admin/dx/auth/providers` · `/admin/dx/auth/enterprise`

---

## 需求从哪来

| 任务 | 时间 | 原存放 | 是否进当前主树 |
|------|------|--------|----------------|
| 登录/注册合一：没有账号则自动注册并提示 | 2026-08-13 | `dx_portal` 的 `LoginRegisterForm` / `LoginRegisterService`；后归档在 `/home/challey/dx-local-wip-20260815/` | **当时未并入当前门户登录**。企业 ID 主题页覆盖了 `/user/login` 后，那套表单被冲掉。现已迁入 `dx_auth` 账号通道。 |
| 多方式网关（企业信用代码 / 微信 / 短信 / Google） | 2026-08 | `dx_auth`（Topstar `wechatquery` + `aliyunsms` 移植） | **已在主树**，线上 `/user/login`。 |
| Topstar「统一登录方式」绑定面板（含跑者 ID 归并） | Topstar | `wechatquery` `login-bindings` | **不移植跑者 ID**。DrupalX 绑定页只覆盖本产品身份：企业ID、账号、手机、微信、Google。 |

`/user/register` **没有**改成登录页。首页「开通门户」仍走注册/开通，与统一登录分开。

---

## 产品行为

一个 Drupal 用户，多种登录方式。

1. **企业ID**（统一社会信用代码 + 已绑定账号的密码）  
   `POST /dx/auth/enterprise_login`。企业须先由管理员在「企业登录」绑定。
2. **邮箱 / 用户名 + 密码**  
   `POST /dx/auth/account_login`。  
   - 账号存在 → 校验密码登录。  
   - 账号不存在且标识是**邮箱**、密码 ≥ 8 位 → **自动注册并登录**，提示「未检测到账号，已为您自动注册并登录」。  
   - 纯用户名且不存在 → 不注册（避免乱建号）。  
   开关：`dx_auth.settings:account_auto_register`（默认开）。
3. **微信** 扫码或公众号内 OAuth：`/dx/auth/wechat_*`。首次 openid 自动建号。
4. **手机短信**（阿里云）：`/dx/auth/sms_send` · `sms_login`。首次号码自动建号。
5. **Google**（海外）：`/dx/auth/google_jump`。按 `sub` + 已验证邮箱登录或建号；同邮箱可并到已有用户。

已登录用户再走微信 / Google / 短信，会**绑定到当前用户**；若该身份已在其他账号上，则把那个账号**归并**进来（两边都有不同手机号时拒绝）。uid 1 管理员不可被吞并。

绑定页 `/dx/auth/bindings`：短信绑手机、密码归并已有账号、**扫码微信**（scene 带 `bind_uid`）、Google 授权绑定。

---

## 前端

主题 `dx_portal_theme`：`templates/page--user--login.html.twig` + `js/login.js`。

- 默认面板：企业ID  
- 底部图标：微信 / 手机 / 账号  
- Google 单独一块（按 IP/配置显示）  
- 账号提交走 `/dx/auth/account_login`（不再提交核心 `user_login_form`）

---

## 绑定接口

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/dx/auth/wechat_qrcode?mode=bind` | 已登录：生成绑定二维码（scene 含 `bind_uid`） |
| POST | `/dx/auth/bind_mobile` | 短信验证后绑手机并归并 |
| POST | `/dx/auth/claim_account` | 用户名/邮箱+密码归并 |
| GET | `/dx/auth/bindings/status` | 当前用户绑定状态 |

---

| 表 | 用途 |
|----|------|
| `dx_auth_enterprise` | 信用代码 → uid |
| `dx_auth_wechat` | openid → uid |
| `dx_auth_google` | google_sub → uid |
| `dx_auth_mobile` | 手机号 → uid |

---

## 运维

```bash
vendor/bin/drush en dx_auth -y
vendor/bin/drush updb -y
# 提供商：/admin/dx/auth/providers
# 企业绑定：/admin/dx/auth/enterprise
```

凭据在 `dx_auth.settings`（微信 AppID/Secret、阿里云短信、Google OAuth）。生产已配的不要写进仓库。

Google 回调：`https://www.drupal.org.cn/dx/auth/google_jump`  
微信公众号服务器 URL：`https://www.drupal.org.cn/dx/auth/wechat_callback`

---

## 验收清单

- [x] 多方式同一入口 `/user/login`
- [x] 邮箱首次登录自动注册并提示（原 8/13 需求）
- [x] 微信 / 短信 / Google 首次自动建号
- [x] 登录后 `/dx/auth/bindings` 查看绑定；微信/Google 可补绑
- [x] 本文档
- [x] 账号冲突时自动归并（对照 Topstar mergeUsers；双手机号冲突则拒绝）
- [x] 绑定页短信验证码补绑手机
- [x] 扫码微信绑定到已登录用户（`wechat_qrcode?mode=bind` + scene `bind_uid`）
