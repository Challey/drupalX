#!/usr/bin/env python3
"""DX-PACK-MANIFEST shared library (L3 Phase H4).

Reads ``tools/packer/manifest-schema.json`` and the per-app
``tools/<packer>/apps/<app_id>.manifest.yml`` files, then resolves a flat,
normalised pack config (schema defaults <- manifest <- CLI overrides) and
validates it.

Pure standard library. PyYAML is used when importable; a small YAML-subset
parser is the fallback so the gate still runs on a bare python3.

Used by:
  scripts/x-pack-manifest.sh            (CI gate entry point)
  scripts/x-pack-android.sh             (pack config resolve + codegen input)
  tools/android-packer/lib/shell_codegen.py
"""

from __future__ import annotations

import json
import os
import re
import sys

REPO_ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
SCHEMA_PATH = os.path.join(REPO_ROOT, "tools", "packer", "manifest-schema.json")


# --------------------------------------------------------------------------
# YAML loading
# --------------------------------------------------------------------------

def _strip_comment(line: str) -> str:
    out, quote = [], ""
    for i, ch in enumerate(line):
        if quote:
            out.append(ch)
            if ch == quote and line[i - 1: i] != "\\":
                quote = ""
            continue
        if ch in "\"'":
            quote = ch
            out.append(ch)
            continue
        if ch == "#" and (i == 0 or line[i - 1] in " \t"):
            break
        out.append(ch)
    return "".join(out).rstrip()


def _scalar(text: str):
    text = text.strip()
    if len(text) >= 2 and text[0] == text[-1] and text[0] in "\"'":
        return text[1:-1]
    low = text.lower()
    if low in ("true", "yes", "on"):
        return True
    if low in ("false", "no", "off"):
        return False
    if low in ("null", "~", ""):
        return None
    if re.fullmatch(r"-?\d+", text):
        return int(text)
    if re.fullmatch(r"-?\d+\.\d+", text):
        return float(text)
    if text.startswith("[") and text.endswith("]"):
        inner = text[1:-1].strip()
        return [_scalar(p) for p in inner.split(",")] if inner else []
    return text


def _indent(line: str) -> int:
    return len(line) - len(line.lstrip(" "))


def _parse_block(lines, pos, indent):
    """Parse a mapping / sequence block starting at lines[pos] with >= indent."""
    # decide container kind from the first meaningful line
    while pos < len(lines) and not lines[pos].strip():
        pos += 1
    if pos >= len(lines):
        return {}, pos
    if lines[pos].lstrip().startswith("- "):
        return _parse_seq(lines, pos, indent)
    return _parse_map(lines, pos, indent)


def _parse_seq(lines, pos, indent):
    items = []
    while pos < len(lines):
        raw = lines[pos]
        if not raw.strip():
            pos += 1
            continue
        cur = _indent(raw)
        if cur < indent or not raw.lstrip().startswith("- "):
            break
        body = raw.lstrip()[2:]
        if re.match(r"^[^:]+:(\s|$)", body) and not body.startswith("{"):
            # inline map item: "- host: x"
            sub_lines = ["  " + body]
            j = pos + 1
            while j < len(lines):
                nxt = lines[j]
                if not nxt.strip():
                    j += 1
                    continue
                if _indent(nxt) <= cur:
                    break
                sub_lines.append(nxt)
                j += 1
            item, _ = _parse_map(sub_lines, 0, 2)
            items.append(item)
            pos = j
        else:
            items.append(_scalar(body))
            pos += 1
    return items, pos


