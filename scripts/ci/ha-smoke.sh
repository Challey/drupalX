#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
HA_DIR="$ROOT/setup/ha"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

grep -Fq 'server 172.16.34.126:88 max_fails=2 fail_timeout=10s;' \
  "$HA_DIR/lnmpa/node-a-balance-servers.conf"
grep -Fq 'server 127.0.0.1:88 backup;' \
  "$HA_DIR/lnmpa/node-a-balance-servers.conf"
if grep -Eq 'weight=' "$HA_DIR/lnmpa/node-a-balance-servers.conf"; then
  echo "A weighted backend would violate strict B-first processing." >&2
  exit 1
fi
grep -Fq 'proxy_pass http://dxBusinessApache;' \
  "$HA_DIR/lnmpa/node-a-proxy-pass-php.conf"
grep -Fq 'proxy_pass http://127.0.0.1:88;' \
  "$HA_DIR/lnmpa/node-b-proxy-pass-php.conf"

mkdir -p "$TMP/bin" "$TMP/state" "$TMP/log" "$TMP/lock"
cat >"$TMP/bin/curl" <<'MOCK'
#!/usr/bin/env bash
printf '%s' "${MOCK_HEALTH_CODE:?}"
MOCK
cat >"$TMP/bin/aliyun" <<'MOCK'
#!/usr/bin/env bash
printf '%s\n' "$*" >>"${MOCK_CALL_LOG:?}"
MOCK
chmod +x "$TMP/bin/curl" "$TMP/bin/aliyun"

cat >"$TMP/failover.env" <<EOF
A_HEALTH_URL=http://172.16.34.121/healthz
A_PUBLIC_IP=198.51.100.11
B_PUBLIC_IP=198.51.100.12
HEALTH_HOST=www.drupal.org.cn
DOMAIN_NAME=drupal.org.cn
RR_LIST="www @ x"
TYPE=A
TTL=60
FAIL_THRESHOLD=3
RECOVERY_THRESHOLD=3
CONNECT_TIMEOUT=1
MAX_TIME=1
STATUS_FILE=$TMP/state/dns-state
LOG_FILE=$TMP/log/failover.log
LOCK_FILE=$TMP/lock/failover.lock
RECORD_IDS="record-www record-apex record-x"
EOF

export PATH="$TMP/bin:$PATH"
export MOCK_CALL_LOG="$TMP/aliyun.calls"
export DX_DNS_FAILOVER_ENV="$TMP/failover.env"

export MOCK_HEALTH_CODE=000
for _ in 1 2 3; do
  "$HA_DIR/dns-failover.sh" >/dev/null
done
read -r mode _ <"$TMP/state/dns-state"
[[ "$mode" == "b" ]]
[[ "$(grep -c -- '--Value 198.51.100.12' "$MOCK_CALL_LOG")" -eq 3 ]]

export MOCK_HEALTH_CODE=200
for _ in 1 2 3; do
  "$HA_DIR/dns-failover.sh" >/dev/null
done
read -r mode _ <"$TMP/state/dns-state"
[[ "$mode" == "a" ]]
[[ "$(grep -c -- '--Value 198.51.100.11' "$MOCK_CALL_LOG")" -eq 3 ]]

echo "HA smoke checks passed."
