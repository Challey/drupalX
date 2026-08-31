#!/usr/bin/env bash
# Validate the DXEP mini-program shell without WeChat DevTools.
#
# 覆盖 roadmap H3 小程序侧：页面注册、require() 目标、fixture 注册、wxml 标签
# 配平、组件目录三端一致，全部纯静态。
#
# Usage:
#   bash scripts/ci/miniprogram-shell-smoke.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
MP="$ROOT/clients/wechat-miniprogram"
cd "$ROOT"

echo "== miniprogram shell smoke =="

[[ -d "$MP" ]] || { echo "ERROR: $MP missing" >&2; exit 2; }

# --- 1. files the pack ships -------------------------------------------------
for f in \
  app.js \
  app.json \
  project.config.json \
  config.js \
  utils/dxep.js \
  utils/field_contract.js \
  fixtures/site.js \
  fixtures/app_layout_gov.js \
  fixtures/app_layout_v2.js \
  fixtures/contents_list.js \
  fixtures/content_article.js \
  fixtures/content_product.js \
  pages/index/index.js \
  pages/index/index.wxml \
  pages/index/index.wxss
do
  [[ -f "$MP/$f" ]] || { echo "FAIL missing $MP/$f" >&2; exit 1; }
  echo "  ok   $f"
done

# --- 2. JSON surfaces must parse --------------------------------------------
python3 - "$MP" <<'PY'
import json, pathlib, sys
mp = pathlib.Path(sys.argv[1])
app = json.loads((mp / "app.json").read_text(encoding="utf-8"))
assert app.get("pages"), "app.json declares no pages"
cfg = json.loads((mp / "project.config.json").read_text(encoding="utf-8"))
assert cfg.get("appid"), "project.config.json lost its appid"
print(f"  ok   app.json pages={app['pages']} appid={cfg['appid']}")
PY

# --- 3. catalogue-driven renderer check (was a hard-coded 6-type set) --------
# catalog mode asserts: every component type in component_catalog.json is known
# to utils/dxep.js AND rendered by pages/index/index.wxml, and that the v1
# frozen types (already delivered in 1.2.x) can never disappear.
python3 tools/clients/isomorph_check.py catalog

# --- 4. mini-program static wiring: pages / require / fixtures / wxml --------
python3 tools/clients/isomorph_check.py mp

# --- 5. the L1/L2 fixtures must equal the Flutter end's data -----------------
python3 tools/clients/isomorph_check.py fixtures

echo "OK miniprogram shell smoke"
