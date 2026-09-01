#!/usr/bin/env python3
"""Android WebView shell codegen (L3 Phase H1, shell 1.3.0).

Turns a resolved DX-PACK-MANIFEST config (see tools/packer/manifest_lib.py)
into the wiring inside the delivered template:

  * token replacement (project / applicationId / urls / versions)
  * payment host + scheme whitelist -> MainActivity.java + AndroidManifest.xml
    + strings.xml (privacy / store disclosure text)
  * capability switches -> AndroidManifest.xml <uses-permission> block,
    build.gradle BuildConfig flags
  * permission rationale copy overrides -> strings.xml
  * x-app.json pack snapshot

Every default equals the shipped 1.2.x behaviour, so packing a manifest that
declares nothing new produces the same whitelist and the same permissions.

CLI:
  python3 tools/android-packer/lib/shell_codegen.py apply \
      --dest <staged project> --config <resolved.json> [--stamp 20260830_071500]
  python3 tools/android-packer/lib/shell_codegen.py tokens --config <resolved.json>
"""

from __future__ import annotations

import argparse
import json
import os
import sys

SKIP_EXT = {".png", ".jpg", ".jpeg", ".webp", ".jar", ".dex", ".ttf", ".so"}

# Capability -> (manifest permission text, BuildConfig flag)
PERMISSION_GROUPS = {
    "location": [
        '    <uses-permission android:name="android.permission.ACCESS_COARSE_LOCATION" />',
        '    <uses-permission android:name="android.permission.ACCESS_FINE_LOCATION" />',
    ],
    "microphone": [
        '    <uses-permission android:name="android.permission.RECORD_AUDIO" />',
        '    <uses-permission android:name="android.permission.MODIFY_AUDIO_SETTINGS" />',
    ],
    "photo_upload": [
        '    <!-- 选图 / 现场热点 / 问题反馈：相册读取（Android 13+ / 旧版） -->',
        '    <uses-permission android:name="android.permission.READ_MEDIA_IMAGES" />',
        '    <uses-permission\n'
        '        android:name="android.permission.READ_EXTERNAL_STORAGE"\n'
        '        android:maxSdkVersion="32" />',
    ],
}

RATIONALE_KEYS = {
    "location": ("location_title", "location_rationale"),
    "microphone": ("mic_title", "mic_rationale"),
    "photo": ("photo_title", "photo_rationale"),
}

CAP_FLAGS = {
    "location": "__CAP_LOCATION__",
    "microphone": "__CAP_MICROPHONE__",
    "photo_upload": "__CAP_PHOTO_UPLOAD__",
}


def xml_escape(text: str) -> str:
    return (
        str(text)
        .replace("&", "&amp;")
        .replace("<", "&lt;")
        .replace(">", "&gt;")
        .replace('"', "&quot;")
    )


def java_string(value: str) -> str:
    escaped = (
        str(value)
        .replace("\\", "\\\\")
        .replace('"', '\\"')
        .replace("\n", "\\n")
        .replace("\r", "")
    )
    return '"%s"' % escaped


def java_host_table(rules) -> str:
    rows = []
    for rule in rules or []:
        rows.append('{"%s", "%s"}' % (rule["host"], rule["match"]))
    if not rows:
        return "new String[][]{}"
    body = ",\n        ".join(rows)
    return "new String[][] {\n        %s,\n    }" % body


def java_string_array(values) -> str:
    if not values:
        return "new String[0]"
    return "new String[] {\n        %s,\n    }" % ",\n        ".join(
        java_string(v) for v in values
    )


def host_csv(rules) -> str:
    return "|".join("%s=%s" % (r["host"], r["match"]) for r in rules or [])


def scheme_csv(values) -> str:
    return ",".join(values or [])


def _describe_rule(rule) -> str:
    host, mode = rule["host"], rule["match"]
    if mode == "exact":
        return host
    if mode == "domain":
        return "%s 及其子域" % host
    if mode == "child":
        return "*.%s（不含 %s 本身）" % (host, host)
    return "含 %s 的域名" % host


def payment_scope_summary(rules, schemes) -> str:
    parts = [_describe_rule(r) for r in rules or []]
    text = "支付与授权页面留在应用内打开：" + ("、".join(parts) if parts else "（未配置）")
    text += "。其余外链交给系统浏览器"
    if schemes:
        text += "；支付 App 唤端 scheme：%s" % "/".join(schemes)
    text += "。"
    return text


