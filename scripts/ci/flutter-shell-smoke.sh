#!/usr/bin/env bash
# Validate Flutter shell fixtures + layout JSON without full Flutter SDK.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
SHELL_DIR="$ROOT/clients/flutter_shell"

echo "== flutter-shell smoke =="

test -f "$SHELL_DIR/pubspec.yaml"
test -f "$SHELL_DIR/lib/main.dart"
test -f "$SHELL_DIR/lib/layout/layout_engine.dart"
test -f "$SHELL_DIR/assets/fixtures/app_layout_gov.json"
test -f "$SHELL_DIR/assets/fixtures/site.json"

python3 - <<'PY'
import json, pathlib, sys
root = pathlib.Path("/home/wwwroot/drupalX/clients/flutter_shell")
known = {
  "hero_banner","notice_ticker","article_list","notice_list","product_grid",
  "service_grid","profile_header","rich_html","web_link","empty","error",
}
for name in ("app_layout_gov.json","app_layout_ent.json"):
  data = json.loads((root/"assets/fixtures"/name).read_text(encoding="utf-8"))
  pages = data.get("pages") or {}
  assert pages, name
  for page in pages.values():
    for block in page.get("blocks") or []:
      t = block.get("type")
      if t not in known:
        print(f"unknown type {t} in {name}", file=sys.stderr)
        sys.exit(1)
  nav = data.get("navigation",{}).get("items") or []
  assert nav, f"no nav in {name}"
site = json.loads((root/"assets/fixtures/site.json").read_text(encoding="utf-8"))
assert site.get("ok") is True
assert "org_profile" in (site.get("data") or {})
print("fixture layout catalog OK")
PY

# Required dart sources
for f in \
  lib/main.dart \
  lib/app.dart \
  lib/config/shell_config.dart \
  lib/dxep/channel_client.dart \
  lib/layout/app_layout.dart \
  lib/layout/block_registry.dart \
  lib/screens/shell_bootstrap.dart
do
  test -f "$SHELL_DIR/$f" || { echo "missing $f" >&2; exit 1; }
done

echo "OK"
