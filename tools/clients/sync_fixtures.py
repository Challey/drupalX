#!/usr/bin/env python3
"""Copy DXEP fixtures from the Flutter end to the mini-program end.

Mini programs cannot read the Flutter asset bundle, so every L1/L2 fixture is
stored twice: `clients/flutter_shell/assets/fixtures/*.json` (source, written by
hand) and `clients/wechat-miniprogram/fixtures/*.js` (mirror, generated).
`tools/clients/isomorph_check.py fixtures` fails when the two copies diverge;
this tool removes the manual step so a field added once lands on both ends.

Usage
  python3 tools/clients/sync_fixtures.py --write   # regenerate the mirrors
  python3 tools/clients/sync_fixtures.py --check   # CI dry run, exit 1 on drift
"""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

CONTRACT_REL = "clients/field-contract.json"
SOURCE_END = "flutter"


def header(source_rel: str) -> str:
    return (
        "// DXEP fixture mirror - DO NOT EDIT BY HAND.\n"
        f"// Source: {source_rel}\n"
        "// Regenerate: python3 tools/clients/sync_fixtures.py --write\n"
        "// Verified by:  python3 tools/clients/isomorph_check.py fixtures\n"
    )


def render(source_rel: str, doc: object) -> str:
    body = json.dumps(doc, ensure_ascii=False, indent=2)
    return f"{header(source_rel)}module.exports = {body};\n"


def load_source(root: Path, rel: str) -> object:
    path = root / rel
    if not path.is_file():
        raise SystemExit(f"ERROR missing source fixture {rel}")
    return json.loads(path.read_text(encoding="utf-8"))


def find_root() -> Path:
    here = Path(__file__).resolve()
    for cand in here.parents:
        if (cand / CONTRACT_REL).is_file():
            return cand
    raise SystemExit("ERROR repository root not found - pass --root")


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        description="Regenerate mini-program fixtures from the Flutter fixtures."
    )
    parser.add_argument("--root", default=None)
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument("--write", action="store_true", help="overwrite the mirrors")
    group.add_argument("--check", action="store_true", help="only report drift")
    args = parser.parse_args(argv)

    root = Path(args.root).resolve() if args.root else find_root()
    try:
        contract = json.loads((root / CONTRACT_REL).read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        print(f"ERROR {CONTRACT_REL}: {exc}", file=sys.stderr)
        return 2

    drift = 0
    for fixture in contract.get("fixtures") or []:
        ends = fixture.get("ends") or {}
        src_rel = ends.get(SOURCE_END)
        if not src_rel:
            print(f"skip {fixture.get('id')}: no {SOURCE_END} end declared")
            continue
        doc = load_source(root, src_rel)
        for end, rel in sorted(ends.items()):
            if end == SOURCE_END:
                continue
            want = render(src_rel, doc)
            path = root / rel
            current = path.read_text(encoding="utf-8") if path.is_file() else None
            if current == want:
                print(f"  ok   {rel}")
                continue
            if args.write:
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text(want, encoding="utf-8")
                print(f"wrote  {rel} (from {src_rel})")
            else:
                print(f"DRIFT  {rel} != {src_rel} "
                      "(run: python3 tools/clients/sync_fixtures.py --write)")
                drift += 1
    if args.check and drift:
        print(f"FAIL {drift} fixture mirror(s) out of sync")
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
