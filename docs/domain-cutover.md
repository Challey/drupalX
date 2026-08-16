# Docs: production domain cutover for DrupalX + 短闻.
# See also docs/theme-studio.md (ent_apple) and XMT short-news.

## Target map

| Host | Product | Docroot | Drupal site |
|------|---------|---------|-------------|
| `www.drupal.org.cn` | **DrupalX** 主页 | `/home/wwwroot/drupalX/web` | `sites/default` |
| `drupal.org.cn` | **DrupalX**（同 www） | 同上 | `sites/default` |
| `x.drupal.org.cn` | **DrupalX**（保留） | 同上 | `sites/default` |
| `news.drupal.org.cn` | **短闻** | `/home/wwwroot/xmt/web` | `sites/drupal.org.cn` |
| `duanwen.drupal.org.cn` | 短闻别名 | 同上 | 同上 |
| `xmt.pub` | 短闻总站 / 信流 | XMT | `sites/xmt.pub` |

## Apply (ops)

```bash
# 1) Install nginx snippets
sudo cp /home/wwwroot/drupalX/setup/nginx/www.drupal.org.cn.conf /usr/local/nginx/conf/vhost/
sudo cp /home/wwwroot/drupalX/setup/nginx/news.drupal.org.cn.conf /usr/local/nginx/conf/vhost/

# 2) Retire old XMT www mapping (or leave file but remove www from server_name)
# Edit /usr/local/nginx/conf/vhost/drupal.org.cn.conf → only keep lab alias if needed
#   server_name drupalcn.wsl;

sudo nginx -t && sudo nginx -s reload

# 3) DNS
# www / apex / x  → DrupalX server
# news / duanwen  → same server (XMT docroot)

# 4) Enable Theme Studio + Apple pack on DrupalX
cd /home/wwwroot/drupalX
vendor/bin/drush en dx_theme -y
vendor/bin/drush dx:theme-apply ent_apple
```

## Preview without DNS

`http://www.drupal.org.cn/?dx_skin=ent_apple`（hosts 指到本机后）

## LNMPA note

This host proxies nginx → Apache `:88`. Apply **both**:

- `setup/nginx/*.conf` → `/usr/local/nginx/conf/vhost/`
- `setup/apache/*.conf` → `/usr/local/apache/conf/vhost/`

Then `nginx -s reload` and restart/reload Apache (`httpd`).

For the A-forwarding/B-business dual-node policy and automatic DNS takeover,
see [`setup/ha/README.md`](../setup/ha/README.md).

## Theme

Production default applied: `drush dx:theme-apply ent_apple`

Preview: `https://www.drupal.org.cn/?dx_skin=ent_apple`
