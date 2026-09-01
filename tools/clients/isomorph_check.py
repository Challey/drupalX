#!/usr/bin/env python3
"""DXEP three-end isomorphism gate (L3 Phase H2 + H3).

Pure text/JSON analysis: no Flutter SDK, no WeChat devtools, no database, no
network. This is the CI entry point behind
``scripts/ci/clients-isomorph-smoke.sh`` and answers one question with a file
and line an operator can act on: do the Web, Flutter and mini-program ends
still describe the same components and the same data fields?

Modes
  catalog    component catalogue v2 <-> Dart mirror <-> registry <-> widget
             files <-> mini-program dxep.js <-> index.wxml <-> layout fixtures
  fields     every contract field must be anchored on every end that requires it
             (server projection, dx_portal, both client mirrors)
  fixtures   the per-end fixture pairs must carry identical data and satisfy the
             contract (presence + type + nothing undeclared)
  dart       static parse of every Dart source under clients/flutter_shell
             (balanced delimiters, resolvable imports) - a substitute for
             `flutter analyze` when no SDK is installed
  mp         static parse of the mini-program end: app.json pages exist,
             require() targets resolve, fixtures are registered, wxml tags balance
  mirror     regenerate / verify the Flutter + mini-program contract mirrors
  all        catalog + fields + fixtures + dart + mp

Exit 0 = every check passed, 1 = at least one FAIL, 2 = the gate could not run
(missing input) so CI never turns a broken checker into a false green.
"""

from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path

CONTRACT_REL = "clients/field-contract.json"
CATALOG_REL = "clients/flutter_shell/assets/config/component_catalog.json"
MANIFEST_SCHEMA_REL = "tools/packer/manifest-schema.json"

# Component types already delivered to customers in 1.2.x shells. They may be
# extended but never removed, renamed or re-versioned.
V1_FROZEN_TYPES = [
    "hero_banner",
    "notice_ticker",
    "article_list",
    "notice_list",
    "product_grid",
    "service_grid",
    "profile_header",
    "rich_html",
    "content",
    "web_link",
    "empty",
    "error",
]

# Layout capabilities are a superset of the Android runtime capabilities:
# `share` needs no permission, so it never shows up in the packer schema.
NON_MANIFEST_CAPABILITIES = {"share"}

KNOWN_TYPES = {
    "boolean", "integer", "number", "string", "nullable_string",
    "rfc3339", "list", "map", "opaque",
}

MIRROR_RELS = {
    "flutter": "clients/flutter_shell/lib/dxep/field_contract.dart",
    "miniprogram": "clients/wechat-miniprogram/utils/field_contract.js",
}

# CLI mode name -> Gate method. `mp` cannot be `run_mp` because the failure
# output must name the end, not an abbreviation.
MODE_METHOD = {
    "catalog": "run_catalog",
    "fields": "run_fields",
    "fixtures": "run_fixtures",
    "dart": "run_dart",
    "mp": "run_miniprogram",
}

RFC3339 = re.compile(
    r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$"
)


class GateError(Exception):
    """The gate cannot run at all (missing or malformed input)."""


def find_root() -> Path:
    here = Path(__file__).resolve()
    for cand in here.parents:
        if (cand / CONTRACT_REL).is_file():
            return cand
    raise GateError("repository root not found - pass --root")


def json_type(value: object) -> str:
    if value is None:
        return "null"
    if isinstance(value, bool):
        return "boolean"
    if isinstance(value, int):
        return "integer"
    if isinstance(value, float):
        return "number"
    if isinstance(value, str):
        return "string"
    if isinstance(value, list):
        return "list"
    if isinstance(value, dict):
        return "map"
    return "unknown"  # pragma: no cover


def without_resource(path: str) -> str:
    return path.split(".", 1)[1] if "." in path else path


def fmt_set(items: set[str]) -> str:
    return ",".join(sorted(items)) if items else "-"


def deep_diff(a: object, b: object, path: str = "$") -> list[str]:
    diffs: list[str] = []
    if isinstance(a, dict) and isinstance(b, dict):
        for key in sorted(set(a) | set(b)):
            diffs.extend(deep_diff(a.get(key), b.get(key), f"{path}.{key}"))
    elif isinstance(a, list) and isinstance(b, list):
        if len(a) != len(b):
            diffs.append(f"{path}: length {len(a)} != {len(b)}")
        else:
            for idx, (x, y) in enumerate(zip(a, b)):
                diffs.extend(deep_diff(x, y, f"{path}[{idx}]"))
    elif a != b or type(a) is not type(b):
        diffs.append(f"{path}: {a!r} != {b!r}")
    return diffs


def resolve_path(doc: object, dotted: str) -> list[object] | None:
    """``data[]`` -> every element of doc['data']; ``''`` -> [doc]."""
    if dotted == "":
        return [doc]
    current: list[object] = [doc]
    for token in dotted.split("."):
        as_list = token.endswith("[]")
        key = token[:-2] if as_list else token
        nxt: list[object] = []
        for item in current:
            if not isinstance(item, dict) or key not in item:
                continue
            value = item[key]
            if as_list:
                if isinstance(value, list):
                    nxt.extend(value)
            else:
                nxt.append(value)
        if not nxt:
            return None
        current = nxt
    return current


