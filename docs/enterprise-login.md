# Enterprise credit ID login (DrupalX)

Port of Topstar’s compact multi-method login UI, with **企业ID** (市场监督局统一社会信用代码) as the primary method instead of 跑者登录.

## Layout

1. **企业ID** — default tab; lookup + password via `/dx/auth/enterprise_*`
2. **账号登录** — bottom icon; submits core `#user-login-form`
3. **微信** — bottom icon; Open Platform QR (`WxLogin`) or in-WeChat Official Account OAuth when enabled
4. **手机** — bottom icon; Aliyun SMS OTP (or test mode) when enabled

Deep link: `/user/login#enterprise` · `#qrcode` · `#mobile` · `#account`

## Security

- Login is **POST-only** with Drupal `X-CSRF-Token` (`_csrf_request_header_token`) except the WeChat OAuth **GET callback** (protected by one-time `state`)
- Flood limits on lookup, enterprise login, WeChat start, SMS send/login
- Lookup returns masked company name only
- Password never accepted via GET
- SMS codes stored as HMAC, 5-minute TTL, 5 verify attempts
- Login CSS/JS use `preprocess: false` plus a direct `<link>` so aggregation cannot drop the compact UI

## Module `dx_auth`

| Piece | Role |
|-------|------|
| `EnterpriseIdentityService` | Normalize / GB 32100 checksum / mask / resolve (binding → tenant settings → platform tenant) |
| `EnterpriseAccountLinker` | Bind UID, password check, platform portal redirect |
| `WeChatAuthService` | QR Connect + Official Account OAuth, `dx_auth_wechat` map |
| `SmsAuthService` | Aliyun Dysmsapi / test-mode OTP, `dx_auth_mobile` map |
| `EnterpriseAuthController` | JSON `code` / `msg` / `data` (+ `redirect`) |
| `SocialAuthController` | WeChat start/callback, SMS send/login |
| Admin forms | `/admin/dx/auth/enterprise` · `/admin/dx/auth/providers` |

Schema tables: `dx_auth_enterprise`, `dx_auth_wechat`, `dx_auth_mobile`.

## Enable

```bash
drush en dx_auth -y
drush updatedb -y
drush cr
```

Bind a credit code under Configuration → People → Enterprise login, or set tenant `credit_code` (auto-binds current user).

### WeChat

1. 微信开放平台网站应用：填写 AppID / AppSecret，授权回调域与 **精确** 回调 URL：`https://<host>/dx/auth/wechat/callback`
2. 公众号（可选，微信内一键登录）：填写公众号 AppID / AppSecret，网页授权域名同上
3. Configuration → People → WeChat and SMS login → enable WeChat

未配置时底部「微信」仍显示「暂未开通」。

### SMS

1. 阿里云短信：AccessKey、签名、模板（变量默认 `code`）
2. 或先打开 **Test mode**：发送接口在 JSON 里返回 `debug_code`，登录页会填入验证码（仅用于验收，生产必须关闭）
3. Enable mobile SMS login

## Unfinished / ops

- Live WeChat/SMS on production needs credentials in `/admin/dx/auth/providers` (not committed)
- Enterprise lookup has no market-supervision registry; bindings are manual
- Ghost modules in some env `core.extension` (`car_hailing`, etc.) are unrelated; they can block `drush en` locally
