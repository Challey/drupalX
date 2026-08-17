# Enterprise credit ID login (DrupalX)

Port of Topstar’s compact multi-method login UI, with **企业ID** (市场监督局统一社会信用代码) as the primary method instead of 跑者登录.

## Layout

1. **企业ID** — default tab; lookup + password via `/dx/auth/enterprise_*`
2. **账号登录** — second tab; submits core `#user-login-form`
3. **其他方式** — WeChat / mobile below the tabs (stubs until providers are wired)

Deep link: `/user/login#enterprise`

## Security

- Credential login is **POST-only** with Drupal `X-CSRF-Token` (`_csrf_request_header_token`); the separate lookup endpoint is read-only
- Flood limits on lookup (IP) and login (IP + credit code)
- Lookup returns only masked enterprise details
- Password never accepted via GET
- Tenant portal redirects require an absolute HTTPS URL without credentials or fragments

## Module `dx_auth`

| Piece | Role |
|-------|------|
| `EnterpriseIdentityService` | Normalize / GB 32100 checksum / mask / resolve (binding → tenant settings → platform tenant) |
| `EnterpriseAccountLinker` | Bind UID, password check, platform portal redirect |
| `EnterpriseAuthController` | JSON `code` / `msg` / `data` (+ `redirect`) |
| Admin form | `/admin/dx/auth/enterprise` |

Schema table: `dx_auth_enterprise`.

## Enable

```bash
drush en dx_auth -y
drush updatedb -y
drush cr
```

Bind a code under Configuration → People → Enterprise login, or set tenant `credit_code` (auto-binds current user).