def type_ok(field: dict, observed: str, value: object) -> bool:
    declared = field.get("type")
    if declared == "opaque":
        return True
    if declared == "nullable_string":
        good = observed in ("string", "null")
    elif declared == "number":
        good = observed in ("integer", "number")
    elif declared == "rfc3339":
        good = observed == "string" and RFC3339.match(str(value or "")) is not None
    elif declared in KNOWN_TYPES:
        good = observed == declared
    else:
        return False
    if not good:
        return False
    enum = field.get("enum")
    if enum and observed == "string" and value not in enum:
        return False
    return True


def strip_dart(text: str) -> str:
    """Blank out comments and string literals so delimiters can be counted."""
    out: list[str] = []
    i, n = 0, len(text)
    while i < n:
        ch = text[i]
        nxt = text[i + 1] if i + 1 < n else ""
        if ch == "/" and nxt == "/":
            while i < n and text[i] != "\n":
                out.append(" ")
                i += 1
            continue
        if ch == "/" and nxt == "*":
            depth = 0
            while i < n:
                if text[i] == "/" and i + 1 < n and text[i + 1] == "*":
                    depth += 1
                    out.append("  ")
                    i += 2
                    continue
                if text[i] == "*" and i + 1 < n and text[i + 1] == "/":
                    depth -= 1
                    out.append("  ")
                    i += 2
                    if depth == 0:
                        break
                    continue
                out.append("\n" if text[i] == "\n" else " ")
                i += 1
            continue
        if ch in "'\"":
            raw = i > 0 and text[i - 1] == "r"
            quote = ch
            triple = text[i:i + 3] == quote * 3
            token = quote * 3 if triple else quote
            i += len(token)
            out.append(" " * len(token))
            while i < n:
                if not raw and text[i] == "\\":
                    out.append("  ")
                    i += 2
                    continue
                if text[i:i + len(token)] == token:
                    out.append(" " * len(token))
                    i += len(token)
                    break
                out.append("\n" if text[i] == "\n" else " ")
                i += 1
            continue
        out.append(ch)
        i += 1
    return "".join(out)


