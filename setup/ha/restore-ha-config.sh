#!/usr/bin/env bash
set -euo pipefail

PATH="/usr/local/bin:/usr/local/sbin:/usr/bin:/usr/sbin:/bin:/sbin:${PATH:-}"

if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
  echo "Run as root." >&2
  exit 1
fi

backup="${1:-}"
restore_dns="${2:-}"
if [[ -z "$backup" ]]; then
  echo "Usage: $0 <backup-directory|latest-node-a|latest-node-b> [--restore-dns]" >&2
  exit 2
fi
if [[ "$backup" != /* ]]; then
  backup="/var/backups/drupalx-ha/$backup"
fi
backup="$(readlink -f "$backup")"
[[ -d "$backup/payload" && -f "$backup/manifest.sha256" ]] || {
  echo "Invalid backup: $backup" >&2
  exit 1
}

(
  cd "$backup"
  sha256sum -c manifest.sha256
)

systemctl stop dx-dns-failover.timer 2>/dev/null || true

while IFS= read -r path; do
  [[ -n "$path" ]] || continue
  source_path="$backup/payload$path"
  [[ -e "$source_path" || -L "$source_path" ]] || {
    echo "Backup payload is missing $path" >&2
    exit 1
  }
  rm -rf "$path"
  mkdir -p "$(dirname "$path")"
  cp -a "$source_path" "$path"
done <"$backup/metadata/present.list"

while IFS= read -r path; do
  [[ -n "$path" ]] || continue
  rm -rf "$path"
done <"$backup/metadata/absent.list"

if [[ -f "$backup/metadata/root-crontab-present" ]]; then
  crontab "$backup/metadata/root.crontab"
else
  crontab -r 2>/dev/null || true
fi

systemctl daemon-reload
nginx -t
/usr/local/apache/bin/apachectl -t
/etc/init.d/httpd restart
/etc/init.d/nginx reload

timer_state="$backup/metadata/systemd-dx-dns-failover.timer"
if [[ -f "$timer_state" ]] && grep -qx 'enabled' "$timer_state"; then
  systemctl enable dx-dns-failover.timer
else
  systemctl disable dx-dns-failover.timer 2>/dev/null || true
fi
if [[ -f "$timer_state" ]] && grep -qx 'active' "$timer_state"; then
  systemctl start dx-dns-failover.timer
else
  systemctl stop dx-dns-failover.timer 2>/dev/null || true
fi

if [[ "$restore_dns" == "--restore-dns" && -s "$backup/metadata/dns-records.json" ]]; then
  aliyun_bin="$(command -v aliyun || true)"
  [[ -n "$aliyun_bin" ]] || {
    echo "Cannot restore DNS: aliyun CLI is unavailable." >&2
    exit 1
  }
  current_dns_json="$("$aliyun_bin" alidns DescribeDomainRecords \
    --DomainName drupal.org.cn \
    --Type A \
    --PageSize 100)"
  CURRENT_DNS_JSON="$current_dns_json" \
    python3 - "$backup/metadata/dns-records.json" <<'PY' |
import json
import os
import sys

with open(sys.argv[1], encoding="utf-8") as stream:
    backup = json.load(stream)
current = json.loads(os.environ["CURRENT_DNS_JSON"])
current_by_id = {
    str(record.get("RecordId")): record
    for record in current.get("DomainRecords", {}).get("Record", []) or []
}
for record in backup.get("DomainRecords", {}).get("Record", []) or []:
    if record.get("Type") == "A" and record.get("RR") in {"www", "@", "x"}:
        record_id = str(record.get("RecordId", ""))
        live = current_by_id.get(record_id, {})
        value = str(record.get("Value", ""))
        ttl = int(record.get("TTL", 600))
        if str(live.get("Value", "")) == value and int(live.get("TTL", 0)) == ttl:
            print(
                f'DNS already restored: {record.get("RR")}.drupal.org.cn'
                f" -> {value} (TTL {ttl})",
                file=sys.stderr,
            )
            continue
        print(
            record_id,
            record.get("RR", ""),
            value,
            ttl,
            sep="\t",
        )
PY
  while IFS=$'\t' read -r record_id rr value ttl; do
    [[ -n "$record_id" && -n "$rr" && -n "$value" ]] || continue
    "$aliyun_bin" alidns UpdateDomainRecord \
      --RecordId "$record_id" \
      --RR "$rr" \
      --Type A \
      --Value "$value" \
      --TTL "$ttl"
  done
fi

echo "Restored DrupalX HA configuration from $backup"
