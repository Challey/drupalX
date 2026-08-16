#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
A_HOST="${DX_HA_A_HOST:-root@47.113.227.103}"
B_HOST="${DX_HA_B_HOST:-root@47.113.217.2}"
A_PUBLIC_IP="${DX_HA_A_PUBLIC_IP:-47.113.227.103}"
B_PUBLIC_IP="${DX_HA_B_PUBLIC_IP:-47.113.217.2}"
A_PRIVATE_IP="${DX_HA_A_PRIVATE_IP:-172.16.34.121}"
B_PRIVATE_IP="${DX_HA_B_PRIVATE_IP:-172.16.34.126}"
SSH_OPTS=(-o BatchMode=yes -o StrictHostKeyChecking=accept-new -o ConnectTimeout=15)

[[ "${1:-}" == "--apply" ]] || {
  echo "Usage: $0 --apply" >&2
  exit 2
}

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
A_BACKUP=""
B_BACKUP=""
A_MUTATED=0
B_MUTATED=0
SUCCESS=0

ssh_run() {
  local host="$1"
  shift
  ssh "${SSH_OPTS[@]}" "$host" "$@"
}

copy_to() {
  local host="$1" source="$2" destination="$3"
  scp "${SSH_OPTS[@]}" "$source" "${host}:${destination}"
}

rollback_on_error() {
  local line="$1" original_status="${2:-1}" rollback_status=0
  [[ "$SUCCESS" -eq 0 ]] || return 0
  trap - ERR
  set +e
  echo "Deployment failed near line $line; restoring captured production state." >&2
  if [[ "$A_MUTATED" -eq 1 && -n "$A_BACKUP" ]]; then
    ssh_run "$A_HOST" "/usr/local/sbin/dx-ha-restore '$A_BACKUP'" || rollback_status=1
  fi
  if [[ "$B_MUTATED" -eq 1 && -n "$B_BACKUP" ]]; then
    ssh_run "$B_HOST" "/usr/local/sbin/dx-ha-restore '$B_BACKUP' --restore-dns" || rollback_status=1
  fi
  [[ "$rollback_status" -eq 0 ]] ||
    echo "Automatic rollback reported an error; inspect both nodes immediately." >&2
  exit "$original_status"
}
trap 'rollback_on_error "$LINENO" "$?"' ERR

echo "Preflight: production connectivity and services"
for host in "$A_HOST" "$B_HOST"; do
  ssh_run "$host" 'nginx -t && /usr/local/apache/bin/apachectl -t'
done
ssh_run "$A_HOST" "curl -fsS -H 'Host: www.drupal.org.cn' http://$B_PRIVATE_IP:88/ >/dev/null"
ssh_run "$B_HOST" 'aliyun sts GetCallerIdentity >/dev/null'

echo "Capture complete node-level backups"
for role in a b; do
  host="$A_HOST"
  [[ "$role" == "b" ]] && host="$B_HOST"
  copy_to "$host" "$ROOT/setup/ha/backup-ha-config.sh" /tmp/dx-ha-backup
  copy_to "$host" "$ROOT/setup/ha/restore-ha-config.sh" /tmp/dx-ha-restore
  backup_path="$(ssh_run "$host" "chmod 700 /tmp/dx-ha-backup /tmp/dx-ha-restore; /tmp/dx-ha-backup '$role'")"
  ssh_run "$host" 'install -m 0700 /tmp/dx-ha-restore /usr/local/sbin/dx-ha-restore'
  if [[ "$role" == "a" ]]; then
    A_BACKUP="$backup_path"
  else
    B_BACKUP="$backup_path"
  fi
done
echo "A backup: $A_BACKUP"
echo "B backup: $B_BACKUP"

release=/tmp/dx-ha-release
ssh_run "$B_HOST" "rm -rf '$release'; mkdir -p '$release'"
copy_to "$B_HOST" "$ROOT/setup/ha/lnmpa/node-b-proxy-pass-php.conf" "$release/proxy-pass-php.conf"
copy_to "$B_HOST" "$ROOT/setup/ha/lnmpa/www.drupal.org.cn.conf" "$release/www.drupal.org.cn.conf"
copy_to "$B_HOST" "$ROOT/setup/apache/www.drupal.org.cn.conf" "$release/apache-www.drupal.org.cn.conf"
copy_to "$B_HOST" "$ROOT/setup/ha/dns-failover.sh" "$release/dx-dns-failover"
copy_to "$B_HOST" "$ROOT/setup/ha/systemd/dx-dns-failover.service" "$release/dx-dns-failover.service"
copy_to "$B_HOST" "$ROOT/setup/ha/systemd/dx-dns-failover.timer" "$release/dx-dns-failover.timer"

cat >"$tmp/dx-dns-failover.env" <<EOF
A_HEALTH_URL=http://$A_PRIVATE_IP/healthz
A_PUBLIC_IP=$A_PUBLIC_IP
B_PUBLIC_IP=$B_PUBLIC_IP
HEALTH_HOST=www.drupal.org.cn
DOMAIN_NAME=drupal.org.cn
RR_LIST="www @ x"
TYPE=A
TTL=60
FAIL_THRESHOLD=3
RECOVERY_THRESHOLD=3
CONNECT_TIMEOUT=3
MAX_TIME=5
STATUS_FILE=/var/lib/dx-ha/dns-state
LOG_FILE=/var/log/dx-dns-failover.log
LOCK_FILE=/run/lock/dx-dns-failover.lock
EOF
copy_to "$B_HOST" "$tmp/dx-dns-failover.env" "$release/dx-dns-failover.env"