class Gate:
    def __init__(self, root: Path, verbose: bool = False) -> None:
        self.root = root
        self.verbose = verbose
        self.checks = 0
        self.failures: list[str] = []
        self.contract = self._load_json(CONTRACT_REL)
        self.catalog = self._load_json(CATALOG_REL)
        self.declared: dict[str, dict] = {}
        for field in self.contract["fields"]:
            if field["path"] in self.declared:
                raise GateError(f"duplicate contract path {field['path']}")
            self.declared[field["path"]] = field

    # ---------------------------------------------------------------- helpers
    def _load_json(self, rel: str) -> dict:
        path = self.root / rel
        if not path.is_file():
            raise GateError(f"missing {rel}")
        try:
            return json.loads(path.read_text(encoding="utf-8"))
        except json.JSONDecodeError as exc:
            raise GateError(f"{rel}: invalid JSON: {exc}") from exc

    def read(self, rel: str) -> str:
        path = self.root / rel
        if not path.is_file():
            raise GateError(f"missing {rel}")
        return path.read_text(encoding="utf-8")

    def ok(self, label: str) -> None:
        self.checks += 1
        if self.verbose:
            print(f"  ok   {label}")

    def fail(self, label: str, detail: str) -> None:
        self.checks += 1
        self.failures.append(f"FAIL {label} :: {detail}")
        print(f"FAIL {label} :: {detail}")

    def expect(self, cond: bool, label: str, detail: str) -> bool:
        if cond:
            self.ok(label)
            return True
        self.fail(label, detail)
        return False

    def existing(self, files: list[str]) -> list[str]:
        """Subset of files that are present in this checkout.

        Without this a stripped checkout would report `the end never names the
        field` for every field, which is the opposite of a precise diagnosis.
        """
        return [rel for rel in files if (self.root / rel).is_file()]

    def grep(self, files: list[str], pattern: str) -> str | None:
        try:
            rx = re.compile(pattern)
        except re.error as exc:
            raise GateError(f"bad contract pattern /{pattern}/: {exc}")
        for rel in files:
            try:
                text = self.read(rel)
            except GateError:
                continue
            for no, line in enumerate(text.splitlines(), start=1):
                if rx.search(line):
                    return f"{rel}:{no}"
        return None

    # ---------------------------------------------------------------- catalog
    def catalog_components(self) -> dict[str, dict]:
        out: dict[str, dict] = {}
        for entry in self.catalog.get("components") or []:
            ctype = entry.get("type")
            if not ctype:
                raise GateError("component without type in the catalogue")
            if ctype in out:
                raise GateError(f"duplicate component type {ctype} in the catalogue")
            out[ctype] = entry
        if not out:
            raise GateError("the catalogue lists no components")
        return out

    def dart_components(self) -> dict[str, dict]:
        text = self.read(self.catalog["endpoints"]["flutter"]["dart_mirror"])
        out: dict[str, dict] = {}
        for body in re.findall(r"ComponentSpec\(([^)]*)\)", text):
            entry: dict[str, object] = {}
            for match in re.finditer(r"(\w+):\s*(?:'([^']*)'|(\d+))", body):
                key = match.group(1)
                value: object = match.group(2) if match.group(2) is not None else int(match.group(3))
                entry[key] = value if value != "" else None
            if "type" not in entry:
                continue
            if entry["type"] in out:
                raise GateError(f"duplicate {entry['type']} in the Dart mirror")
            out[str(entry["type"])] = entry
        return out

    def check_catalog_schema(self) -> None:
        self.expect(
            self.catalog.get("spec") == "DX-COMPONENT-CATALOG",
            "catalog.spec",
            f"expected DX-COMPONENT-CATALOG, got {self.catalog.get('spec')!r}",
        )
        version = self.catalog.get("schema_version")
        self.expect(
            isinstance(version, int) and version >= 2,
            "catalog.schema_version",
            "the registry must carry a schema version field >= 2, "
            f"got {version!r}",
        )
        self.expect(
            bool(self.catalog.get("rules", {}).get("backwards_compat")),
            "catalog.backwards_compat_rule",
            "the catalogue must document its additive-only rule (shipped apps)",
        )

    def check_dart_mirror(self, comps: dict[str, dict]) -> None:
        rel = self.catalog["endpoints"]["flutter"]["dart_mirror"]
        text = self.read(rel)
        dart = self.dart_components()
        self.expect(
            set(dart) == set(comps),
            "catalog.dart_mirror.types",
            f"{rel}: only in JSON={fmt_set(set(comps) - set(dart))} "
            f"only in Dart={fmt_set(set(dart) - set(comps))}",
        )
        for ctype, entry in sorted(comps.items()):
            got = dart.get(ctype) or {}
            diff = []
            for key in ("widget", "file", "since", "capability", "aliasOf"):
                if (entry.get(key) or None) != (got.get(key) or None):
                    diff.append(f"{key}: json={entry.get(key)!r} dart={got.get(key)!r}")
            self.expect(
                not diff,
                f"catalog.dart_mirror.{ctype}",
                f"{rel}: {'; '.join(diff)}",
            )
        match = re.search(r"static const int schemaVersion\s*=\s*(\d+)", text)
        self.expect(
            match is not None
            and int(match.group(1)) == int(self.catalog["schema_version"]),
            "catalog.dart_mirror.schema_version",
            f"{rel}: ComponentCatalog.schemaVersion must equal "
            f"{self.catalog['schema_version']}",
        )
        self.expect(
            "'DX-COMPONENT-CATALOG'" in text,
            "catalog.dart_mirror.spec",
            f"{rel}: the spec token must be repeated in Dart",
        )
        v1 = re.search(r"v1Types\s*=\s*<String>\s*\[(.*?)\]", text, re.S)
        listed = re.findall(r"'([a-z_]+)'", v1.group(1)) if v1 else []
        self.expect(
            listed == V1_FROZEN_TYPES,
            "catalog.dart_mirror.v1Types",
            f"{rel}: the frozen v1 list is a customer contract, expected "
            f"{V1_FROZEN_TYPES}, found {listed}",
        )

    def check_registry(self, comps: dict[str, dict]) -> None:
        rel = self.catalog["endpoints"]["flutter"]["registry"]
        text = self.read(rel)
        cases = re.findall(r"case\s+'([a-z_]+)'\s*:", text)
        self.expect(
            set(cases) == set(comps),
            "catalog.registry.cases",
            f"{rel}: not dispatched={fmt_set(set(comps) - set(cases))} "
            f"not catalogued={fmt_set(set(cases) - set(comps))}",
        )
        dupes = {c for c in cases if cases.count(c) > 1}
        self.expect(
            not dupes,
            "catalog.registry.no_duplicate_cases",
            f"{rel}: duplicate case labels {fmt_set(dupes)}",
        )
        self.expect(
            "catalogSchemaVersion = ComponentCatalog.schemaVersion"
            in " ".join(text.split()),
            "catalog.registry.exposes_schema_version",
            f"{rel}: BlockRegistry must publish catalogSchemaVersion from the mirror",
        )
        self.expect(
            "default:" in text,
            "catalog.registry.closed_default",
            f"{rel}: unknown types must fall through to `default:` (closed catalogue)",
        )

    def check_widget_files(self, comps: dict[str, dict]) -> None:
        referenced = set()
        for ctype, entry in sorted(comps.items()):
            rel = f"clients/flutter_shell/{entry['file']}"
            referenced.add(rel)
            try:
                text = self.read(rel)
            except GateError as exc:
                self.fail(f"catalog.widget_file.{ctype}", str(exc))
                continue
            self.expect(
                f"class {entry['widget']} extends" in text,
                f"catalog.widget_class.{ctype}",
                f"{rel} must declare `class {entry['widget']} extends`",
            )
        widgets_dir = self.catalog["endpoints"]["flutter"]["widgets_dir"]
        base = self.root / widgets_dir
        if not base.is_dir():
            raise GateError(f"missing {widgets_dir}")
        orphans = {
            str(path.relative_to(self.root))
            for path in base.glob("*.dart")
        } - referenced
        self.expect(
            not orphans,
            "catalog.no_orphan_widgets",
            f"widget file(s) unreachable from the catalogue: {fmt_set(orphans)} - "
            "catalogue them or delete them",
        )

    def check_miniprogram_mirror(self, comps: dict[str, dict]) -> None:
        mp = self.catalog["endpoints"]["miniprogram"]
        registry_rel = mp["registry"]
        text = self.read(registry_rel)
        match = re.search(r"catalogSchemaVersion\s*=\s*(\d+)", text)
        self.expect(
            match is not None
            and int(match.group(1)) == int(self.catalog["schema_version"]),
            "catalog.miniprogram.schema_version",
            f"{registry_rel}: catalogSchemaVersion must equal "
            f"{self.catalog['schema_version']}",
        )
        block = re.search(r"const known\s*=\s*\{(.*?)\n\}", text, re.S)
        known = set(re.findall(r"([a-z_]+):\s*true", block.group(1))) if block else set()
        self.expect(
            known == set(comps),
            "catalog.miniprogram.known",
            f"{registry_rel}: missing={fmt_set(set(comps) - known)} "
            f"extra={fmt_set(known - set(comps))}",
        )
        cap_block = re.search(
            r"const capabilityByType\s*=\s*\{(.*?)\n\}", text, re.S
        )
        caps = dict(
            re.findall(r"([a-z_]+):\s*'([a-z_]+)'", cap_block.group(1))
        ) if cap_block else {}
        want = {
            ctype: entry["capability"]
            for ctype, entry in comps.items()
            if entry.get("capability")
        }
        self.expect(
            caps == want,
            "catalog.miniprogram.capability_gating",
            f"{registry_rel}: capabilityByType={caps} catalogue={want}",
        )
        exports = text.split("module.exports", 1)[-1]
        self.expect(
            "catalogSchemaVersion" in exports and "capabilityByType" in exports,
            "catalog.miniprogram.exports",
            f"{registry_rel}: must export catalogSchemaVersion + capabilityByType",
        )
        renderer_rel = mp["renderer"]
        wxml = self.read(renderer_rel)
        rendered = set(re.findall(r"block\.type === '([a-z_]+)'", wxml))
        need = {
            ctype for ctype, entry in comps.items()
            if entry.get("mp") == "renderer"
        }
        marker = mp.get("renderer_marker", "block.type === '{type}'")
        self.expect(
            need <= rendered,
            "catalog.miniprogram.renderer_branches",
            f"{renderer_rel}: no `wx:elif` branch for {fmt_set(need - rendered)}; "
            f"the catalogue expects the marker \"{marker.format(type=fmt_set(need - rendered))}\"",
        )

    def check_capability_vocabulary(self, comps: dict[str, dict]) -> None:
        schema = self._load_json(MANIFEST_SCHEMA_REL)
        android = schema["platforms"]["android"]["fields"]["capabilities"]
        allowed = set(android.get("enum") or []) | set(
            (android.get("items") or {}).get("enum") or []
        ) | NON_MANIFEST_CAPABILITIES
        used = {
            entry["capability"] for entry in comps.values() if entry.get("capability")
        }
        self.expect(
            used <= allowed,
            "catalog.capabilities_match_packer_manifest",
            f"component capabilities outside the shell/manifest vocabulary "
            f"{fmt_set(allowed)}: {fmt_set(used - allowed)} ({MANIFEST_SCHEMA_REL})",
        )

    def check_fixture_component_types(self, comps: dict[str, dict]) -> None:
        for fixture in self.contract["fixtures"]:
            resources = {root["resource"] for root in fixture["roots"]}
            if "layout" not in resources:
                continue
            for end, rel in fixture["ends"].items():
                doc = self.load_fixture_end(fixture, end, quiet=True)
                if not isinstance(doc, dict):
                    continue
                for page_id, page in sorted((doc.get("pages") or {}).items()):
                    for idx, block in enumerate((page or {}).get("blocks") or []):
                        ctype = str((block or {}).get("type"))
                        label = f"catalog.{fixture['id']}:{end}:{page_id}[{idx}]"
                        if not self.expect(
                            ctype in comps,
                            label,
                            f"type {ctype!r} is outside the closed catalogue ({rel})",
                        ):
                            continue
                        since = int(comps[ctype].get("since") or 1)
                        if fixture["id"].endswith("_gov"):
                            self.expect(
                                since == 1,
                                f"{label}.v1_only",
                                f"{ctype} entered in v{since}; the gov fixture is "
                                f"served to 1.2.x shells that cannot render it ({rel})",
                            )
                for route_id, route in sorted((doc.get("routes") or {}).items()):
                    rtype = str((route or {}).get("type"))
                    self.expect(
                        rtype in comps,
                        f"catalog.{fixture['id']}:{end}:route:{route_id}",
                        f"route target {rtype!r} is not a catalogued component ({rel})",
                    )

    def run_catalog(self) -> None:
        comps = self.catalog_components()
        print(
            f"-- component catalogue v{self.catalog.get('schema_version')}: "
            f"{len(comps)} type(s), {len(V1_FROZEN_TYPES)} frozen since v1"
        )
        self.check_catalog_schema()
        self.expect(
            set(V1_FROZEN_TYPES) <= set(comps),
            "catalog.v1_types_still_ship",
            f"delivered types vanished: {fmt_set(set(V1_FROZEN_TYPES) - set(comps))}",
        )
        for ctype in V1_FROZEN_TYPES:
            entry = comps.get(ctype)
            if entry is None:
                continue
            self.expect(
                entry.get("since") == 1,
                f"catalog.{ctype}.since_is_1",
                f"a v1 type must keep since=1 or shipped apps lose its semantics "
                f"(found since={entry.get('since')!r})",
            )
        self.check_dart_mirror(comps)
        self.check_registry(comps)
        self.check_widget_files(comps)
        self.check_miniprogram_mirror(comps)
        self.check_capability_vocabulary(comps)
        self.check_fixture_component_types(comps)

    # --------------------------------------------------------------- fixtures
    def load_fixture_end(self, fixture: dict, end: str, quiet: bool = False) -> object:
        rel = (fixture.get("ends") or {}).get(end)
        if not rel:
            return None
        path = self.root / rel
        if not path.is_file():
            if not quiet:
                self.fail(f"fixture.{fixture['id']}.{end}", f"missing {rel}")
            return None
        text = path.read_text(encoding="utf-8")
        if rel.endswith(".js"):
            body = re.sub(r"^\s*//[^\n]*$", "", text, flags=re.M)
            body = re.sub(r"^\s*module\.exports\s*=\s*", "", body.strip())
            body = body.rstrip().rstrip(";")
        else:
            body = text
        try:
            return json.loads(body)
        except json.JSONDecodeError as exc:
            self.fail(f"fixture.{fixture['id']}.{end}", f"{rel}: {exc}")
            return None

    def walk(
        self,
        value: object,
        canon: str,
        found: dict[str, list[tuple[str, object]]],
        dyn: set[str],
        free: set[str],
    ) -> None:
        observed = json_type(value)
        found.setdefault(canon, [])
        if all(observed != got for got, _ in found[canon]):
            found[canon].append((observed, value))
        field = self.declared.get(canon)
        if canon in free or (field is not None and field.get("type") == "opaque"):
            return
        if isinstance(value, dict):
            for key, child in value.items():
                child_canon = f"{canon}.*" if canon in dyn else f"{canon}.{key}"
                self.walk(child, child_canon, found, dyn, free)
        elif isinstance(value, list):
            for child in value:
                self.walk(child, f"{canon}[]", found, dyn, free)

    def dyn_paths(self) -> set[str]:
        out = set()
        for name, res in (self.contract.get("resources") or {}).items():
            for dyn in res.get("dynamic_maps") or []:
                out.add(f"{name}.{dyn}")
        return out

    def free_paths(self) -> set[str]:
        out = set()
        for name, res in (self.contract.get("resources") or {}).items():
            for free in res.get("free_maps") or []:
                out.add(f"{name}.{free}")
        return out

    def notational(self, path: str) -> bool:
        """``X[]`` / ``X.*`` are notation, not fields, when X is declared."""
        if path.endswith("[]") or path.endswith(".*"):
            return path[:-2] in self.declared
        return False

    def check_fixture_contract(self, fixture: dict, end: str, doc: object) -> None:
        rel = fixture["ends"][end]
        scope = set(fixture.get("scope") or [])
        dyn, free = self.dyn_paths(), self.free_paths()
        for root in fixture["roots"]:
            resource = root["resource"]
            values = resolve_path(doc, root["path"])
            if values is None:
                self.fail(
                    f"fixture.{fixture['id']}.{end}.root:{root['path'] or '(doc)'}",
                    f"{rel} has no `{root['path'] or '(root)'}`, but the contract "
                    f"expects a {resource} payload there",
                )
                continue
            found: dict[str, list[tuple[str, object]]] = {}
            for value in values:
                self.walk(value, resource, found, dyn, free)
            for path, field in sorted(self.declared.items()):
                if field.get("resource") != resource:
                    continue
                fscope = field.get("scope")
                if fscope and fscope not in scope:
                    continue
                present = path in found
                if field.get("fixture_required") is False:
                    continue
                if field.get("presence") == "conditional" or not field.get("required"):
                    continue
                if not present:
                    self.fail(
                        f"fixture.{fixture['id']}.{end}:{path}",
                        f"required {resource} field is missing from {rel} "
                        f"(scope {fmt_set(scope)}) - add it to every end's fixture",
                    )
            for path, samples in sorted(found.items()):
                if "." not in path or self.notational(path):
                    # The resource root document itself and the `[]` / `*`
                    # element views are notation, not wire fields.
                    continue
                field = self.declared.get(path)
                if field is None:
                    self.fail(
                        f"fixture.{fixture['id']}.{end}:undeclared {path}",
                        f"{rel} carries `{without_resource(path)}` which "
                        f"{CONTRACT_REL} does not declare - add the field (and its "
                        "anchors for web/flutter/miniprogram) before shipping it",
                    )
                    continue
                for observed, value in samples:
                    self.expect(
                        type_ok(field, observed, value),
                        f"fixture.{fixture['id']}.{end}:type {path}",
                        f"{rel} sends {observed} `{json.dumps(value, ensure_ascii=False)[:40]}` "
                        f"for {path}, contract says {field['type']}"
                        + (f" one of {field['enum']}" if field.get("enum") else ""),
                    )

    def run_fixtures(self) -> None:
        print(f"-- fixtures ({len(self.contract['fixtures'])} resource(s), "
              f"flutter vs mini program must carry the same data)")
        for fixture in self.contract["fixtures"]:
            docs = {}
            for end in fixture["ends"]:
                doc = self.load_fixture_end(fixture, end)
                if doc is not None:
                    docs[end] = doc
            if len(docs) == len(fixture["ends"]) and len(docs) >= 2:
                ends = sorted(docs)
                for other in ends[1:]:
                    diffs = deep_diff(docs[ends[0]], docs[other])
                    self.expect(
                        not diffs,
                        f"fixture.{fixture['id']}.identical",
                        f"{ends[0]} vs {other} diverge: {diffs[:3]} "
                        f"({fixture['ends'][ends[0]]} / {fixture['ends'][other]})",
                    )
            else:
                continue
            for end in sorted(docs):
                self.check_fixture_contract(fixture, end, docs[end])

    # ------------------------------------------------------------------- dart
    def run_dart(self) -> None:
        """SDK-free Dart sanity: delimiters balance and imports resolve.

        This is not a compiler. It catches the two failures a text-only lane
        can actually introduce: a truncated/edited file, and a reference to a
        file that was never added to the commit.
        """
        base = self.root / "clients/flutter_shell"
        pubspec = self.read("clients/flutter_shell/pubspec.yaml")
        pkg = re.search(r"^name:\s*(\S+)", pubspec, re.M)
        name = pkg.group(1) if pkg else "dx_flutter_shell"
        deps = set(re.findall(r"^\s{2,}([A-Za-z_][\w.]*):", pubspec, re.M))
        files = sorted(
            list((base / "lib").rglob("*.dart")) + list((base / "test").rglob("*.dart"))
        )
        self.expect(bool(files), "dart.files_present", "no Dart sources found")
        for path in files:
            rel = str(path.relative_to(self.root))
            text = path.read_text(encoding="utf-8")
            code = strip_dart(text)
            stack: list[tuple[str, int]] = []
            pairs = {")": "(", "]": "[", "}": "{"}
            bad: str | None = None
            line = 1
            for ch in code:
                if ch == "\n":
                    line += 1
                elif ch in "([{":
                    stack.append((ch, line))
                elif ch in pairs:
                    if not stack or stack[-1][0] != pairs[ch]:
                        bad = f"stray {ch!r} at line {line}"
                        break
                    stack.pop()
            if bad is None and stack:
                opener, at = stack[0]
                bad = f"unclosed {opener!r} opened at line {at}"
            self.expect(bad is None, f"dart.balanced:{rel}", f"{rel}: {bad}")
            for match in re.finditer(r"^import\s+'([^']+)'", text, re.M):
                target = match.group(1)
                if target.startswith("package:"):
                    pkg_name, _, rest = target[len("package:"):].partition("/")
                    if pkg_name != name:
                        # Third party: the pubspec must declare the dependency,
                        # its sources are not in this repository.
                        self.expect(
                            pkg_name in deps,
                            f"dart.import_dependency:{rel}",
                            f"{rel} imports {target} but {pkg_name} is not a "
                            "dependency in clients/flutter_shell/pubspec.yaml",
                        )
                        continue
                    resolved = base / "lib" / rest
                elif target.startswith("dart:"):
                    continue
                else:
                    resolved = path.parent / target
                self.expect(
                    resolved.is_file(),
                    f"dart.import_resolves:{rel}",
                    f"{rel}: import '{target}' has no file at "
                    f"{resolved.relative_to(self.root)}",
                )
        for rel in self.catalog["endpoints"]["flutter"].get("tests") or []:
            self.expect(
                (self.root / rel).is_file(),
                f"dart.catalogued_test:{rel}",
                f"the catalogue promises test {rel} but it is missing",
            )
        for rel in [MIRROR_RELS["flutter"]]:
            self.expect(
                (self.root / rel).is_file(),
                f"dart.mirror_present:{rel}",
                f"run isomorph_check.py mirror --write",
            )

    # ------------------------------------------------------------- mini program
    def run_miniprogram(self) -> None:
        """Devtools-free sanity for the mini-program shell.

        Checks the wiring a text edit breaks most often: page registrations,
        `require()` targets, fixture files that must stay valid JSON, and
        unclosed tags in the renderer.
        """
        base = self.root / "clients/wechat-miniprogram"
        if not base.is_dir():
            raise GateError("missing clients/wechat-miniprogram")
        app_json = base / "app.json"
        try:
            app = json.loads(app_json.read_text(encoding="utf-8"))
        except json.JSONDecodeError as exc:
            self.fail("mp.app_json", f"app.json: {exc}")
            return
        self.ok("mp.app_json parses")
        for page in app.get("pages") or []:
            for suffix in ("js", "wxml"):
                self.expect(
                    (base / f"{page}.{suffix}").is_file(),
                    f"mp.page:{page}.{suffix}",
                    f"app.json registers {page} but clients/wechat-miniprogram/"
                    f"{page}.{suffix} is missing",
                )
        for path in sorted(base.rglob("*.js")):
            rel = str(path.relative_to(self.root))
            text = path.read_text(encoding="utf-8")
            for match in re.finditer(r"""require\(\s*'([^']+)'""", text):
                target = match.group(1)
                if target.startswith("/"):
                    resolved = base / target.lstrip("/")
                else:
                    resolved = path.parent / target
                found = (
                    resolved.is_file()
                    or resolved.with_suffix(".js").is_file()
                    or (resolved / "index.js").is_file()
                )
                self.expect(
                    found,
                    f"mp.require:{rel}",
                    f"{rel}: require('{target}') resolves to no file",
                )
        self.expect(
            "module.exports" in (base / "utils" / "dxep.js").read_text(encoding="utf-8"),
            "mp.dxep_exports",
            "utils/dxep.js must keep exporting its helpers",
        )
        for path in sorted((base / "fixtures").glob("*.js")):
            rel = str(path.relative_to(self.root))
            fixture = next(
                (f for f in self.contract["fixtures"]
                 if f["ends"].get("miniprogram") == rel),
                None,
            )
            if fixture is None:
                self.fail(
                    f"mp.fixture_registered:{rel}",
                    "fixture file is not declared in "
                    f"{CONTRACT_REL} ['fixtures'] - register it or delete it",
                )
                continue
            self.ok(f"mp.fixture_registered:{rel}")
        wxml = (base / "pages" / "index" / "index.wxml").read_text(encoding="utf-8")
        for tag in ("view", "text", "image", "block", "scroll-view"):
            opens = len(re.findall(rf"<{tag}(?=[\s>])", wxml))
            selfclosing = len(re.findall(rf"<{tag}\b[^>]*/>", wxml))
            closes = len(re.findall(rf"</{tag}>", wxml))
            self.expect(
                opens - selfclosing == closes,
                f"mp.wxml_tags:{tag}",
                f"pages/index/index.wxml: <{tag}> opens {opens} "
                f"({selfclosing} self-closed) but </{tag}> appears {closes}",
            )

    # ----------------------------------------------------------------- fields
    def end_files(self, end: str, resource: str) -> list[str]:
        spec = self.contract["ends"][end]
        if end == "server":
            return list((spec.get("resources") or {}).get(resource) or [])
        files = list(spec.get("files") or [])
        mirror = spec.get("mirror")
        if mirror and mirror not in files:
            files.insert(0, mirror)
        return files

    def anchor_pattern(self, end: str, field: dict) -> str | None:
        anchors = field.get("anchors") or {}
        if end in anchors:
            return anchors[end]
        default = (self.contract["ends"][end] or {}).get("default_anchor")
        if not default:
            return None
        leaf = without_resource(field["path"]).split(".")[-1]
        # Path segments carry `[]` / `*` notation, so they must be escaped
        # before they are dropped into a regex; hand-written `anchors` stay
        # verbatim.
        return default.format(
            path=re.escape(field["path"]), leaf=re.escape(leaf)
        )

    def check_mirror_file(self, end: str) -> None:
        rel = MIRROR_RELS[end]
        try:
            text = self.read(rel)
        except GateError as exc:
            self.fail(
                f"fields.mirror.{end}",
                f"{exc} (fix: python3 tools/clients/isomorph_check.py mirror --write)",
            )
            return
        mirror = parse_mirror_map(text)
        want = {f["path"]: f["type"] for f in self.contract["fields"]}
        self.expect(
            mirror == want,
            f"fields.mirror.{end}",
            f"{rel} is out of sync with {CONTRACT_REL}: "
            f"missing={fmt_set(set(want) - set(mirror))} "
            f"extra={fmt_set(set(mirror) - set(want))} "
            f"retyped={fmt_set({p for p in want if p in mirror and want[p] != mirror[p]})}",
        )
        version = self.contract["schema_version"]
        self.expect(
            re.search(
                rf"(dxepFieldContractSchemaVersion|FIELD_CONTRACT_SCHEMA_VERSION)\s*=\s*{version}\b",
                text,
            )
            is not None,
            f"fields.mirror.{end}.schema_version",
            f"{rel} must state schema_version {version}",
        )

    def run_fields(self) -> None:
        fields = self.contract["fields"]
        print(
            f"-- field contract v{self.contract['schema_version']}: {len(fields)} "
            f"field(s) x ends {', '.join(self.contract['ends'])}"
        )
        for field in fields:
            path = field["path"]
            resource = field.get("resource")
            self.expect(
                resource in self.contract["resources"],
                f"fields.resource:{path}",
                f"{CONTRACT_REL}: {path} points at unknown resource {resource!r}",
            )
            self.expect(
                field.get("type") in KNOWN_TYPES,
                f"fields.type:{path}",
                f"{CONTRACT_REL}: {path} has unknown type {field.get('type')!r}",
            )
            self.expect(
                field.get("layer") in ("envelope", "L1", "L2"),
                f"fields.layer:{path}",
                f"{CONTRACT_REL}: {path} has layer {field.get('layer')!r}",
            )
            ends = field.get("ends") or []
            self.expect(
                bool(ends),
                f"fields.ends:{path}",
                f"{CONTRACT_REL}: {path} is required by no end",
            )
            for end in ends:
                if end not in self.contract["ends"]:
                    self.fail(f"fields.end:{path}", f"unknown end {end!r}")
                    continue
                pattern = self.anchor_pattern(end, field)
                files = self.end_files(end, resource or "")
                if pattern is None or not files:
                    self.fail(
                        f"fields.anchor:{path}:{end}",
                        f"end={end} has no anchorable files for {resource}"
                        if not files
                        else f"end={end} has no default pattern; add "
                        f"anchors[\"{end}\"] to {CONTRACT_REL} for {path}",
                    )
                    continue
                present = self.existing(files)
                if not present:
                    self.fail(
                        f"fields.end_files:{path}:{end}",
                        f"end={end} declares {files} but none of them exists in "
                        "this checkout, so the anchor cannot be proved - restore "
                        f"the file or fix {CONTRACT_REL} ends.{end}",
                    )
                    continue
                hit = self.grep(files, pattern)
                if hit:
                    self.ok(f"{path} [{end}] -> {hit}")
                else:
                    self.fail(
                        f"fields.anchor:{path}:{end}",
                        f"end={end}: /{pattern}/ not found in {present} - this "
                        f"end never names `{without_resource(path)}` (add it, or "
                        f"drop {end!r} from the field's ends)",
                    )
        for end in ("flutter", "miniprogram"):
            self.check_mirror_file(end)
        web = sum(1 for f in fields if "web" in (f.get("ends") or []))
        print(f"   web anchors: {web} field(s) via dx_portal (read-only)")


