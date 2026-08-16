#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
echo "== clients isomorphic layout smoke =="
test -f clients/flutter_shell/pubspec.yaml
test -f clients/wechat-miniprogram/app.json
# Shared block type tokens should appear in both codebases
for token in hero banner list content tabs; do
  FLUTTER_HIT="$(rg -l "$token" clients/flutter_shell --glob '!**/.*' 2>/dev/null | head -1 || true)"
  MP_HIT="$(rg -l "$token" clients/wechat-miniprogram --glob '!**/.*' 2>/dev/null | head -1 || true)"
  if [[ -z "$FLUTTER_HIT" || -z "$MP_HIT" ]]; then
    echo "WARN missing token=$token flutter=${FLUTTER_HIT:-none} mp=${MP_HIT:-none}"
  else
    echo "OK token=$token"
  fi
done
# At least fixtures exist
test -d clients/flutter_shell || test -f clients/flutter_shell/README.md
test -f clients/wechat-miniprogram/app.js
echo "OK clients present"
