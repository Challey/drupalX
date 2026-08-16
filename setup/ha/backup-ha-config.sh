#!/usr/bin/env bash
set -euo pipefail

PATH="/usr/local/bin:/usr/local/sbin:/usr/bin:/usr/sbin:/bin:/sbin:${PATH:-}"

if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
  echo "Run as root." >&2
  exit 1
fi

role="${1:-}"
backup_root="${2:-/var/backups/drupalx-ha}"
[[ "$role" == "a" || "$role" == "b" ]] || {
  echo "Usage: $0 <a|b> [backup-root]" >&2
  exit 2
}

timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
backup="$backup_root/${timestamp}-node-${role}"
mkdir -p "$backup/payload" "$backup/metadata"
chmod 700 "$backup_root" "$backup"

paths=(
  /usr/local/nginx/conf/nginx.conf
  /usr/local/nginx/conf/proxy-pass-php.conf
  /usr/local/nginx/conf/balanceServers.conf
  /usr/local/nginx/conf/vhost/www.drupal.org.cn.conf
  /usr/local/nginx/conf/ssl/www.drupal.org.cn
  /usr/local/apache/conf/vhost/www.drupal.org.cn.conf
  /root/dx-dns-failover.env
  /usr/local/sbin/dx-dns-failover
  /etc/systemd/system/dx-dns-failover.service
  /etc/systemd/system/dx-dns-failover.timer
  /home/wwwroot/drupalX/web/sites/default/settings.php
  /home/wwwroot/drupalX/web/sites/sites.php
  /home/wwwroot/drupalX/web/sites/default/files
)

: >"$backup/metadata/present.list"
: >"$backup/metadata/absent.list"
for path in "${paths[@]}"; do
  if [[ -e "$path" || -L "$path" ]]; then
    printf '%s\n' "$path" >>"$backup/metadata/present.list"
    cp -a --parents "$path" "$backup/payload"
  else
    printf '%s\n' "$path" >>"$backup/metadata/absent.list"
  fi
done

printf '%s\n' "$role" >"$backup/metadata/role"
hostname >"$backup/metadata/hostname"
date -u --iso-8601=seconds >"$backup/metadata/created-at"
if crontab -l >"$backup/metadata/root.crontab" 2>/dev/null; then
  touch "$backup/metadata/root-crontab-present"
fi

for unit in nginx httpd dx-dns-failover.timer; do
  {
    systemctl is-enabled "$unit" 2>/dev/null || true
    systemctl is-active "$unit" 2>/dev/null || true
  } >"$backup/metadata/systemd-$unit"
done

aliyun_bin="$(command -v aliyun || true)"
if [[ "$role" == "b" && -n "$aliyun_bin" ]]; then
  "$aliyun_bin" alidns DescribeDomainRecords \
    --DomainName drupal.org.cn \
    --Type A \
    --PageSize 100 >"$backup/metadata/dns-records.json"
fi

(
  cd "$backup"
  find payload metadata -type f -print0 |
    sort -z |
    xargs -0 sha256sum >manifest.sha256
)
chmod -R go-rwx "$backup"
ln -sfn "$backup" "$backup_root/latest-node-${role}"

echo "$backup"
