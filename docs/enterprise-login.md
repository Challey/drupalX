# Enterprise credit ID login (DrupalX)

Port of Topstar’s compact multi-method login UI, with **企业ID** (市场监督局统一社会信用代码) as the primary method instead of 跑者登录.

## Layout

1. **企业ID** — default panel; lookup + password via `/dx/auth/enterprise_*`
2. **其他方式** — circular icons below: WeChat / mobile (stubs) / account (core `#user-login-form`)

Deep link: `/user/login#enterprise`

## Security

- Login is **POST-only** with Drupal `X-CSRF-Token` (`_csrf_request_header_token`)
- Flood limits on lookup (IP; empty input skipped) and login (IP + credit code)
- Lookup returns masked company name only; UI shows inline status (found / not found / unbound / portal)
- Password never accepted via GET

## Module `dx_auth`

| Piece | Role |
|-------|------|
| `EnterpriseIdentityService` | Normalize / GB 32100 checksum / mask / resolve (binding → tenant settings → platform tenant) |
| `EnterpriseAccountLinker` | Bind / unbind UID, password check, platform portal redirect |
| `EnterpriseAuthController` | JSON `code` / `msg` / `data` (+ `redirect`) |
| Admin form | `/admin/dx/auth/enterprise` (bind + unbind; full credit code visible to admins) |

Schema table: `dx_auth_enterprise`.

## Theme assets

- Library `dx_portal_theme/login` attaches with `preprocess: false` (avoids CSS/JS aggregation drops)
- Also attached via `hook_page_attachments()` + preprocess; compact CSS remains inlined in the login twig as a fallback

## Enable

```bash
drush en dx_auth -y
drush updatedb -y
drush cr
```

Bind a code under Configuration → People → Enterprise login, or set tenant `credit_code` (validated + auto-binds current user).

## Still open

- WeChat OAuth / SMS mobile login backends (UI stubs only)
- Production: bind at least one real credit code so lookup returns `code=1`
- Prefer official packer deploy over ad-hoc rsync when packer permissions are fixed
