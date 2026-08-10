#!/usr/bin/env bash
# DrupalX · Gavias/Kiamo 一键部署入口（长期放在 upgrade/gavias/）
#
# 用法（与 Topstar D10→11 / car-hailing 相同）：
#   cd /home/wwwroot/drupalX/upgrade/gavias && ./gavias-update.sh
#
# 有 tar 时一律重新解压覆盖 gavias-deploy-latest/（避免一直用旧目录）。
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
TAR_NAME="gavias-deploy-latest.tar.gz"
DIR_NAME="gavias-deploy-latest"

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
      if [[ -z "${INNER}" ]]; then
        echo "ERROR: 压缩包内容无效" >&2
        rm -rf "${DIR_NAME}.extract"
        exit 1
      fi
      mv "$INNER" "$DIR_NAME"
    fi
    rm -rf "${DIR_NAME}.extract"
  fi

  if [[ ! -d "$DIR_NAME/scripts" ]]; then
    echo "ERROR: 缺少 $UPGRADE_DIR/$DIR_NAME （请上传 tar.gz 或解压目录）" >&2
    exit 1
  fi
  PKG="$UPGRADE_DIR/$DIR_NAME"
fi

if [[ ! -f "$PKG/scripts/deploy_from_upgrade.sh" ]]; then
  echo "ERROR: 找不到 $PKG/scripts/deploy_from_upgrade.sh" >&2
  exit 1
fi

# 刷新本目录长期入口
if [[ -f "$PKG/gavias-update.sh" ]]; then
  cp -f "$PKG/gavias-update.sh" "$UPGRADE_DIR/gavias-update.sh"
fi
chmod +x "$UPGRADE_DIR/gavias-update.sh" "$PKG/scripts/deploy_from_upgrade.sh"

echo "==> 部署包: $PKG"
if [[ -f "$PKG/docs/DEPLOY.md" ]]; then
  head -n 5 "$PKG/docs/DEPLOY.md" || true
fi
exec bash "$PKG/scripts/deploy_from_upgrade.sh"
