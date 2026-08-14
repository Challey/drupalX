Recipes allow the automation of Drupal module and theme installation and
configuration.

## DrupalX recipes

| Recipe | Purpose |
|--------|---------|
| `dx_platform` | Platform control plane modules |
| `dx_tenant_portal` | Tenant portal + AI gateway |
| `dx_ai_stack` | AI gateway + key |
| `dx_appstore` | App Store entities |
| `dx_industry_manufacturing` | 制造行业门户预设 |
| `dx_industry_retail` | 零售行业门户预设 |
| `dx_industry_services` | 服务行业门户预设 |

Apply (site already installed):

```bash
vendor/bin/drush recipe ../recipes/dx_industry_manufacturing
vendor/bin/drush dx:portal-seed --industry=manufacturing
```