def parse_mirror_map(text: str) -> dict[str, str]:
    match = re.search(
        r"(?:dxepFieldContract|fieldContract)\s*=\s*(?:<[^>]*>\s*)?\{(.*?)\n\}",
        text,
        re.S,
    )
    if not match:
        return {}
    return dict(re.findall(r"'([^']+)'\s*:\s*'([^']*)'", match.group(1)))


def render_mirror(end: str, contract: dict) -> str:
    fields = contract["fields"]
    body = "\n".join(f"  '{f['path']}': '{f['type']}'," for f in fields)
    if end == "flutter":
        return (
            "/// Generated mirror of `clients/field-contract.json`"
            " (DX-FIELD-CONTRACT).\n"
            "///\n"
            "/// Keys are canonical wire paths (`<resource>.<dotted.path>`, `[]` for\n"
            "/// list elements, `*` for dynamic map keys); values are contract types.\n"
            "/// This is the copy the shell can consult at runtime; the JSON stays the\n"
            "/// source of truth. Both directions are gated:\n"
            "///   * flutter test test/field_contract_test.dart\n"
            "///   * python3 tools/clients/isomorph_check.py fields\n"
            "/// Regenerate with:\n"
            "///   python3 tools/clients/isomorph_check.py mirror --write\n"
            "library;\n\n"
            "const int dxepFieldContractSchemaVersion = "
            f"{contract['schema_version']};\n"
            "const String dxepFieldContractSpec = "
            f"'{contract['spec']}';\n\n"
            "const Map<String, String> dxepFieldContract = <String, String>{\n"
            f"{body}\n}};\n\n"
            "/// Contract type of a canonical path, or null when it is undeclared.\n"
            "String? dxepFieldType(String path) => dxepFieldContract[path];\n\n"
            "/// Whether a path exists in the frozen cross-end contract.\n"
            "bool dxepFieldExists(String path) => dxepFieldContract.containsKey(path);\n"
        )
    return (
        "// DXEP field contract mirror - DO NOT EDIT BY HAND.\n"
        "// Source of truth: clients/field-contract.json\n"
        "// Regenerate: python3 tools/clients/isomorph_check.py mirror --write\n"
        "// Gated by:    scripts/ci/clients-isomorph-smoke.sh (no SDK, no network)\n"
        f"// Fields: {len(fields)}\n"
        "var FIELD_CONTRACT_SCHEMA_VERSION = "
        f"{contract['schema_version']};\n"
        f"var FIELD_CONTRACT_SPEC = '{contract['spec']}';\n\n"
        "var fieldContract = {\n"
        f"{body}\n}};\n\n"
        "function fieldType(path) {\n"
        "  return Object.prototype.hasOwnProperty.call(fieldContract, path)\n"
        "    ? fieldContract[path]\n"
        "    : null;\n"
        "}\n\n"
        "function hasField(path) {\n"
        "  return Object.prototype.hasOwnProperty.call(fieldContract, path);\n"
        "}\n\n"
        "module.exports = {\n"
        "  FIELD_CONTRACT_SCHEMA_VERSION: FIELD_CONTRACT_SCHEMA_VERSION,\n"
        "  FIELD_CONTRACT_SPEC: FIELD_CONTRACT_SPEC,\n"
        "  fieldContract: fieldContract,\n"
        "  fieldType: fieldType,\n"
        "  hasField: hasField\n"
        "};\n"
    )


