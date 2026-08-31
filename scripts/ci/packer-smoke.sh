#!/usr/bin/env bash
# X 项目核心工具：出包流水线冒烟（三端清单 schema + 壳生成器 + 出包演练）
#
# 覆盖 roadmap H1 / H4：
#   * DX-PACK-MANIFEST 三端统一 schema 必须校验通过，且 `--list` 与三个打包脚本一致
#   * Android 壳 1.3.0 离线回归（支付白名单等价、能力裁剪、权限说明覆盖、无残留 token）
#   * 三端各做一次真实出包演练，产物只写临时目录，绝不落仓库 / 生产 staging
#
# 纯静态 + 临时目录：不跑 drush、不碰数据库、不联网、不调用 gradle / flutter。
#
# Usage:
#   bash scripts/ci/packer-smoke.sh
#   PACKER_SMOKE_SKIP_PACK=1 bash scripts/ci/packer-smoke.sh    # 只跑门禁，不出包
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

MANIFEST_TOOL="tools/packer/validate_manifest.py"
GATE="bash scripts/x-pack-manifest.sh"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

echo "== packer pipeline smoke =="

command -v python3 >/dev/null 2>&1 || { echo "ERROR: python3 required" >&2; exit 2; }

# --- 1. every entry point must exist ----------------------------------------
for f in \
  scripts/pack-tenant-channels.sh \
  scripts/x-pack-android.sh \
  scripts/x-pack-flutter.sh \
  scripts/x-pack-miniprogram.sh \
  scripts/x-pack-miniprogram-portal.sh \
  scripts/x-pack-manifest.sh \
  "$MANIFEST_TOOL" \
  tools/packer/manifest-schema.json \
  tools/android-packer/lib/shell_codegen.py \
  clients/flutter_shell/pubspec.yaml \
  clients/wechat-miniprogram/app.json
do
  [[ -e "$f" ]] || { echo "FAIL missing $f" >&2; exit 1; }
done
echo "  ok   packer entry points present"

# --- 2. usage text without arguments (never a silent no-op) ------------------
set +e
OUT="$(bash scripts/pack-tenant-channels.sh 2>&1)"; RC1=$?
OUT2="$(bash scripts/x-pack-manifest.sh 2>&1)"; RC2=$?
set -e
[[ $RC1 -ne 0 ]] || { echo "FAIL pack-tenant-channels.sh must exit non-zero without args" >&2; exit 1; }
printf '%s\n' "$OUT" | grep -q 'Usage:' || { echo "FAIL pack-tenant-channels.sh usage missing" >&2; exit 1; }
printf '%s\n' "$OUT" | grep -q 'api-base' || { echo "FAIL pack-tenant-channels.sh usage lacks --api-base" >&2; exit 1; }
[[ $RC2 -ne 0 ]] || { echo "FAIL x-pack-manifest.sh must exit non-zero without args" >&2; exit 1; }
printf '%s\n' "$OUT2" | grep -q 'Usage:' || { echo "FAIL x-pack-manifest.sh usage missing" >&2; exit 1; }
echo "  ok   usage text + non-zero exit without arguments"

# --- 3. unknown flags must never produce a deliverable -----------------------
# (a real incident: `x-pack-miniprogram-portal.sh --list` treated --list as the
#  api_base and packed a bundle; every packer now rejects unknown arguments)
for spec in \
  "scripts/x-pack-android.sh --nonsense" \
  "scripts/x-pack-flutter.sh --nonsense" \
  "scripts/x-pack-miniprogram.sh --nonsense" \
  "scripts/x-pack-miniprogram-portal.sh --nonsense"
do
  set +e
  bash $spec >/dev/null 2>&1; rc=$?
  set -e
  [[ $rc -eq 1 ]] || { echo "FAIL '$spec' exited $rc, expected 1" >&2; exit 1; }
done
echo "  ok   unknown arguments are rejected by all four packers"

# --- 4. H4 gate: one schema, three platforms --------------------------------
$GATE --platforms | sort > "$WORK/platforms.txt"
[[ "$(wc -l < "$WORK/platforms.txt")" -eq 3 ]] \
  || { echo "FAIL expected 3 platforms, got: $(tr '\n' ' ' < "$WORK/platforms.txt")" >&2; exit 1; }
