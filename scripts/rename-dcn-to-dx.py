#!/usr/bin/env python3
"""Rename all dcn_/DCN_ project identifiers to dx_/DX_ (filesystem + file contents)."""
from __future__ import annotations

import os
import re
import shutil
from pathlib import Path

ROOT = Path("/home/wwwroot/drupalX")

SKIP_DIR_NAMES = {
    ".git", "vendor", "core", "contrib", "dumps", "upgrade", "node_modules",
    "gavias_kiamo", "gavias_kiamo_custom", "gavias_sliderlayer", "gavias_view",
    "gaviasthemer", "gva_blockbuilder", "features_kiamo",
}

# Longest-first replacements for content.
REPLACEMENTS: list[tuple[str, str]] = [
    ("dcn_portal_theme", "dx_portal_theme"),
    ("dcn_tenant_portal", "dx_tenant_portal"),
    ("dcn_install_request", "dx_install_request"),
    ("dcn_revenue_share", "dx_revenue_share"),
    ("dcn_app_package", "dx_app_package"),
    ("dcn_ai_gateway", "dx_ai_gateway"),
    ("dcn_ai_usage", "dx_ai_usage"),
    ("dcn_ai_stack", "dx_ai_stack"),
    ("dcn_ai_chat", "dx_ai_chat"),
    ("dcn_customer_service_chat", "dx_customer_service_chat"),
    ("dcn_appstore", "dx_appstore"),
    ("dcn_platform", "dx_platform"),
    ("dcn_tenant", "dx_tenant"),
    ("dcn_portal", "dx_portal"),
    ("dcn_admin", "dx_admin"),
    ("dcn_license", "dx_license"),
    ("Drupal\\dcn_", "Drupal\\dx_"),
    ("Drupal\\\\dcn_", "Drupal\\\\dx_"),
    ("dcn-ai-chat", "dx-ai-chat"),
    ("dcn-ai-page", "dx-ai-page"),
    ("dcn-portal-", "dx-portal-"),
    ("dcnAiChat", "dxAiChat"),
    ("--dcn-", "--dx-"),
    (".dcn-", ".dx-"),
    ("DCN_AI_", "DX_AI_"),
    ("DCN_DB_", "DX_DB_"),
    ("DCN_ADMIN_", "DX_ADMIN_"),
    ("DCN_PLATFORM_", "DX_PLATFORM_"),
    ("DCN_TENANT_", "DX_TENANT_"),
    ("DCN_PROJECT", "DX_PROJECT"),
    ("dcn_load_env", "dx_load_env"),
    ("dcn_resolve", "dx_resolve"),
    ("/admin/dcn/", "/admin/dx/"),
    ("administer dcn ", "administer dx "),
    ("access dcn ", "access dx "),
    ("DCN Customer", "DX Customer"),
    ("drush dcn:", "drush dx:"),
    ("@command dcn:", "@command dx:"),
    ("dcn:tenant", "dx:tenant"),
    ("dcn:ai-", "dx:ai-"),
    ("dcn:appstore", "dx:appstore"),
    # Catch-all for remaining machine names / prefixes.
    ("dcn_", "dx_"),
    ("DCN_", "DX_"),
]

TEXT_SUFFIXES = {
    ".php", ".yml", ".yaml", ".twig", ".js", ".css", ".md", ".sh", ".json",
    ".info", ".txt", ".example", ".html", ".svg", ".module", ".install",
    ".theme", ".profile", ".inc",
}


def should_skip(path: Path) -> bool:
    rel = path.as_posix()
    if "/sites/" in rel and path.name in {"settings.php", "settings.local.php", "services.yml"}:
        return True
    if path.name in {".env", ".env.local", ".env.active_host"}:
        return True
    parts = set(path.parts)
    if parts & SKIP_DIR_NAMES:
        # Allow paths under web/modules/custom/dcn_* and themes/custom/dcn_*
        custom = "web" in path.parts and (
            ("modules" in path.parts and "custom" in path.parts)
            or ("themes" in path.parts and "custom" in path.parts)
        )
        recipes = "recipes" in path.parts
        if not custom and not recipes and "docs" not in path.parts and "scripts" not in path.parts:
            if "vendor" in path.parts or "core" in path.parts or "contrib" in path.parts:
                return True
            if "dumps" in path.parts or "upgrade" in path.parts or ".git" in path.parts:
                return True
            if any(g in path.parts for g in (
                "gavias_kiamo", "gavias_kiamo_custom", "gavias_sliderlayer",
                "gavias_view", "gaviasthemer", "gva_blockbuilder", "features_kiamo",
            )):
                return True
    return False