echo "Deploy B local-business and DNS failover configuration"
B_MUTATED=1
ssh_run "$B_HOST" "
  set -e
  systemctl disable --now dx-dns-failover.timer 2>/dev/null || true
  install -m 0644 '$release/proxy-pass-php.conf' /usr/local/nginx/conf/proxy-pass-php.conf
  install -m 0644 '$release/www.drupal.org.cn.conf' /usr/local/nginx/conf/vhost/www.drupal.org.cn.conf
  install -m 0644 '$release/apache-www.drupal.org.cn.conf' /usr/local/apache/conf/vhost/www.drupal.org.cn.conf
  install -m 0755 '$release/dx-dns-failover' /usr/local/sbin/dx-dns-failover
  install -m 0600 '$release/dx-dns-failover.env' /root/dx-dns-failover.env
  install -m 0644 '$release/dx-dns-failover.service' /etc/systemd/system/dx-dns-failover.service
  install -m 0644 '$release/dx-dns-failover.timer' /etc/systemd/system/dx-dns-failover.timer
  crontab -l 2>/dev/null | awk '!/dns-switcher\\/dns_switch\\.sh/' | crontab -
  systemctl daemon-reload
  nginx -t
  /usr/local/apache/bin/apachectl -t
  /etc/init.d/httpd restart
  /etc/init.d/nginx reload
  curl -fsS -H 'Host: www.drupal.org.cn' http://127.0.0.1:88/ >/dev/null
"

echo "Synchronize Drupal site state and current dual-domain certificate B -> A"
A_MUTATED=1
ssh "${SSH_OPTS[@]}" "$B_HOST" \
  'tar -C /home/wwwroot/drupalX -czf - web/sites/default/settings.php web/sites/sites.php web/sites/default/files' |
  ssh "${SSH_OPTS[@]}" "$A_HOST" \
    'tar -C /home/wwwroot/drupalX -xzf -; chown -R www:www /home/wwwroot/drupalX/web/sites/default/settings.php /home/wwwroot/drupalX/web/sites/sites.php /home/wwwroot/drupalX/web/sites/default/files'
ssh "${SSH_OPTS[@]}" "$B_HOST" \
  'tar -C /usr/local/nginx/conf/ssl -czf - www.drupal.org.cn' |
  ssh "${SSH_OPTS[@]}" "$A_HOST" \
    'rm -rf /usr/local/nginx/conf/ssl/www.drupal.org.cn; tar -C /usr/local/nginx/conf/ssl -xzf -'

ssh_run "$A_HOST" "rm -rf '$release'; mkdir -p '$release'"
copy_to "$A_HOST" "$ROOT/setup/ha/lnmpa/node-a-balance-servers.conf" "$release/balanceServers.conf"
copy_to "$A_HOST" "$ROOT/setup/ha/lnmpa/node-a-proxy-pass-php.conf" "$release/proxy-pass-php.conf"
copy_to "$A_HOST" "$ROOT/setup/ha/lnmpa/www.drupal.org.cn.conf" "$release/www.drupal.org.cn.conf"
copy_to "$A_HOST" "$ROOT/setup/apache/www.drupal.org.cn.conf" "$release/apache-www.drupal.org.cn.conf"

echo "Deploy A edge/B-first business configuration"
ssh_run "$A_HOST" "
  set -e
  install -m 0644 '$release/balanceServers.conf' /usr/local/nginx/conf/balanceServers.conf
  install -m 0644 '$release/proxy-pass-php.conf' /usr/local/nginx/conf/proxy-pass-php.conf
  install -m 0644 '$release/www.drupal.org.cn.conf' /usr/local/nginx/conf/vhost/www.drupal.org.cn.conf
  install -m 0644 '$release/apache-www.drupal.org.cn.conf' /usr/local/apache/conf/vhost/www.drupal.org.cn.conf
  grep -Eq '^[[:space:]]*include[[:space:]]+balanceServers\\.conf;' /usr/local/nginx/conf/nginx.conf
  nginx -t
  /usr/local/apache/bin/apachectl -t
  /etc/init.d/httpd restart
  /etc/init.d/nginx reload
  curl -fsS -H 'Host: www.drupal.org.cn' http://127.0.0.1:88/ >/dev/null
  curl -fsS -H 'Host: www.drupal.org.cn' http://$A_PRIVATE_IP/healthz >/dev/null
  curl -kfsS --resolve www.drupal.org.cn:443:$A_PUBLIC_IP https://www.drupal.org.cn/ >/dev/null
"

echo "Initialize recovery state, switch authoritative DNS to A, and enable timer"
ssh_run "$B_HOST" "
  set -e
  rm -f /var/lib/dx-ha/dns-state
  for attempt in 1 2 3; do
    systemctl start dx-dns-failover.service
    sleep 2
  done
  grep -q '^a ' /var/lib/dx-ha/dns-state
  systemctl enable --now dx-dns-failover.timer
  aliyun alidns DescribeDomainRecords --DomainName drupal.org.cn --Type A --PageSize 100 |
    python3 -c 'import json,sys; d=json.load(sys.stdin); r=[x for x in d.get(\"DomainRecords\",{}).get(\"Record\",[]) if x.get(\"RR\") in (\"www\",\"@\",\"x\")]; assert len(r)==3 and all(x.get(\"Value\")==\"$A_PUBLIC_IP\" and int(x.get(\"TTL\"))==60 for x in r), r'
"

SUCCESS=1
trap - ERR
echo "Production HA deployment completed."
echo "One-command A restore: ssh $A_HOST /usr/local/sbin/dx-ha-restore $A_BACKUP"
echo "One-command B+DNS restore: ssh $B_HOST /usr/local/sbin/dx-ha-restore $B_BACKUP --restore-dns"