def build_tokens(config: dict) -> dict:
    caps = list(config.get("capabilities") or [])
    rules = config.get("payment_hosts") or []
    schemes = config.get("payment_schemes") or []
    tokens = {
        "__PROJECT_NAME__": str(config.get("project_name") or config.get("app_id") or ""),
        "__APPLICATION_ID__": str(config.get("application_id") or ""),
        "__APP_NAME__": str(config.get("brand_name") or config.get("label") or ""),
        "__START_URL__": str(config.get("start_url") or ""),
        "__ALLOWED_HOST__": str(config.get("allowed_host") or ""),
        "__VERSION_CODE__": str(config.get("version_code") or 1),
        "__VERSION_NAME__": str(config.get("version_name") or "1.0.0"),
        "__SHELL_VERSION__": str(config.get("shell_version") or "1.3.0"),
        "__PAYMENT_HOSTS__": java_host_table(rules),
        "__PAYMENT_SCHEMES__": java_string_array(schemes),
        "__PAYMENT_HOSTS_CSV__": host_csv(rules),
        "__PAYMENT_SCHEMES_CSV__": scheme_csv(schemes),
        "__PAYMENT_SCOPE_SUMMARY__": xml_escape(payment_scope_summary(rules, schemes)),
    }
    for cap, flag in CAP_FLAGS.items():
        tokens[flag] = "true" if cap in caps else "false"
    return tokens


def permission_block_text(caps) -> str:
    lines = []
    for cap in ("location", "microphone", "photo_upload"):
        if cap in (caps or []):
            lines.extend(PERMISSION_GROUPS[cap])
    return "\n".join(lines)


def _replace_block(text: str, marker: str, body: str, path: str) -> str:
    begin = "<!-- BEGIN %s" % marker
    end = "<!-- END %s -->" % marker
    start = text.find(begin)
    stop = text.find(end)
    if start == -1 or stop == -1 or stop < start:
        raise SystemExit("ERROR: %s missing %s markers" % (path, marker))
    head_end = text.find("\n", start) + 1
    return text[:head_end] + body + "\n    " + text[stop:]


def apply_rationale_overrides(text: str, overrides: dict) -> str:
    """Optional copy overrides; absent keys keep the shipped strings.xml text."""
    for key, value in (overrides or {}).items():
        names = RATIONALE_KEYS.get(key)
        if not names:
            continue
        title, rationale = names
        # value may be a plain string (rationale only) or a {title, rationale} map
        if isinstance(value, dict):
            pairs = {title: value.get("title"), rationale: value.get("rationale")}
        else:
            pairs = {rationale: value}
        for name, new in pairs.items():
            if not new:
                continue
            text = _replace_string(text, name, str(new))
    return text


def _replace_string(text: str, name: str, value: str) -> str:
    import re

    pattern = re.compile(r'(<string name="%s">)(.*?)(</string>)' % re.escape(name), re.S)
    if not pattern.search(text):
        return text
    return pattern.sub(lambda m: m.group(1) + xml_escape(value) + m.group(3), text, count=1)


def write_pack_snapshot(dest: str, config: dict, stamp: str) -> None:
    snapshot = {
        "app_id": config.get("app_id", ""),
        "label": config.get("label", ""),
        "application_id": config.get("application_id", ""),
        "start_url": config.get("start_url", ""),
        "allowed_host": config.get("allowed_host", ""),
        "version_code": config.get("version_code", 1),
        "version_name": config.get("version_name", ""),
        "shell_version": config.get("shell_version", ""),
        "capabilities": config.get("capabilities", []),
        "payment_hosts": config.get("payment_hosts", []),
        "payment_schemes": config.get("payment_schemes", []),
        "manifest_platform": config.get("_platform", "android"),
        "packed_at": stamp,
    }
    with open(os.path.join(dest, "x-app.json"), "w", encoding="utf-8") as fh:
        json.dump(snapshot, fh, ensure_ascii=False, indent=2)
        fh.write("\n")


