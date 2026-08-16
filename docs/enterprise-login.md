# Enterprise credit ID login (DrupalX)

Port of Topstar’s compact multi-method login UI, with **企业ID** (市场监督局统一社会信用代码) as the primary method instead of 跑者登录.

## Layout

1. **企业ID** — default tab; lookup + password via `/dx/auth/enterprise_*`
2. **账号登录** — second tab; submits core `#user-login-form`
3. **其他方式** — WeChat / mobile below the tabs (stubs until providers are wired)

## Module `dx_auth`

| Piece | Role |
|-------|------|
| `EnterpriseIdentityService` | Normalize / validate / mask / resolve credit codes (binding table → `dx_tenant.settings` → platform `dx_tenant` entity) |
| `EnterpriseAccountLinker` | Bind UID, `loginByEnterprise` with `PasswordInterface::check`, list bindings |
| `EnterpriseAuthController` | JSON `code` / `msg` / `data` (Topstar shape) |
| Admin form | `/admin/dx/auth/enterprise` |

Schema table: `dx_auth_enterprise`.

## Tenant / platform hooks

- Tenant settings field `credit_code` (+ auto-bind on save when `dx_auth` is enabled)
- Platform `dx_tenant` entity field `credit_code` (`dx_platform_update_10001`)
- `TenantProvisioner` enables `dx_auth` for new tenants

## Theme

`dx_portal_theme` attaches `login` library on `user.login`, suggestion `page__user__login`, and i18n via `includes/login_i18n.php` (zh-hans / zh-hant / en). Visual tokens reuse DrupalX teal (`--dx-teal`), not purple.
