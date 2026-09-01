#!/usr/bin/env python3
"""DX-PACK-MANIFEST gate (L3 Phase H4) — pure static check, no DB / no network.

Examples:
  python3 tools/packer/validate_manifest.py --all
  python3 tools/packer/validate_manifest.py --platform=android --app=car_hailing_assistant
  python3 tools/packer/validate_manifest.py --schema --platform=android
  python3 tools/packer/validate_manifest.py --resolve --platform=android \
      --file=tools/android-packer/apps/car_hailing_assistant.manifest.yml
"""

from __future__ import annotations

import argparse
import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import manifest_lib as ml  # noqa: E402


def emit(payload, as_json: bool, text: str = ""):
    if as_json:
        print(json.dumps(payload, ensure_ascii=False, indent=2, sort_keys=True))
    elif text:
        print(text)


def field_rows(schema, platform):
    rows = []
    specs = ml.platform_field_specs(schema, platform)
    for name in sorted(specs):
        spec = specs[name]
        default = spec.get("default", "—")
        if isinstance(default, (list, dict)):
            default = json.dumps(default, ensure_ascii=False)
        elif default is None:
            default = '""'
        elif default is True:
            default = "true"
        elif default is False:
            default = "false"
        rows.append({
            "field": name,
            "type": spec.get("type", "string"),
            "required": bool(spec.get("required", False)),
            "default": default,
            "aliases": ",".join(spec.get("aliases", [])),
            "enum": "|".join(str(e) for e in spec.get("enum", [])) or "",
            "desc": spec.get("desc", ""),
            "scope": "common" if name in schema["common_fields"] else platform,
        })
    return rows


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(description="DX-PACK-MANIFEST validator")
    parser.add_argument("--schema-file", default=ml.SCHEMA_PATH)
    parser.add_argument("--platform", default="", help="android | miniprogram | flutter")
    parser.add_argument("--app", default="", help="app id (looks up the platform apps dir)")
    parser.add_argument("--file", default="", help="explicit manifest path")
    parser.add_argument("--all", action="store_true", help="validate every registered manifest")
    parser.add_argument("--list", action="store_true", help="list registered app ids")
    parser.add_argument(
        "--names-only",
        action="store_true",
        help="with --list: print one bare app id per line (what x-pack-*.sh --list shows)",
    )
    parser.add_argument("--schema", action="store_true", help="dump the field table")
    parser.add_argument("--platforms", action="store_true", help="list platforms")
    parser.add_argument("--resolve", action="store_true", help="print the merged pack config as JSON")
    parser.add_argument("--override", action="append", default=[], help="field=value (repeatable)")
    parser.add_argument("--add-payment-host", action="append", default=[],
                        help="host[:mode] appended to the resolved payment_hosts (repeatable)")
    parser.add_argument("--json", action="store_true", help="machine readable output")
    parser.add_argument("--no-yaml", action="store_true", help="force the builtin YAML-subset parser")
    args = parser.parse_args(argv)

    schema = ml.load_schema(args.schema_file)

    if args.platforms:
        emit({"platforms": sorted(schema["platforms"])}, args.json,
             "\n".join(sorted(schema["platforms"])))
        return 0

    if args.schema:
        platforms = [args.platform] if args.platform else sorted(schema["platforms"])
        rows = []
        for platform in platforms:
            rows += field_rows(schema, platform)
        emit({"schema_version": schema.get("schema_version"), "fields": rows}, args.json,
             _render_table(rows))
        return 0

    if args.list:
        platforms = [args.platform] if args.platform else sorted(schema["platforms"])
        payload = {}
        lines = []
        for platform in platforms:
            ids = []
            for path in ml.manifest_files(schema, platform):
                name = os.path.basename(path)
                ids.append(name.replace(".manifest.yml", ""))
            payload[platform] = ids
            lines.append("%s: %s" % (platform, " ".join(ids) or "(none)"))
        if args.names_only and not args.json:
            for platform in platforms:
                for app_id in payload[platform]:
                    print(app_id)
            return 0
        emit(payload, args.json, "\n".join(lines))
        return 0

    if args.all:
        platforms = [args.platform] if args.platform else sorted(schema["platforms"])
        reports = []
        for platform in platforms:
            reports += [
                ml.validate_file(schema, platform, path, force_fallback=args.no_yaml)
                for path in ml.manifest_files(schema, platform)
            ]
        if not reports:
            print("ERROR: no manifests found for %s" % "/".join(platforms), file=sys.stderr)
            return 1
        return _report(reports, args.json)

    if not args.platform:
        print("ERROR: --platform=<android|miniprogram|flutter> required", file=sys.stderr)
        return 2

    if args.file:
        paths = [args.file]
    elif args.app:
        base = os.path.join(ml.REPO_ROOT, schema["platforms"][args.platform]["apps_dir"])
        paths = [os.path.join(base, "%s.manifest.yml" % args.app)]
    else:
        print("ERROR: --app=<id> or --file=<path> required", file=sys.stderr)
        return 2

    for path in paths:
        if not os.path.isfile(path):
            print("ERROR: manifest not found: %s" % path, file=sys.stderr)
            return 1

    if args.resolve:
        overrides = {}
        for item in args.override:
            if "=" not in item:
                print("ERROR: --override wants field=value, got %r" % item, file=sys.stderr)
                return 2
            key, value = item.split("=", 1)
            overrides[key] = value
        manifest = ml.load_manifest(paths[0], force_fallback=args.no_yaml)
        app_id = str(manifest.get("app_id") or manifest.get("id")
                     or os.path.basename(paths[0]).replace(".manifest.yml", ""))
        config = ml.resolve_config(schema, args.platform, manifest, overrides, app_id=app_id)
        for extra in args.add_payment_host:
            host, _, mode = extra.partition(":")
            rule = ml.normalize_host_rules([{"host": host, "match": mode or "domain"}])[0]
            if not rule["host"]:
                print("ERROR: --add-payment-host wants host[:mode], got %r" % extra, file=sys.stderr)
                return 2
            rules = config.setdefault("payment_hosts", [])
            if rule not in rules:
                rules.append(rule)
        print(json.dumps(config, ensure_ascii=False, indent=2, sort_keys=True))
        return 0

    reports = [ml.validate_file(schema, args.platform, p, force_fallback=args.no_yaml) for p in paths]
    return _report(reports, args.json)


def _render_table(rows):
    out = []
    for row in rows:
        req = "required" if row["required"] else "optional"
        default = row["default"]
        if isinstance(default, str) and len(default) > 72:
            default = default[:69] + "..."
        line = "%-12s %-22s %-10s %-14s default=%s" % (
            row["scope"], row["field"], row["type"], req, default)
        if row["enum"]:
            line += "  enum=%s" % row["enum"]
        out.append(line)
    return "\n".join(out)


def _report(reports, as_json: bool) -> int:
    failed = False
    if as_json:
        print(json.dumps(reports, ensure_ascii=False, indent=2, sort_keys=True))
        return 0 if all(r["ok"] for r in reports) else 1
    for report in reports:
        tag = "OK  " if report["ok"] else "FAIL"
        print("%s %s/%s (%s)" % (tag, report["platform"], report["app_id"], report["file"]))
        for error in report["errors"]:
            print("     error: %s" % error)
        for warning in report["warnings"]:
            print("     warn : %s" % warning)
        failed = failed or not report["ok"]
    return 1 if failed else 0


if __name__ == "__main__":
    raise SystemExit(main())