def replace_content(text: str) -> str:
    for old, new in REPLACEMENTS:
        text = text.replace(old, new)
    return text


def iter_files(root: Path):
    for dirpath, dirnames, filenames in os.walk(root):
        # Prune heavy/irrelevant trees early.
        dirnames[:] = [
            d for d in dirnames
            if d not in {".git", "vendor", "dumps", "upgrade", "node_modules"}
            and not (Path(dirpath).name == "web" and d == "core")
            and not (Path(dirpath).name == "modules" and d == "contrib")
            and not (Path(dirpath).name == "themes" and d == "contrib")
            and d not in {
                "gavias_kiamo", "gavias_kiamo_custom", "gavias_sliderlayer",
                "gavias_view", "gaviasthemer", "gva_blockbuilder", "features_kiamo",
            }
        ]
        for name in filenames:
            p = Path(dirpath) / name
            if should_skip(p):
                continue
            yield p


def rename_path_component(name: str) -> str:
    return replace_content(name)


def main() -> None:
    # 1) Content rewrite
    changed = 0
    for path in iter_files(ROOT):
        if path.suffix.lower() not in TEXT_SUFFIXES and path.name not in {
            "Dockerfile", "Makefile", ".env", ".env.example", ".env.active_host",
        }:
            # Still process extensionless scripts? skip binaries
            if path.suffix:
                continue
        try:
            raw = path.read_bytes()
        except OSError:
            continue
        if b"\0" in raw[:1024]:
            continue
        try:
            text = raw.decode("utf-8")
        except UnicodeDecodeError:
            continue
        new = replace_content(text)
        if new != text:
            path.write_text(new, encoding="utf-8")
            changed += 1
            print(f"edit  {path.relative_to(ROOT)}")

    # 2) Rename files (deepest first)
    files = sorted(iter_files(ROOT), key=lambda p: len(p.parts), reverse=True)
    for path in files:
        new_name = rename_path_component(path.name)
        if new_name != path.name:
            dest = path.with_name(new_name)
            if dest.exists():
                print(f"skip file exists {dest}")
                continue
            path.rename(dest)
            print(f"mv    {path.relative_to(ROOT)} -> {dest.name}")

    # 3) Rename directories under custom modules/themes/recipes (deepest first)
    dirs: list[Path] = []
    for base in (
        ROOT / "web/modules/custom",
        ROOT / "web/themes/custom",
        ROOT / "recipes",
    ):
        if not base.exists():
            continue
        for dirpath, dirnames, _ in os.walk(base, topdown=False):
            for d in dirnames:
                dirs.append(Path(dirpath) / d)
    # Also scripts/templates with dcn in dirname if any
    for dirpath, dirnames, _ in os.walk(ROOT / "scripts", topdown=False):
        for d in dirnames:
            if "dcn" in d.lower():
                dirs.append(Path(dirpath) / d)

    dirs = sorted(set(dirs), key=lambda p: len(p.parts), reverse=True)
    for path in dirs:
        new_name = rename_path_component(path.name)
        if new_name == path.name:
            continue
        dest = path.with_name(new_name)
        if dest.exists():
            # Merge: move children then remove
            for child in path.iterdir():
                target = dest / child.name
                if child.is_dir():
                    shutil.copytree(child, target, dirs_exist_ok=True)
                    shutil.rmtree(child)
                else:
                    shutil.copy2(child, target)
                    child.unlink()
            path.rmdir()
            print(f"merge {path} -> {dest}")
        else:
            path.rename(dest)
            print(f"mvdir {path.relative_to(ROOT)} -> {dest.name}")

    print(f"\nDone. Content files edited: {changed}")


if __name__ == "__main__":
    main()
