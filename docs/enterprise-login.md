# Enterprise credit ID login (DrupalX)

Port of Topstar’s compact multi-method login UI, with **企业ID** (市场监督局统一社会信用代码) as the primary method instead of 跑者登录.

## Layout

1. **企业ID** — default tab; lookup + password via `/dx/auth/enterprise_*`
2. **账号登录** — second tab; submits core `#user-login-form`
3. **其他方式** — WeChat / mobile below the tabs (stubs until providers are wired)

Deep link: `/user/login#enterprise`

On tenant sites whose default theme is `gavias_kiamo` (or another pack), `dx_auth`’s theme negotiator still loads `dx_portal_theme` for `/user/login` so the compact enterprise UI appears.

## Security

- Credential login is **POST-only**; the controller validates `X-CSRF-Token` for **anonymous and authenticated** sessions (core’s `_csrf_request_header_token` alone only covers authenticated sessions)
- Flood limits on lookup (IP) and login (IP + credit code)
- Lookup returns only masked enterprise details
- Password never accepted via GET
- Tenant portal redirects require an absolute HTTPS URL without credentials or fragments
- Login requires an explicit `dx_auth_enterprise` binding (tenant settings alone are lookup preview only)

## Module `dx_auth`

| Piece | Role |
|-------|------|
| `EnterpriseIdentityService` | Normalize / GB 32100 checksum / mask / resolve (binding → tenant settings preview → platform tenant) |
| `EnterpriseAccountLinker` | Bind UID, password check, safe portal redirect |
| `EnterpriseAuthController` | JSON `code` / `msg` / `data` (+ `redirect`) |
| `EnterpriseLoginThemeNegotiator` | Force portal theme on `/user/login` |
| Admin form | `/admin/dx/auth/enterprise` |
| Drush | `dx:auth-bind` / `dx:auth-list` / `dx:auth-unbind` / `dx:auth-validate` |

Schema table: `dx_auth_enterprise`.

## Enable

```bash
drush en dx_auth -y
drush theme:enable dx_portal_theme -y
drush updatedb -y
drush cr
```

`recipes/dx_tenant_portal` and industry recipes install `dx_auth`. Tenant provision enables both `dx_auth` and `dx_portal_theme` (default site theme remains `gavias_kiamo`).

Bind a code under Configuration → People → Enterprise login, tenant settings `credit_code` (auto-binds the saving user), or:

```bash
drush dx:auth-bind 91110000MA0123456P 2 "示例科技有限公司"
drush dx:auth-list
```

Remove bindings from the admin screen or `drush dx:auth-unbind <id>` when rotating accounts.

## Still open

- WeChat OAuth / QR login provider wiring
- Mobile SMS OTP provider wiring