def _parse_map(lines, pos, indent):
    result = {}
    while pos < len(lines):
        raw = lines[pos]
        if not raw.strip():
            pos += 1
            continue
        cur = _indent(raw)
        if cur < indent:
            break
        stripped = raw.strip()
        if stripped.startswith("- ") or stripped == "":
            break
        m = re.match(r"^([^:]+):\s*(.*)$", stripped)
        if not m:
            pos += 1
            continue
        key = m.group(1).strip().strip("\"'")
        value = m.group(2).strip()
        if value in (">", ">-", "|", "|-"):
            block, pos = _collect_folded(lines, pos + 1, cur)
            result[key] = block
            continue
        if value == "":
            # nested container (map / seq) or empty
            child_indent = None
            j = pos + 1
            while j < len(lines) and not lines[j].strip():
                j += 1
            if j < len(lines):
                child_indent = _indent(lines[j])
                if lines[j].lstrip().startswith("- "):
                    child_indent = indent + 2
            if child_indent is None or child_indent <= cur:
                result[key] = ""
                pos += 1
                continue
            if lines[j].lstrip().startswith("- "):
                sub, pos = _parse_seq(lines, j, child_indent)
            else:
                sub, pos = _parse_map(lines, j, _indent(lines[j]))
            result[key] = sub
            continue
        result[key] = _scalar(value)
        pos += 1
    return result, pos


def _collect_folded(lines, pos, parent_indent):
    parts = []
    while pos < len(lines):
        raw = lines[pos]
        if raw.strip() and _indent(raw) <= parent_indent:
            break
        parts.append(raw.strip())
        pos += 1
    return " ".join(p for p in parts if p), pos


def load_yaml_text(text: str):
    try:
        import yaml  # type: ignore
    except Exception:
        yaml = None
    if yaml is not None:
        return yaml.safe_load(text) or {}
    lines = [_strip_comment(l.rstrip("\n")) for l in text.splitlines()]
    lines = [l for l in lines if l.strip() != "" or True]
    data, _ = _parse_map(lines, 0, 0)
    return data


def load_yaml_file(path: str, force_fallback: bool = False):
    with open(path, encoding="utf-8") as fh:
        text = fh.read()
    if force_fallback:
        lines = [_strip_comment(l.rstrip("\n")) for l in text.splitlines()]
        data, _ = _parse_map(lines, 0, 0)
        return data
    return load_yaml_text(text) or {}


# --------------------------------------------------------------------------
# schema helpers
# --------------------------------------------------------------------------

def load_schema(path: str = SCHEMA_PATH) -> dict:
    with open(path, encoding="utf-8") as fh:
        return json.load(fh)


def platform_field_specs(schema: dict, platform: str) -> dict:
    if platform not in schema["platforms"]:
        raise KeyError("unknown platform: %s" % platform)
    specs = dict(schema["common_fields"])
    specs.update(schema["platforms"][platform].get("fields", {}))
    return specs


def _interpolate(value, ctx: dict):
    if isinstance(value, str):
        return re.sub(
            r"<([a-z_]+)>",
            lambda m: str(ctx.get(m.group(1), m.group(0))),
            value,
        )
    if isinstance(value, list):
        return [_interpolate(v, ctx) for v in value]
    if isinstance(value, dict):
        return {k: _interpolate(v, ctx) for k, v in value.items()}
    return value


def _resolve_default(spec: dict, merged: dict, platform: str, ctx: dict):
    default = spec.get("default")
    if isinstance(default, str) and default.startswith("="):
        expr = default[1:]
        m = re.fullmatch(r"host\(([a-z_]+)\)", expr)
        if m:
            url = str(merged.get(m.group(1), "") or "")
            match = re.match(r"^[a-zA-Z][a-zA-Z0-9+.-]*://([^/?#]+)", url)
            return match.group(1) if match else ""
        key = expr
        return _interpolate(merged.get(key, ""), ctx)
    return _interpolate(default, ctx)


def parse_host_rules_str(text: str):
    """Parse the canonical serialised form ``a.com=exact|b.com=child``."""
    rules = []
    for chunk in str(text).split("|"):
        chunk = chunk.strip()
        if not chunk:
            continue
        host, _, mode = chunk.partition("=")
        rules.append({"host": host.strip().lower(),
                      "match": (mode.strip().lower() or "domain")})
    return rules


def parse_list_str(text: str):
    return [part.strip() for part in str(text).split(",") if part.strip()]