def run_mirror(root: Path, contract: dict, write: bool) -> int:
    failed = 0
    for end, rel in MIRROR_RELS.items():
        want = render_mirror(end, contract)
        path = root / rel
        if write:
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(want, encoding="utf-8")
            print(f"wrote {rel}")
            continue
        current = path.read_text(encoding="utf-8") if path.is_file() else ""
        if current == want:
            print(f"  ok   {rel} is current")
        else:
            print(f"FAIL {rel} is stale (run: isomorph_check.py mirror --write)")
            failed += 1
    return failed


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        description="Offline three-end isomorphism gate for DXEP clients."
    )
    parser.add_argument(
        "mode",
        nargs="?",
        default="all",
        choices=("catalog", "fields", "fixtures", "dart", "mp", "mirror", "all"),
    )
    parser.add_argument("--root", default=None, help="repository root override")
    parser.add_argument(
        "--write", action="store_true", help="mirror mode: rewrite both mirrors"
    )
    parser.add_argument("-v", "--verbose", action="store_true")
    args = parser.parse_args(argv)

    try:
        root = Path(args.root).resolve() if args.root else find_root()
    except GateError as exc:
        print(f"ERROR {exc}", file=sys.stderr)
        return 2
    if args.mode == "mirror":
        try:
            contract = json.loads((root / CONTRACT_REL).read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError) as exc:
            print(f"ERROR {CONTRACT_REL}: {exc}", file=sys.stderr)
            return 2
        return 1 if run_mirror(root, contract, args.write) else 0

    try:
        gate = Gate(root, verbose=args.verbose)
    except GateError as exc:
        print(f"ERROR {exc}", file=sys.stderr)
        return 2

    modes = (
        ["catalog", "fields", "fixtures", "dart", "mp"]
        if args.mode == "all"
        else [args.mode]
    )
    for mode in modes:
        try:
            getattr(gate, MODE_METHOD[mode])()
        except GateError as exc:
            gate.fail(mode, str(exc))
    passed = gate.checks - len(gate.failures)
    print(
        f"{'PASS' if not gate.failures else 'FAIL'} clients-isomorph"
        f"[{'+'.join(modes)}] {passed}/{gate.checks} check(s) passed"
    )
    return 1 if gate.failures else 0


if __name__ == "__main__":
    sys.exit(main())
