#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

echo "== packer pipeline smoke =="
test -f scripts/pack-tenant-channels.sh
test -f scripts/x-pack-flutter.sh
test -f scripts/x-pack-miniprogram-portal.sh
test -d clients/flutter_shell
test -d clients/wechat-miniprogram
# Help / usage exits non-zero without args — expect usage text
set +e
OUT="$(bash scripts/pack-tenant-channels.sh 2>&1)"
set -e
echo "$OUT" | grep -q 'Usage:'
echo "$OUT" | grep -q 'api-base'
# Fixture / layout files present
test -f clients/flutter_shell/pubspec.yaml
test -f clients/wechat-miniprogram/app.json
echo "OK packer scripts + client fixtures present"