def _coerce(spec: dict, value):
    ttype = spec.get("type", "string")
    base = ttype.split("<")[0]
    if base == "list" and isinstance(value, str):
        # CLI overrides arrive as strings; accept the serialised forms.
        return parse_host_rules_str(value) if _sub_type(spec) == "host_rule" else parse_list_str(value)
    if base in ("list", "map"):
        return value
    if ttype == "integer":
        try:
            return int(value)
        except (TypeError, ValueError):
            return value
    if ttype == "boolean":
        if isinstance(value, bool):
            return value
        return str(value).strip().lower() in ("1", "true", "yes", "on")
    return value


def _type_name(spec: dict) -> str:
    ttype = spec.get("type", "string")
    return ttype.split("<")[0]


def _sub_type(spec: dict):
    m = re.match(r"(?:list|map)<(.+)>", spec.get("type", ""))
    return m.group(1) if m else None


def check_value(spec: dict, name: str, value) -> list:
    """Return a list of issue strings for one field value."""
    issues = []
    base = _type_name(spec)
    if value is None:
        return ["%s: missing (required)" % name]
    if base == "string":
        if not isinstance(value, str):
            return ["%s: expected string, got %s" % (name, type(value).__name__)]
        pattern = spec.get("pattern")
        if pattern and not re.match(pattern, value):
            issues.append("%s: '%s' does not match %s" % (name, value, pattern))
    elif base == "integer":
        if isinstance(value, bool) or not isinstance(value, int):
            if not (isinstance(value, str) and re.fullmatch(r"\d+", value)):
                issues.append("%s: expected integer, got %r" % (name, value))
    elif base == "boolean":
        if not isinstance(value, bool):
            issues.append("%s: expected boolean, got %r" % (name, value))
    elif base == "list":
        if not isinstance(value, list):
            return ["%s: expected list, got %s" % (name, type(value).__name__)]
        if spec.get("unique") and len(value) != len({json.dumps(v, sort_keys=True) for v in value}):
            issues.append("%s: duplicate entries" % name)
        enum = spec.get("enum")
        if enum:
            for item in value:
                if item not in enum:
                    issues.append("%s: '%s' not in %s" % (name, item, "|".join(enum)))
        if _sub_type(spec) == "host_rule" or spec.get("item_type") == "host_rule":
            issues += _check_host_rules(name, value)
    elif base == "map":
        if not isinstance(value, dict):
            return ["%s: expected map, got %s" % (name, type(value).__name__)]
        keys_enum = spec.get("keys_enum")
        if keys_enum:
            for key in value:
                if key not in keys_enum:
                    issues.append("%s: unknown key '%s' (allowed: %s)" % (name, key, ", ".join(keys_enum)))
        for key, sub in (spec.get("keys") or {}).items():
            if key in value:
                issues += check_value(sub, "%s.%s" % (name, key), value[key])
    return issues


def _check_host_rules(name: str, rules) -> list:
    issues = []
    if not isinstance(rules, list):
        return ["%s: expected list" % name]
    allowed = ("exact", "domain", "child", "contains")
    for idx, rule in enumerate(rules):
        if not isinstance(rule, dict) or not rule.get("host"):
            issues.append("%s[%d]: entry needs a 'host' key" % (name, idx))
            continue
        host = str(rule["host"]).lower()
        if not re.fullmatch(r"[a-z0-9][a-z0-9.\-]*[a-z0-9]", host):
            issues.append("%s[%d]: bad host '%s'" % (name, idx, rule["host"]))
        match = rule.get("match", "domain")
        if match not in allowed:
            issues.append("%s[%d]: match '%s' not in %s" % (name, idx, match, "|".join(allowed)))
    return issues


def normalize_host_rules(rules):
    out = []
    for rule in rules or []:
        if isinstance(rule, str):
            rule = {"host": rule}
        out.append({
            "host": str(rule.get("host", "")).strip().lower(),
            "match": str(rule.get("match", "domain")).strip().lower(),
        })
    return out


