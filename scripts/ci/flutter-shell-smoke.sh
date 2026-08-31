#!/usr/bin/env bash
# Validate the Flutter DXEP shell without a Flutter SDK.
#
# 覆盖 roadmap H2：组件目录 v2、注册表、widget 文件、pubspec 资产声明、布局
# fixture 必须互相自洽；真正的 `flutter test` 需要 SDK（见 docs/lanes/L3-clients-pack.md）。
#
# Usage:
#   bash scripts/ci/flutter-shell-smoke.sh
#   FLUTTER_SMOKE_SKIP_ANALYZE=1 bash scripts/ci/flutter-shell-smoke.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
SHELL_DIR="$ROOT/clients/flutter_shell"
cd "$ROOT"

echo "== flutter-shell smoke =="

[[ -d "$SHELL_DIR" ]] || { echo "ERROR: $SHELL_DIR missing" >&2; exit 2; }

# --- 1. sources the pack ships ----------------------------------------------
for f in \
  pubspec.yaml \
  lib/main.dart \
  lib/app.dart \
  lib/config/shell_config.dart \
  lib/dxep/channel_client.dart \
  lib/dxep/envelope.dart \
  lib/dxep/field_contract.dart \
  lib/layout/app_layout.dart \
  lib/layout/component_catalog.dart \
  lib/layout/block_registry.dart \
  lib/layout/layout_engine.dart \
  lib/screens/shell_bootstrap.dart \
  lib/screens/shell_tabs.dart \
  assets/config/shell.example.json \
  assets/config/component_catalog.json \
  assets/fixtures/app_layout_gov.json \
  assets/fixtures/app_layout_v2.json \
  assets/fixtures/site.json \
  test/layout_engine_test.dart \
  test/component_catalog_test.dart \
  test/field_contract_test.dart
do
  [[ -f "$SHELL_DIR/$f" ]] || { echo "FAIL missing $SHELL_DIR/$f" >&2; exit 1; }
  echo "  ok   $f"
done

# --- 2. assets the shell loads must be declared in pubspec -------------------
python3 - "$SHELL_DIR" <<'PY'
import json, pathlib, re, sys

root = pathlib.Path(sys.argv[1])
pubspec = (root / "pubspec.yaml").read_text(encoding="utf-8")
declared = set(re.findall(r"^\s*-\s+(assets/[\w./-]*)\s*$", pubspec, re.M))
# A trailing "/" declares the whole folder.
dirs = {d for d in declared if d.endswith("/")}
files = {f for f in declared if not f.endswith("/")}
for folder in ("assets/config/", "assets/fixtures/"):
    if folder not in dirs:
        print(f"FAIL pubspec.yaml does not declare {folder}", file=sys.stderr)
        sys.exit(1)
print("  ok   pubspec declares assets/config/ + assets/fixtures/")

# Every rootBundle path in lib/ must resolve inside the package.
broken = []
for path in sorted((root / "lib").rglob("*.dart")):
    for match in re.finditer(r"rootBundle\.loadString\(\s*'([^']+)'", path.read_text(encoding="utf-8")):
        rel = match.group(1)
        if (root / rel).is_file() or any(rel.startswith(d) for d in dirs):
            continue
        broken.append(f"{path.relative_to(root)}: {rel}")
# The configurable fixture is not a literal in lib/, so check it separately.
for path in sorted((root / "assets").rglob("*.json")):
    rel = str(path.relative_to(root))
    if rel not in files and not any(rel.startswith(d) for d in dirs):
        broken.append(f"undeclared asset {rel}")
if broken:
    print("FAIL unresolved bundle paths:\n  " + "\n  ".join(broken), file=sys.stderr)
    sys.exit(1)
print("  ok   every bundle path resolves inside the package")

cfg = json.loads((root / "assets/config/shell.example.json").read_text(encoding="utf-8"))
fixture = cfg.get("layout_fixture", "assets/fixtures/app_layout_gov.json")
if not (root / fixture).is_file():
    print(f"FAIL shell.example.json layout_fixture={fixture} missing", file=sys.stderr)
    sys.exit(1)
print(f"  ok   shell.example.json layout_fixture -> {fixture}")

config_dart = (root / "lib/config/shell_config.dart").read_text(encoding="utf-8")
if "layoutFixture" not in config_dart or "layout_fixture" not in config_dart:
    print("FAIL shell_config.dart lost the layout_fixture wiring", file=sys.stderr)
    sys.exit(1)
client = (root / "lib/dxep/channel_client.dart").read_text(encoding="utf-8")
if "config.layoutFixture" not in client:
    print("FAIL channel_client.dart still hard-codes a layout fixture", file=sys.stderr)
    sys.exit(1)
print("  ok   layout_fixture is configurable end to end")
PY

# --- 3. catalogue-driven checks (replaces the old hard-coded 11-type set) ----
# catalog mode reads assets/config/component_catalog.json and cross-checks the
# Dart mirror, block_registry.dart, the widget files, dxep.js, index.wxml and
# every layout fixture, so a new component can never be half-wired again.
python3 tools/clients/isomorph_check.py catalog

# --- 4. static Dart analysis: balanced delimiters + resolvable imports -------
if [[ "${FLUTTER_SMOKE_SKIP_ANALYZE:-0}" != "1" ]]; then
  python3 tools/clients/isomorph_check.py dart
fi

if command -v flutter >/dev/null 2>&1; then
  # An SDK may exist on this machine, but `flutter pub get` resolves from
  # pub.dev and `flutter test` compiles with it - both need network + a build
  # window this lane is not allowed to open. So: report, never run.
  echo "  note flutter SDK found at $(command -v flutter) - NOT executed (network build forbidden);"
  echo "       authorized window: cd clients/flutter_shell && flutter pub get && flutter test"
else
  echo "  note no flutter SDK here - the Dart tests in test/ need a SDK window (see docs/flutter-shell.md)"
fi

echo "OK flutter-shell smoke"
