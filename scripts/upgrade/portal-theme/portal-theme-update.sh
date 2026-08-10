#!/usr/bin/env bash
# 用法: cd /home/wwwroot/drupalX/upgrade/portal-theme && ./portal-theme-update.sh
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
TAR_NAME="portal-theme-deploy-latest.tar.gz"
DIR_NAME="portal-theme-deploy-latest"

if [[ "$(basename "$HERE")" == "$DIR_NAME" ]]; then
  UPGRADE_DIR="$(cd "$HERE/.." && pwd)"
  PKG="$HERE"
else
  UPGRADE_DIR="$HERE"
  cd "$UPGRADE_DIR"
  if [[ -f "$TAR_NAME" ]]; then
    echo "==> 解压并覆盖 $DIR_NAME （来自 $TAR_NAME）"
    rm -rf "$DIR_NAME" "${DIR_NAME}.extract"
    mkdir -p "${DIR_NAME}.extract"
    tar -xzf "$TAR_NAME" -C "${DIR_NAME}.extract"
    if [[ -d "${DIR_NAME}.extract/$DIR_NAME" ]]; then
      mv "${DIR_NAME}.extract/$DIR_NAME" "$DIR_NAME"
    else
      INNER="$(find "${DIR_NAME}.extract" -mindepth 1 -maxdepth 1 -type d | head -1 || true)"
      [[ -n "$INNER" ]] || { echo "ERROR: empty tar" >&2; exit 1; }
      mv "$INNER" "$DIR_NAME"
    fi
    rm -rf "${DIR_NAME}.extract"
  fi
  [[ -d "$DIR_NAME/scripts" ]] || { echo "ERROR: missing $DIR_NAME" >&2; exit 1; }
  PKG="$UPGRADE_DIR/$DIR_NAME"
fi

[[ -f "$PKG/scripts/deploy_from_upgrade.sh" ]] || { echo "ERROR: missing deploy script" >&2; exit 1; }
[[ -f "$PKG/portal-theme-update.sh" ]] && cp -f "$PKG/portal-theme-update.sh" "$UPGRADE_DIR/portal-theme-update.sh"
chmod +x "$UPGRADE_DIR/portal-theme-update.sh" "$PKG/scripts/deploy_from_upgrade.sh"
echo "==> 部署包: $PKG"
exec bash "$PKG/scripts/deploy_from_upgrade.sh"
