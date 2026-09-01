#!/usr/bin/env bash
# L3 Phase H1 offline regression: the generated payment whitelist must behave
# exactly like the hard-coded shell 1.2.x expression.
#
# Uses a *synthetic* manifest that declares none of the new fields, so it
# proves the DX-PACK-MANIFEST defaults (not the car-hailing manifest text)
# reproduce today's shipped behaviour.
#
# Needs a plain JDK (javac/java 17+); skips itself (exit 0) when absent so a
# JDK-less CI runner does not turn red on this case.
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/../../.." && pwd)"
TEMPLATE="$ROOT/tools/android-packer/template"
CODEGEN="$ROOT/tools/android-packer/lib/shell_codegen.py"
MANIFEST_TOOL="$ROOT/tools/packer/validate_manifest.py"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

if ! command -v javac >/dev/null 2>&1 || ! command -v java >/dev/null 2>&1; then
  echo "SKIP payment-host-policy: javac/java not on PATH (needs JDK 17+)"
  exit 0
fi

cat > "$WORK/minimal.manifest.yml" <<'YML'
app_id: whitelist_probe
label: Whitelist Probe
application_id: run.example.probe
start_url: https://www.topstar.run/driver
YML

python3 "$MANIFEST_TOOL" --resolve --platform=android --file="$WORK/minimal.manifest.yml" > "$WORK/config.json"

# Defaults must be what the manifest does not say.
python3 - "$WORK/config.json" <<'PY'
import json, sys
cfg = json.load(open(sys.argv[1]))
expected = [
    {"host": "wx.tenpay.com", "match": "exact"},
    {"host": "tenpay.com", "match": "child"},
    {"host": "pay.weixin.qq.com", "match": "domain"},
    {"host": "open.weixin.qq.com", "match": "exact"},
    {"host": "alipay.com", "match": "contains"},
    {"host": "alipayobjects.com", "match": "contains"},
]
assert cfg["payment_hosts"] == expected, cfg["payment_hosts"]
assert cfg["capabilities"] == ["location", "microphone", "photo_upload"], cfg["capabilities"]
assert cfg["shell_version"] == "1.3.0", cfg["shell_version"]
print("defaults : 6 payment rule(s), 3 capabilities, shell 1.3.0")
PY

CSV="$(python3 "$CODEGEN" payment-csv --config "$WORK/config.json")"
ALLOWED_HOST="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["allowed_host"])' "$WORK/config.json")"
echo "rules    : $CSV"

mkdir -p "$WORK/classes"
javac -Xlint:none -nowarn -d "$WORK/classes" \
  "$TEMPLATE/app/src/main/java/x/app/shell/PaymentHostPolicy.java" \
  "$HERE/LegacyWhitelistProbe.java"
java -cp "$WORK/classes" LegacyWhitelistProbe "$CSV" "$ALLOWED_HOST" "$HERE/whitelist-hosts.txt"
