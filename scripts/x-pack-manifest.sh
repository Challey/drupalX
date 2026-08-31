#!/usr/bin/env bash
# X 项目核心工具：三端出包清单（manifest）schema 门禁（Phase H4，纯静态）
#
# Usage:
#   bash scripts/x-pack-manifest.sh --platforms
#   bash scripts/x-pack-manifest.sh --list
#   bash scripts/x-pack-manifest.sh --all                      # 三端全部清单
#   bash scripts/x-pack-manifest.sh --platform=android --app=car_hailing_assistant
#   bash scripts/x-pack-manifest.sh --schema --platform=android
#   bash scripts/x-pack-manifest.sh --resolve --platform=android --app=car_hailing_assistant
#   bash scripts/x-pack-manifest.sh --json --all               # CI 机读
#   bash scripts/x-pack-manifest.sh --no-yaml --all            # 用内置 YAML 子集解析器
#
# Exit codes: 0 全部通过 · 1 有清单不合规 · 2 用法错误
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if [[ $# -eq 0 ]]; then
  sed -n '2,18p' "$0" | sed 's/^# \{0,1\}//'
  exit 2
fi

for arg in "$@"; do
  case "$arg" in
    -h|--help) sed -n '2,18p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
  esac
done

exec python3 "$ROOT/tools/packer/validate_manifest.py" "$@"
