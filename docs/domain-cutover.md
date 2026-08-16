# Docs: production domain cutover for DrupalX + 短闻.
# See also docs/theme-studio.md (ent_apple) and XMT short-news.

## Target map

| Host | Product | Docroot | Drupal site |
|------|---------|---------|-------------|
| `www.drupal.org.cn` | **DrupalX** 主页 | `/home/wwwroot/drupalX/web` | `sites/default` |
| `drupal.org.cn` | **DrupalX**（301 → www） | 同上 | `sites/default` |
| `x.drupal.org.cn` | **DrupalX**（保留） | 同上 | `sites/default` |
| `news.drupal.org.cn` | **短闻** | `/home/wwwroot/xmt/web` | `sites/drupal.org.cn` |
| `duanwen.drupal.org.cn` | 短闻别名 | 同上 | 同上 |
| `xmt.pub` | 短闻总站 / 信流 | XMT | `sites/xmt.pub` |

## Live status (Server B `47.113.217.2`)

| Host | Status |
|------|--------|
| `https://www.drupal.org.cn` | DrupalX (same body as `x`) |
| `https://x.drupal.org.cn` | DrupalX |
| `http://drupal.org.cn` | 301 → `https://www.drupal.org.cn` (when A record exists) |
| `https://drupal.org.cn` | **Blocked**: authoritative DNS has **no A record** for apex; LE cannot issue SAN |

## DNS (required for apex)

At 万网 / 阿里云 DNS (`dns13/14.hichina.com`), add:

| Type | Host | Value |
|------|------|-------|
| A | `@` | `47.113.217.2` |
| A | `www` | `47.113.217.2` (already) |
| A | `x` | `47.113.217.2` (already) |
| A | `news` | `47.113.217.2` |
| A | `duanwen` | `47.113.217.2` |

Verify:

```bash
dig @dns13.hichina.com drupal.org.cn A +short   # expect 47.113.217.2
```

Then on Server B:

```bash
/usr/local/acme.sh/acme.sh --issue -d www.drupal.org.cn -d drupal.org.cn \
  -w /home/wwwroot/drupalX/web --force --server letsencrypt
/usr/local/acme.sh/acme.sh --install-cert -d www.drupal.org.cn \
  --fullchain-file /usr/local/nginx/conf/ssl/www.drupal.org.cn/fullchain.cer \
  --key-file /usr/local/nginx/conf/ssl/www.drupal.org.cn/www.drupal.org.cn.key \
  --reloadcmd "nginx -s reload"
# Uncomment HTTPS apex server in www.drupal.org.cn.conf, nginx -t && nginx -s reload
```

## Apply (ops)

```bash
# 1) Install nginx snippets
sudo cp /home/wwwroot/drupalX/setup/nginx/www.drupal.org.cn.conf /usr/local/nginx/conf/vhost/
sudo cp /home/wwwroot/drupalX/setup/nginx/news.drupal.org.cn.conf /usr/local/nginx/conf/vhost/

# 2) Apache (LNMPA :88)
sudo cp /home/wwwroot/drupalX/setup/apache/www.drupal.org.cn.conf /usr/local/apache/conf/vhost/
sudo cp /home/wwwroot/drupalX/setup/apache/news.drupal.org.cn.conf /usr/local/apache/conf/vhost/

# 3) sites.php
sudo cp /home/wwwroot/drupalX/setup/sites.drupal.org.cn.php /home/wwwroot/drupalX/web/sites/sites.php

sudo nginx -t && sudo nginx -s reload
# restart/reload httpd as appropriate

# 4) Theme Studio + Apple pack (optional)
cd /home/wwwroot/drupalX
vendor/bin/drush --uri=https://www.drupal.org.cn en dx_theme -y
vendor/bin/drush --uri=https://www.drupal.org.cn dx:theme-apply ent_apple
```

## LNMPA note

This host proxies nginx → Apache `:88`. Apply **both** nginx and Apache vhosts.

## Preview

`https://www.drupal.org.cn/?dx_skin=ent_apple`
