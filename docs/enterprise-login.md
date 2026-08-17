# Enterprise credit ID login (DrupalX)

Port of Topstar’s compact multi-method login UI, with **企业ID** (市场监督局统一社会信用代码) as the primary method instead of 跑者登录.

## Layout

1. **企业ID** — default tab; lookup + password via `/dx/auth/enterprise_*`
2. **其他方式** icons — WeChat / mobile / account (same row as Topstar)
3. **Google** — **solo block below** other methods (not peer to WeChat); geo-gated for mainland CN

Deep link: `/user/login#enterprise`

On tenant sites whose default theme is `gavias_kiamo`, `dx_auth`’s theme negotiator loads `dx_portal_theme` for `/user/login`.

## Why Google is below (not next to WeChat)

Matches Topstar `docs/RUNNER-LOGIN.md` and login twig:

- WeChat / SMS / account are CN-centric “其他登录方式”
- Google is western OAuth, full-width CTA, hidden in mainland China unless `google_ignore_geo`

Putting Google in the icon row would mix incompatible regional providers and clutter the CN primary path.

## Social providers (Topstar reuse)

| Provider | Topstar source | DrupalX config (`dx_auth.settings`) | Routes |
|----------|----------------|-------------------------------------|--------|
| WeChat MP QR + OAuth | `wechatquery` | `wechat_app_id`, `wechat_secret`, `wechat_token` | `/dx/auth/wechat_*` |
| Aliyun SMS OTP | `aliyunsms` | `sms_access_key`, `sms_access_secret`, `sms_sign_name`, `sms_template_code` | `/dx/auth/sms_*` |
| Google OAuth2 | `wechatquery` google_jump | `google_client_id`, `google_client_secret`, `google_redirect_uri`, `google_ignore_geo` | `/dx/auth/google_jump` |

Admin: **Configuration → People → Login providers** (`/admin/dx/auth/providers`).

Reuse Topstar credentials by copying the same keys from `wechatquery.settings` / `aliyunsms.settings` into this form (do not commit secrets). SMS sign/template must be approved for the DrupalX brand (Topstar uses `跑者之星` / `SMS_465170903`).

MP server URL: `https://<host>/dx/auth/wechat_callback`  
Google redirect URI: `https://<host>/dx/auth/google_jump`

## Security

- Enterprise password login: POST + anonymous CSRF validation + flood
- SMS: flood on IP + mobile; OTP TTL 300s; 60s client cooldown
- Google: mainland CN hidden by default (CF-IPCountry); require verified email
- Portal redirects: HTTPS only without credentials/fragments
- Enterprise login requires explicit `dx_auth_enterprise` binding

## Module pieces

| Piece | Role |
|-------|------|
| `EnterpriseIdentityService` / `EnterpriseAccountLinker` | Credit ID |
| `WechatAuthService` / `SmsAuthService` / `GoogleAuthService` | Providers |
| `SocialAccountLinker` | openid / mobile / google_sub ↔ uid |
| `SocialAuthController` | JSON + OAuth jumps |
| Drush | `dx:auth-bind` / `list` / `unbind` / `validate` |

## Enable

```bash
drush en dx_auth -y
drush theme:enable dx_portal_theme -y
drush updatedb -y
drush cr
# Fill /admin/dx/auth/providers then:
drush cset dx_auth.settings wechat_enabled 1 -y
drush cset dx_auth.settings sms_enabled 1 -y
drush cset dx_auth.settings google_enabled 1 -y
```
