#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
MP="$ROOT/clients/wechat-miniprogram"
echo "== miniprogram shell smoke =="
test -f "$MP/app.js"
test -f "$MP/utils/dxep.js"
test -f "$MP/fixtures/app_layout_gov.js"
python3 - <<'PY'
import pathlib, re, sys
root = pathlib.Path('/home/wwwroot/drupalX/clients/wechat-miniprogram')
text = (root/'utils/dxep.js').read_text(encoding='utf-8')
# crude extract known keys
keys = set(re.findall(r'(\w+):\s*true', text))
need = {'hero_banner','notice_ticker','article_list','product_grid','service_grid','profile_header'}
missing = need - keys
if missing:
  print('missing known types', missing, file=sys.stderr)
  sys.exit(1)
print('mp dxep known OK', sorted(keys))
PY
echo "OK"
