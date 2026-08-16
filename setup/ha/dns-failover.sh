#!/usr/bin/env bash
# Run on B. Move all DrupalX A records to B when A fails, and restore A after
# it has recovered. This controls public entry failover; Nginx upstream on A
# separately controls B-first business processing.
set -uo pipefail

ENV_FILE="${DX_DNS_FAILOVER_ENV:-/root/dx-dns-failover.env}"
if [[ ! -r "$ENV_FILE" ]]; then
  echo "Unreadable environment file: $ENV_FILE" >&2
  exit 1
fi
# Administrator-owned deployment configuration.
# shellcheck disable=SC1090
source "$ENV_FILE"

: "${A_HEALTH_URL:?missing A_HEALTH_URL}"
: "${A_PUBLIC_IP:?missing A_PUBLIC_IP}"
: "${B_PUBLIC_IP:?missing B_PUBLIC_IP}"
: "${HEALTH_HOST:?missing HEALTH_HOST}"
: "${DOMAIN_NAME:?missing DOMAIN_NAME}"
: "${RR_LIST:?missing RR_LIST}"

TYPE="${TYPE:-A}"
TTL="${TTL:-60}"
FAIL_THRESHOLD="${FAIL_THRESHOLD:-3}"
RECOVERY_THRESHOLD="${RECOVERY_THRESHOLD:-3}"
CONNECT_TIMEOUT="${CONNECT_TIMEOUT:-3}"
MAX_TIME="${MAX_TIME:-5}"
STATUS_FILE="${STATUS_FILE:-/var/lib/dx-ha/dns-state}"
LOG_FILE="${LOG_FILE:-/var/log/dx-dns-failover.log}"
LOCK_FILE="${LOCK_FILE:-/run/lock/dx-dns-failover.lock}"
RECORD_IDS="${RECORD_IDS:-}"

for command in aliyun curl flock python3; do
  command -v "$command" >/dev/null 2>&1 || {
    echo "Required command is unavailable: $command" >&2
    exit 1
  }
done
for name in TTL FAIL_THRESHOLD RECOVERY_THRESHOLD CONNECT_TIMEOUT MAX_TIME; do
  [[ "${!name}" =~ ^[0-9]+$ ]] || {
    echo "$name must be a non-negative integer." >&2
    exit 1
  }
done
[[ "$FAIL_THRESHOLD" -gt 0 && "$RECOVERY_THRESHOLD" -gt 0 ]] || {
  echo "Fail and recovery thresholds must be greater than zero." >&2
  exit 1
}
[[ "$A_PUBLIC_IP" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]] || {
  echo "A_PUBLIC_IP must be an IPv4 address." >&2
  exit 1
}
[[ "$B_PUBLIC_IP" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]] || {
  echo "B_PUBLIC_IP must be an IPv4 address." >&2
  exit 1
}

mkdir -p "$(dirname "$STATUS_FILE")" "$(dirname "$LOG_FILE")" "$(dirname "$LOCK_FILE")"
exec 9>"$LOCK_FILE"
flock -n 9 || exit 0

timestamp() { date '+%F %T'; }
log() { echo "$(timestamp) $*" | tee -a "$LOG_FILE" >&2; }

resolve_record_id() {
  local rr="$1" index="$2" id=""
  if [[ -n "$RECORD_IDS" ]]; then
    id=$(awk -v field="$index" '{print $field}' <<<"$RECORD_IDS")
  fi
  if [[ -n "$id" ]]; then
    printf '%s\n' "$id"
    return 0
  fi

  aliyun alidns DescribeDomainRecords \
    --DomainName "$DOMAIN_NAME" \
    --RRKeyWord "$rr" \
    --Type "$TYPE" \
    --PageSize 100 2>>"$LOG_FILE" |
    python3 -c '
import json
import sys

rr, record_type = sys.argv[1:3]
data = json.load(sys.stdin)
for record in data.get("DomainRecords", {}).get("Record", []) or []:
    if record.get("RR") == rr and record.get("Type") == record_type:
        print(record.get("RecordId", ""))
        raise SystemExit(0)
raise SystemExit(2)
' "$rr" "$TYPE" 2>>"$LOG_FILE"
}

update_records() {
  local target_ip="$1" rr id index=1 failed=0
  for rr in $RR_LIST; do
    id=$(resolve_record_id "$rr" "$index" || true)
    index=$((index + 1))
    if [[ -z "$id" ]]; then
      log "[ERROR] no record ID for ${rr}.${DOMAIN_NAME}"
      failed=1
      continue
    fi
    if aliyun alidns UpdateDomainRecord \
      --RecordId "$id" \
      --RR "$rr" \
      --Type "$TYPE" \
      --Value "$target_ip" \
      --TTL "$TTL" >>"$LOG_FILE" 2>&1; then
      log "[OK] ${rr}.${DOMAIN_NAME} -> $target_ip"
    else
      log "[ERROR] failed to update ${rr}.${DOMAIN_NAME}"
      failed=1
    fi
  done
  return "$failed"
}

mode=unknown
fail_count=0
recovery_count=0
if [[ -r "$STATUS_FILE" ]]; then
  read -r mode fail_count recovery_count <"$STATUS_FILE" || true
fi
[[ "$mode" == "a" || "$mode" == "b" || "$mode" == "unknown" ]] || mode=unknown
[[ "$fail_count" =~ ^[0-9]+$ ]] || fail_count=0
[[ "$recovery_count" =~ ^[0-9]+$ ]] || recovery_count=0

http_code=$(curl --silent --output /dev/null --write-out '%{http_code}' \
  --header "Host: $HEALTH_HOST" \
  --connect-timeout "$CONNECT_TIMEOUT" \
  --max-time "$MAX_TIME" \
  "$A_HEALTH_URL" 2>/dev/null || true)
http_code="${http_code:-000}"

if [[ "$http_code" == "200" ]]; then
  fail_count=0
  if [[ "$mode" != "a" ]]; then
    recovery_count=$((recovery_count + 1))
    if [[ "$recovery_count" -ge "$RECOVERY_THRESHOLD" ]]; then
      log "[RECOVERY] A passed $recovery_count checks; restoring public entry to A"
      if update_records "$A_PUBLIC_IP"; then
        mode=a
        recovery_count=0
      else
        log "[ERROR] A recovered, but DNS restoration was incomplete"
      fi
    fi
  else
    recovery_count=0
  fi
else
  recovery_count=0
  if [[ "$mode" != "b" ]]; then
    fail_count=$((fail_count + 1))
    log "[WARN] A health check returned $http_code ($fail_count/$FAIL_THRESHOLD)"
    if [[ "$fail_count" -ge "$FAIL_THRESHOLD" ]]; then
      log "[CRITICAL] A failed $fail_count checks; moving public entry to B"
      if update_records "$B_PUBLIC_IP"; then
        mode=b
        fail_count=0
      else
        log "[ERROR] DNS takeover by B was incomplete"
      fi
    fi
  else
    fail_count=0
  fi
fi

temporary_state="${STATUS_FILE}.tmp.$$"
printf '%s %s %s\n' "$mode" "$fail_count" "$recovery_count" >"$temporary_state"
mv -f "$temporary_state" "$STATUS_FILE"