def apply_tokens(dest: str, tokens: dict) -> int:
    touched = 0
    for dirpath, _, files in os.walk(dest):
        for name in files:
            if os.path.splitext(name)[1].lower() in SKIP_EXT:
                continue
            path = os.path.join(dirpath, name)
            try:
                text = open(path, encoding="utf-8").read()
            except Exception:
                continue
            orig = text
            for key, value in tokens.items():
                if key in text:
                    text = text.replace(key, value)
            if text != orig:
                with open(path, "w", encoding="utf-8") as fh:
                    fh.write(text)
                touched += 1
    return touched


def apply_structural(dest: str, config: dict) -> list:
    """Manifest permission block + strings overrides (capability/whitelist wiring)."""
    changed = []
    manifest_path = os.path.join(dest, "app/src/main/AndroidManifest.xml")
    if os.path.isfile(manifest_path):
        text = open(manifest_path, encoding="utf-8").read()
        text = _replace_block(
            text,
            "DX_PERMISSIONS",
            permission_block_text(config.get("capabilities")),
            manifest_path,
        )
        with open(manifest_path, "w", encoding="utf-8") as fh:
            fh.write(text)
        changed.append("app/src/main/AndroidManifest.xml")
    strings_path = os.path.join(dest, "app/src/main/res/values/strings.xml")
    if os.path.isfile(strings_path):
        text = open(strings_path, encoding="utf-8").read()
        overrides = config.get("permission_rationales") or {}
        if overrides:
            text = apply_rationale_overrides(text, overrides)
        with open(strings_path, "w", encoding="utf-8") as fh:
            fh.write(text)
        changed.append("app/src/main/res/values/strings.xml")
    return changed


def run_apply(dest: str, config: dict, stamp: str) -> dict:
    tokens = build_tokens(config)
    touched = apply_tokens(dest, tokens)
    changed = apply_structural(dest, config)
    write_pack_snapshot(dest, config, stamp)
    leftovers = scan_leftover_tokens(dest)
    return {
        "tokens": tokens,
        "files_tokenised": touched,
        "files_structural": changed,
        "leftover_tokens": leftovers,
    }


KNOWN_TOKENS = (
    "__PROJECT_NAME__", "__APPLICATION_ID__", "__APP_NAME__", "__START_URL__",
    "__ALLOWED_HOST__", "__VERSION_CODE__", "__VERSION_NAME__", "__SHELL_VERSION__",
    "__PAYMENT_HOSTS__", "__PAYMENT_SCHEMES__", "__PAYMENT_HOSTS_CSV__",
    "__PAYMENT_SCHEMES_CSV__", "__PAYMENT_SCOPE_SUMMARY__", "__CAP_LOCATION__",
    "__CAP_MICROPHONE__", "__CAP_PHOTO_UPLOAD__",
)


def scan_leftover_tokens(dest: str) -> list:
    found = []
    for dirpath, _, files in os.walk(dest):
        for name in files:
            if os.path.splitext(name)[1].lower() in SKIP_EXT:
                continue
            path = os.path.join(dirpath, name)
            try:
                text = open(path, encoding="utf-8").read()
            except Exception:
                continue
            for token in KNOWN_TOKENS:
                if token in text:
                    found.append("%s:%s" % (os.path.relpath(path, dest), token))
    return sorted(found)


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(description="Android shell codegen")
    parser.add_argument("mode", choices=["apply", "tokens", "payment-csv", "scope-summary"])
    parser.add_argument("--dest", default="")
    parser.add_argument("--config", required=True, help="resolved pack config JSON")
    parser.add_argument("--stamp", default="")
    args = parser.parse_args(argv)

    config = json.load(open(args.config, encoding="utf-8"))
    if args.mode == "tokens":
        print(json.dumps(build_tokens(config), ensure_ascii=False, indent=2))
        return 0
    if args.mode == "payment-csv":
        print(host_csv(config.get("payment_hosts")))
        return 0
    if args.mode == "scope-summary":
        print(payment_scope_summary(config.get("payment_hosts"), config.get("payment_schemes")))
        return 0
    if not args.dest or not os.path.isdir(args.dest):
        print("ERROR: --dest must be an existing directory", file=sys.stderr)
        return 2
    report = run_apply(args.dest, config, args.stamp)
    print("    codegen : %d tokenised file(s); %s rewired" % (
        report["files_tokenised"], ", ".join(os.path.basename(p) for p in report["files_structural"])))
    if report["leftover_tokens"]:
        for item in report["leftover_tokens"]:
            print("ERROR: unreplaced token %s" % item, file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