$GATE --all
$GATE --list --json > "$WORK/registry.json"
python3 - "$WORK/registry.json" <<'PY'
import json, sys
registry = json.load(open(sys.argv[1], encoding="utf-8"))
assert set(registry) == {"android", "flutter", "miniprogram"}, registry
assert all(registry[p] for p in registry), f"an end has no registered app: {registry}"
print(f"  ok   registry = {registry}")
PY
$GATE --schema --platform=android >/dev/null
$GATE --schema --json | python3 -c '
import json, sys
rows = json.load(sys.stdin)["fields"]
common = {r["field"] for r in rows if r["scope"] == "common"}
want = {"app_id", "label", "brand_name", "project_name"}
missing = want - common
assert not missing, f"common contract fields missing from the schema: {missing}"
print(f"  ok   schema carries {len(rows)} field rows, common={sorted(common)}")
'

# --- 5. each packer's --list must agree with the gate (docs promise this) ----
for pair in "android scripts/x-pack-android.sh" "flutter scripts/x-pack-flutter.sh" "miniprogram scripts/x-pack-miniprogram.sh"; do
  platform=${pair%% *}
  script=${pair##* }
  python3 "$MANIFEST_TOOL" --list --platform="$platform" --names-only | sort > "$WORK/gate.txt"
  bash "$script" --list | tail -n +2 | sort > "$WORK/script.txt"
  diff -u "$WORK/gate.txt" "$WORK/script.txt" > "$WORK/diff.txt" || {
    echo "FAIL $script --list disagrees with the schema registry:" >&2
    sed 's/^/     /' "$WORK/diff.txt" >&2
    exit 1
  }
  echo "  ok   $script --list == gate registry ($platform)"
done

# --- 5b. H2 wiring: a Flutter pack must ship the catalogue it declares --------
CATALOG="clients/flutter_shell/assets/config/component_catalog.json"
for app in $(python3 "$MANIFEST_TOOL" --list --platform=flutter --names-only); do
  python3 "$MANIFEST_TOOL" --resolve --platform=flutter --app="$app" > "$WORK/flutter-$app.json"
  python3 - "$WORK/flutter-$app.json" "$app" "$CATALOG" <<'PY'
import json
import os
import sys

config = json.load(open(sys.argv[1], encoding="utf-8"))
catalog = json.load(open(sys.argv[3], encoding="utf-8"))
declared = int(config["catalog_version"])
if declared != catalog["schema_version"]:
    sys.exit(f"FAIL flutter app '{sys.argv[2]}': manifest catalog_version={declared} but "
             f"{catalog['spec']} schema_version={catalog['schema_version']} - bump the "
             f"manifest (or the default in tools/packer/manifest-schema.json) together "
             f"with the catalogue")
for component in catalog["components"]:
    path = os.path.join("clients/flutter_shell", component["file"])
    if not os.path.isfile(path):
        sys.exit(f"FAIL catalogue entry '{component['type']}' points at a missing file: {path}")
print(f"  ok   flutter app '{sys.argv[2]}' ships catalogue v{declared} "
      f"({len(catalog['components'])} components, widget files present)")
PY
done

# --- 6. H1 offline regression suites ----------------------------------------
python3 tools/android-packer/tests/test_shell_pack.py
bash tools/android-packer/tests/test_payment_host_policy.sh

if [[ "${PACKER_SMOKE_SKIP_PACK:-0}" == "1" ]]; then
  echo "OK packer pipeline smoke (pack rehearsal skipped)"
  exit 0
fi

# --- 7. real pack rehearsal, temp dirs only ---------------------------------
ANDROID_DEST="$WORK/stage/android/car_hailing_assistant-android-deploy-latest"
bash scripts/x-pack-android.sh \
  --app=car_hailing_assistant \
  --out="$WORK/stage/android" \
  --mirror-dir="$WORK/mirror/android" \
  --payment-host=pay.partner.example:domain \
  > "$WORK/android.log" 2>&1 || { cat "$WORK/android.log" >&2; exit 1; }
JAVA="$ANDROID_DEST/app/src/main/java/x/app/shell/MainActivity.java"
POLICY="$ANDROID_DEST/app/src/main/java/x/app/shell/PaymentHostPolicy.java"
STRINGS="$ANDROID_DEST/app/src/main/res/values/strings.xml"
A_MANIFEST="$ANDROID_DEST/app/src/main/AndroidManifest.xml"
for f in "$JAVA" "$POLICY" "$STRINGS" "$A_MANIFEST" "$ANDROID_DEST/x-app.json" "$ANDROID_DEST/FILE-LIST.txt"; do
  [[ -f $f ]] || { echo "FAIL android pack missing $(basename "$f")" >&2; exit 1; }
done
grep -q 'PaymentHostPolicy\.' "$JAVA" || { echo "FAIL MainActivity does not delegate to PaymentHostPolicy" >&2; exit 1; }
grep -q 'pay.partner.example' "$JAVA" "$ANDROID_DEST/x-app.json" \
  || { echo "FAIL the injected --payment-host never reached the generated project" >&2; exit 1; }
grep -q 'wx.tenpay.com' "$JAVA" || { echo "FAIL the 1.2.x default whitelist disappeared" >&2; exit 1; }
# The rule interpreter is copied verbatim - only MainActivity's data table is
# generated, so a per-app pack can never fork the matcher semantics.
cmp -s "$POLICY" "$ROOT/tools/android-packer/template/app/src/main/java/x/app/shell/PaymentHostPolicy.java" \
  || { echo "FAIL PaymentHostPolicy.java differs from the template" >&2; exit 1; }
for perm in ACCESS_FINE_LOCATION ACCESS_COARSE_LOCATION RECORD_AUDIO READ_MEDIA_IMAGES; do
  grep -q "android.permission.$perm" "$A_MANIFEST" \
    || { echo "FAIL $perm disappeared from AndroidManifest.xml (1.2.x behaviour)" >&2; exit 1; }
done
for key in location_rationale mic_rationale photo_rationale payment_scope_summary; do
  grep -q "name=\"$key\"" "$STRINGS" \
    || { echo "FAIL strings.xml lost $key" >&2; exit 1; }
done
grep -Eq 'versionName "1\.4\.4"' "$ANDROID_DEST/app/build.gradle" \
  || { echo "FAIL version_name did not reach app/build.gradle" >&2; exit 1; }
grep -Eq 'versionCode 19' "$ANDROID_DEST/app/build.gradle" \
  || { echo "FAIL version_code did not reach app/build.gradle" >&2; exit 1; }
grep -q 'SHELL_VERSION", "\\"1.3.0\\""' "$ANDROID_DEST/app/build.gradle" \
  || { echo "FAIL shell_version 1.3.0 did not reach BuildConfig" >&2; exit 1; }
python3 - "$ANDROID_DEST/x-app.json" <<'PY'
import json, pathlib, sys
snapshot = json.load(open(sys.argv[1], encoding="utf-8"))
assert snapshot["shell_version"] == "1.3.0", snapshot["shell_version"]
assert snapshot["capabilities"] == ["location", "microphone", "photo_upload"], snapshot
hosts = [(r["host"], r["match"]) for r in snapshot["payment_hosts"]]
assert ("wx.tenpay.com", "exact") in hosts and ("tenpay.com", "child") in hosts, hosts
assert ("pay.partner.example", "domain") in hosts, "the appended rule is not in the snapshot"
# Legacy keys the ops scripts still read must survive the 1.3.0 snapshot.
for key in ("app_id", "application_id", "start_url", "allowed_host", "version_code", "version_name"):
    assert key in snapshot, f"snapshot lost legacy key {key}"
print(f"  ok   x-app.json snapshot: {len(hosts)} payment rules, caps={snapshot['capabilities']}")
PY
if command -v javac >/dev/null 2>&1; then
  # Only the pure-JDK class is compiled here: MainActivity needs the Android
  # SDK (android.* / androidx.*), which this lane may not download.
  javac -d "$WORK/javac-out" "$POLICY" || { echo "FAIL generated PaymentHostPolicy.java does not compile" >&2; exit 1; }
  python3 - "$JAVA" "$WORK/rules.txt" <<'PY'
import pathlib, re, sys
# Every generated PAYMENT_HOSTS row must be a well-formed {"host", "mode"} pair,
# i.e. the codegen can never emit a half-substituted placeholder.
text = pathlib.Path(sys.argv[1]).read_text(encoding="utf-8")
block = re.search(r"PAYMENT_HOSTS\s*=\s*new String\[\]\[\]\s*\{(.*?)\n\s*\};", text, re.S)
assert block, "PAYMENT_HOSTS table not found in the generated MainActivity"
rows = re.findall(r'\{\s*"([^"]+)"\s*,\s*"([^"]+)"\s*\}', block.group(1))
assert rows, "PAYMENT_HOSTS is empty - the whitelist can never be configured"
MODES = {"exact", "domain", "child", "contains"}
bad = [r for r in rows if not re.fullmatch(r"[a-z0-9][a-z0-9.\-]*", r[0]) or r[1] not in MODES]
assert not bad, f"malformed whitelist rows: {bad}"
assert not any("{" in h or "}" in h for h, _ in rows), "unexpanded template marker reached the pack"
with open(sys.argv[2], "w", encoding="utf-8") as fh:
    for host, mode in rows:
        fh.write(f"{host}={mode}\n")
print(f"  ok   generated MainActivity whitelist is well formed ({len(rows)} rules)")
PY
  grep -q 'pay.partner.example=domain' "$WORK/rules.txt" \
    || { echo "FAIL the injected --payment-host is missing from PAYMENT_HOSTS" >&2; exit 1; }
  echo "  ok   generated PaymentHostPolicy.java compiles with a plain JDK"
else
  echo "  note no javac here - the generated policy class was not compiled"
fi
echo "  ok   android rehearsal project generated (APK build still needs SDK - see docs/android-pack.md)"

FLUTTER_DEST_LATEST="$WORK/stage/flutter/demo-flutter-deploy-latest"
bash scripts/x-pack-flutter.sh \
  --app=demo \
  --api-base=https://demo.example.com \
  --token=dxc_smoke_not_a_real_token \
  --out="$WORK/stage/flutter" \
  --mirror-dir="$WORK/mirror/flutter" \
  --layout-fixture=assets/fixtures/app_layout_v2.json \
  > "$WORK/flutter.log" 2>&1 || { cat "$WORK/flutter.log" >&2; exit 1; }
SHELL_JSON="$FLUTTER_DEST_LATEST/assets/config/shell.json"
[[ -f $SHELL_JSON ]] || { echo "FAIL flutter pack did not inject assets/config/shell.json" >&2; exit 1; }
python3 - "$SHELL_JSON" "$FLUTTER_DEST_LATEST" <<'PY'
import json, pathlib, sys
cfg = json.load(open(sys.argv[1], encoding="utf-8"))
dest = pathlib.Path(sys.argv[2])
assert cfg["api_base"] == "https://demo.example.com", cfg
assert cfg["layout_fixture"] == "assets/fixtures/app_layout_v2.json", cfg
assert (dest / cfg["layout_fixture"]).is_file(), "injected fixture is not bundled"
assert (dest / "assets/config/component_catalog.json").is_file(), "catalogue missing from the pack"
assert (dest / "test/field_contract_test.dart").is_file(), "contract test missing from the pack"
assert (dest / "FILE-LIST.txt").is_file(), "FILE-LIST.txt missing from the pack"
assert cfg["use_fixtures"] is False, "a packed shell must not default to fixtures"
print("  ok   flutter pack injects shell.json + ships catalogue, fixtures and tests")
PY

MP_DEST="$WORK/mirror/mp/drupalx_portal-mp-deploy-latest"
mkdir -p "$WORK/stage/mp"
bash scripts/x-pack-miniprogram.sh \
  --app=drupalx_portal \
  --api-base=https://demo.example.com \
  --out="$WORK/stage/mp" \
  --mirror-dir="$WORK/mirror/mp" \
  > "$WORK/mp.log" 2>&1 || { cat "$WORK/mp.log" >&2; exit 1; }
[[ -d $MP_DEST ]] || MP_DEST="$WORK/stage/mp/drupalx_portal-mp-deploy-latest"
python3 - "$MP_DEST" <<'PY'
import json, pathlib, sys
dest = pathlib.Path(sys.argv[1])
for f in ("app.js", "app.json", "utils/dxep.js", "utils/field_contract.js", "FILE-LIST.txt"):
    assert (dest / f).is_file(), f"mp pack missing {f}"
listing = (dest / "FILE-LIST.txt").read_text(encoding="utf-8").splitlines()
assert "utils/field_contract.js" in listing, "FILE-LIST.txt does not list the contract mirror"
assert "FILE-LIST.txt" not in listing, "FILE-LIST.txt must not list itself"
app = json.loads((dest / "app.json").read_text(encoding="utf-8"))
for page in app["pages"]:
    assert (dest / f"{page}.js").is_file(), f"page {page} not packed"
config = (dest / "config.js").read_text(encoding="utf-8")
assert "https://demo.example.com" in config, "apiBase was not injected into config.js"
print(f"  ok   miniprogram pack renders pages={app['pages']} with injected apiBase")
PY

# --- 8. no rehearsal artifact may land in the repo or the live staging -------
if [[ -e "$ROOT/upgrade/android/car_hailing_assistant-android-deploy-latest" \
   || -e "$ROOT/upgrade/flutter/demo-flutter-deploy-latest" \
   || -e "$ROOT/upgrade/miniprogram/drupalx_portal-mp-deploy-latest" ]]; then
  echo "FAIL the rehearsal wrote into $ROOT/upgrade - override was ignored" >&2
  exit 1
fi
echo "  ok   rehearsal wrote only into $WORK (repo + live staging untouched)"

echo "OK packer pipeline smoke"
