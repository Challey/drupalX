#!/usr/bin/env bash
# X 项目核心工具：三端同构冒烟（Web / Flutter / 小程序）
#
# 覆盖 roadmap H3：L1/L2 数据字段清单 + 组件目录必须在三端一致，缺项要能
# 定位到具体端与文件。纯静态：无 Flutter SDK、无微信开发者工具、无数据库、无网络。
#
# Usage:
#   bash scripts/ci/clients-isomorph-smoke.sh          # 全量
#   bash scripts/ci/clients-isomorph-smoke.sh -v       # 逐项 ok 输出
#   ISOMORPH_MODE=fields bash scripts/ci/clients-isomorph-smoke.sh   # 单模式排障
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

VERBOSE=""
[[ "${1:-}" == "-v" || "${1:-}" == "--verbose" ]] && VERBOSE="-v"
MODE="${ISOMORPH_MODE:-all}"

echo "== clients isomorphic smoke =="

for tool in python3; do
  command -v "$tool" >/dev/null 2>&1 || { echo "ERROR: $tool required" >&2; exit 2; }
done

# --- inputs the gate reads (fail early with a file name, not a traceback) ----
for f in \
  clients/field-contract.json \
  clients/flutter_shell/assets/config/component_catalog.json \
  clients/flutter_shell/lib/dxep/field_contract.dart \
  clients/wechat-miniprogram/utils/field_contract.js \
  tools/clients/isomorph_check.py \
  tools/clients/sync_fixtures.py \
  tools/packer/manifest-schema.json
do
  [[ -f "$f" ]] || { echo "FAIL missing $f" >&2; exit 2; }
done

# --- 1. three-end contract + catalogue + fixtures + static source parse ------
# catalog    : 18 component types (v1 frozen 12 + v2 additions) -> Dart mirror,
#              block_registry, widget files, dxep.js, index.wxml, fixtures
# fields     : every field of clients/field-contract.json must be anchored on
#              each end that requires it -> prints file:line of the anchor
# fixtures   : flutter JSON == mini program JS, both satisfy the contract
# dart       : balanced delimiters + resolvable imports for every .dart file
# mp         : app.json pages / require() / fixture registry / wxml tag balance
python3 tools/clients/isomorph_check.py "$MODE" $VERBOSE

# --- 2. mirrors must be current (hand-edited mirror == FAIL) -----------------
python3 tools/clients/isomorph_check.py mirror | sed 's/^/  /'

# --- 3. fixture copies must not drift apart ----------------------------------
# (the mini program cannot read the Flutter asset bundle, so every L1/L2
#  fixture exists twice; `fixtures` mode compares the data, this compares bytes)
python3 tools/clients/sync_fixtures.py --check | sed 's/^/  /'

echo "OK clients isomorphic smoke"