def resolve_config(
    schema: dict,
    platform: str,
    manifest: dict,
    overrides: dict | None = None,
    app_id: str = "",
) -> dict:
    """Merge schema defaults <- manifest <- overrides into a flat config."""
    specs = platform_field_specs(schema, platform)
    ctx = {"app_id": app_id}
    merged: dict = {"app_id": app_id}
    provenance: dict = {}

    alias_map = {}
    for field, spec in specs.items():
        for alias in spec.get("aliases", []):
            alias_map.setdefault(alias, field)

    def put(field, value, source):
        merged[field] = _coerce(specs.get(field, {"type": "string"}), value) if field in specs else value
        provenance[field] = source

    # 1. manifest values (aliases folded onto the canonical name)
    declared = {}
    for key, value in (manifest or {}).items():
        field = alias_map.get(key, key)
        if key != field and field in declared:
            continue
        declared[field] = value
    for field, value in declared.items():
        put(field, value, "manifest")
    for field, spec in specs.items():
        if field not in declared and spec.get("required") and spec.get("aliases"):
            for alias in spec["aliases"]:
                if alias in (manifest or {}):
                    put(field, (manifest or {})[alias], "manifest")
                    break

    # 2. defaults for anything absent
    for field, spec in specs.items():
        if field not in merged or merged[field] in ("", None):
            if "default" in spec:
                put(field, _resolve_default(spec, merged, platform, ctx), "default")
    merged.setdefault("app_id", app_id)
    for field in merged:
        provenance.setdefault(field, "default")

    # 3. CLI overrides win
    for field, value in (overrides or {}).items():
        if value is None or value == "":
            continue
        put(field, value, "override")

    # host rules always normalised to {host, match}
    if "payment_hosts" in merged:
        merged["payment_hosts"] = normalize_host_rules(merged.get("payment_hosts"))
    if platform == "android" and not merged.get("allowed_host"):
        specs_url = specs.get("allowed_host", {})
        merged["allowed_host"] = _resolve_default(specs_url, merged, platform, ctx)
    merged["_platform"] = platform
    merged["_provenance"] = provenance
    merged["_extra"] = {
        k: v for k, v in (manifest or {}).items() if k not in specs and k not in alias_map
    }
    return merged


def validate_config(schema: dict, platform: str, config: dict) -> dict:
    specs = platform_field_specs(schema, platform)
    errors, warnings = [], []
    for field, spec in specs.items():
        value = config.get(field)
        if spec.get("required"):
            if value in (None, ""):
                errors.append("%s: required but empty" % field)
                continue
        if value in (None, ""):
            continue
        for issue in check_value(spec, field, value):
            errors.append(issue)
    for field in config.get("_extra", {}):
        warnings.append("%s: not declared in schema (ignored by the packer)" % field)
    return {
        "platform": platform,
        "app_id": config.get("app_id", ""),
        "ok": not errors,
        "errors": errors,
        "warnings": warnings,
        "config": {k: v for k, v in config.items() if not k.startswith("_")},
    }


def manifest_files(schema: dict, platform: str, root: str = REPO_ROOT) -> list:
    apps_dir = os.path.join(root, schema["platforms"][platform]["apps_dir"])
    if not os.path.isdir(apps_dir):
        return []
    glob = schema["platforms"][platform].get("manifest_glob", "*.manifest.yml")
    suffix = glob.replace("*", "")
    return sorted(
        os.path.join(apps_dir, name)
        for name in os.listdir(apps_dir)
        if name.endswith(suffix)
    )


def load_manifest(path: str, force_fallback: bool = False) -> dict:
    data = load_yaml_file(path, force_fallback=force_fallback)
    if not isinstance(data, dict):
        raise ValueError("manifest root must be a mapping: %s" % path)
    return data


def validate_file(schema: dict, platform: str, path: str, force_fallback: bool = False) -> dict:
    manifest = load_manifest(path, force_fallback=force_fallback)
    specs = platform_field_specs(schema, platform)
    app_id = ""
    for key in ("app_id", "id"):
        if manifest.get(key):
            app_id = str(manifest[key])
            break
    if not app_id:
        app_id = os.path.basename(path).replace(".manifest.yml", "")
    config = resolve_config(schema, platform, manifest, app_id=app_id)
    report = validate_config(schema, platform, config)
    report["file"] = os.path.relpath(path, REPO_ROOT)
    if not manifest:
        report["errors"].append("empty manifest")
    return report
