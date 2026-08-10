#!/usr/bin/env bash
# Build Gavias/Kiamo hotfix package into upgrade/gavias/ (Topstar D10→11 / car-hailing style).
#
# Output (always):
#   /home/challey/staging/drupalX/gavias/gavias-deploy-latest.tar.gz
#   /home/wwwroot/drupalX/upgrade/gavias/gavias-deploy-latest.tar.gz  (local drop)
#   /home/wwwroot/drupalX/upgrade/gavias/gavias-update.sh
#
# Usage:
#   /home/wwwroot/drupalX/scripts/upgrade/gavias/build_package.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
DEFAULT_OUT="/home/challey/staging/drupalX/gavias"
FALLBACK_OUT="$ROOT/dumps/gavias-pack"
if [[ -n "${PACK_OUT_DIR:-}" ]]; then
  OUT_DIR="$PACK_OUT_DIR"
elif mkdir -p "$DEFAULT_OUT" 2>/dev/null && [[ -w "$DEFAULT_OUT" ]]; then
  OUT_DIR="$DEFAULT_OUT"
else
  OUT_DIR="$FALLBACK_OUT"
  echo "NOTE: $DEFAULT_OUT not writable — using $OUT_DIR"
fi
ARCHIVE_DIR="${PACK_ARCHIVE_DIR:-$OUT_DIR/archive}"
UPGRADE_DIR="${DRUPALX_UPGRADE_GAVIAS:-$ROOT/upgrade/gavias}"
STAMP="$(date +%Y%m%d_%H%M%S)"
PKG_NAME="gavias-deploy-${STAMP}"
LATEST_NAME="gavias-deploy-latest"

MODULES=(
  gva_blockbuilder
  gavias_kiamo_custom
  gavias_sliderlayer
  gavias_view
  gaviasthemer
  features_kiamo
)
THEME=gavias_kiamo

for m in "${MODULES[@]}"; do
  if [[ ! -d "$ROOT/web/modules/custom/$m" ]]; then
    echo "ERROR: missing module $ROOT/web/modules/custom/$m" >&2
    exit 1
  fi
done
if [[ ! -d "$ROOT/web/themes/custom/$THEME" ]]; then
  echo "ERROR: missing theme $ROOT/web/themes/custom/$THEME" >&2
  exit 1
fi

STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT
PKG_DIR="$STAGE/$LATEST_NAME"

echo "==> Building Gavias package from $ROOT"
mkdir -p "$PKG_DIR"/{web/modules/custom,web/themes/custom,scripts,docs}

RSYNC_OPTS=(
  -a --delete
  --no-owner --no-group
  --exclude '.git'
  --exclude '._*'
  --exclude '.DS_Store'
)
for m in "${MODULES[@]}"; do
  rsync "${RSYNC_OPTS[@]}" \
    "$ROOT/web/modules/custom/$m/" "$PKG_DIR/web/modules/custom/$m/"
done
rsync "${RSYNC_OPTS[@]}" \
  "$ROOT/web/themes/custom/$THEME/" "$PKG_DIR/web/themes/custom/$THEME/"

cp -f "$SCRIPT_DIR/deploy_from_upgrade.sh" "$PKG_DIR/scripts/deploy_from_upgrade.sh"
cp -f "$SCRIPT_DIR/gavias-update.sh" "$PKG_DIR/gavias-update.sh"
chmod +x "$PKG_DIR/scripts/deploy_from_upgrade.sh" "$PKG_DIR/gavias-update.sh"

cat > "$PKG_DIR/docs/DEPLOY.md" <<'EOF'
# DrupalX · Gavias/Kiamo 补丁包（一键部署）

与 Topstar D10→11 / car-hailing 相同模式：上传到 `upgrade/gavias/`，一条命令落盘。

## 打包（开发机）

```bash
/home/wwwroot/drupalX/scripts/upgrade/gavias/build_package.sh
```

产物：

- `/home/challey/staging/drupalX/gavias/gavias-deploy-latest.tar.gz`
- 本机落包：`/home/wwwroot/drupalX/upgrade/gavias/`

## 生产一键

上传至少：

1. `gavias-deploy-latest.tar.gz`
2. `gavias-update.sh`（首次；之后包内会刷新）

到：`/home/wwwroot/drupalX/upgrade/gavias/`

然后：

```bash
cd /home/wwwroot/drupalX/upgrade/gavias && ./gavias-update.sh
```

会：

- 备份现有 Gavias 模块/主题到 `upgrade/gavias/backups/`
- rsync 模块 + `gavias_kiamo` 主题到 `web/`
- `drush cr`
- 校验关键 `.module` 文件是否存在

**不会**覆盖 `.env` / `settings.php` / `vendor/` / 用户上传文件 / 其它 custom 模块。

## 包含内容

- modules: `gva_blockbuilder`, `gavias_kiamo_custom`, `gavias_sliderlayer`, `gavias_view`, `gaviasthemer`, `features_kiamo`
- theme: `gavias_kiamo`

全量代码仍用：`cd /home/wwwroot/drupalX/upgrade && ./drupalX-update.sh`
EOF

(
  cd "$PKG_DIR"
  find . -type f ! -path './docs/FILE-LIST.txt' ! -path './docs/SHA256SUMS.txt' \
    | sed 's|^\./||' | sort > docs/FILE-LIST.txt
  if command -v sha256sum >/dev/null 2>&1; then
    find . -type f ! -name 'SHA256SUMS.txt' ! -name 'FILE-LIST.txt' -print0 \
      | sort -z | xargs -0 sha256sum > docs/SHA256SUMS.txt
  fi
)

mkdir -p "$OUT_DIR" "$ARCHIVE_DIR" "$UPGRADE_DIR"
chmod -R u+w "$OUT_DIR/$LATEST_NAME" 2>/dev/null || true
rm -rf "$OUT_DIR/$LATEST_NAME"
cp -a "$PKG_DIR" "$OUT_DIR/$LATEST_NAME"
tar -C "$STAGE" -czf "$OUT_DIR/${LATEST_NAME}.tar.gz" "$LATEST_NAME"
cp -f "$OUT_DIR/${LATEST_NAME}.tar.gz" "$ARCHIVE_DIR/gavias-deploy-${STAMP}.tar.gz"
cp -f "$SCRIPT_DIR/gavias-update.sh" "$OUT_DIR/gavias-update.sh"
chmod +x "$OUT_DIR/gavias-update.sh"

# Local project drop (same layout as production)
cp -f "$OUT_DIR/${LATEST_NAME}.tar.gz" "$UPGRADE_DIR/${LATEST_NAME}.tar.gz"
cp -f "$SCRIPT_DIR/gavias-update.sh" "$UPGRADE_DIR/gavias-update.sh"
chmod +x "$UPGRADE_DIR/gavias-update.sh"
rm -rf "$UPGRADE_DIR/$LATEST_NAME"
cp -a "$PKG_DIR" "$UPGRADE_DIR/$LATEST_NAME"

if command -v sha256sum >/dev/null 2>&1; then
  sha256sum "$OUT_DIR/${LATEST_NAME}.tar.gz" | tee "$OUT_DIR/${LATEST_NAME}.tar.gz.sha256"
fi

if id www >/dev/null 2>&1; then
  chown -R www:www "$UPGRADE_DIR" 2>/dev/null || chmod -R a+rX "$UPGRADE_DIR"
else
  chmod -R a+rX "$UPGRADE_DIR"
fi

HOME_COMPAT="${PACK_HOME_COMPAT:-/home/challey}"
if [[ -d "$HOME_COMPAT" ]]; then
  ln -sfn "$OUT_DIR/${LATEST_NAME}.tar.gz" "$HOME_COMPAT/${LATEST_NAME}.tar.gz"
  ln -sfn "$OUT_DIR/${LATEST_NAME}.tar.gz.sha256" "$HOME_COMPAT/${LATEST_NAME}.tar.gz.sha256" 2>/dev/null || true
  ln -sfn "$OUT_DIR/gavias-update.sh" "$HOME_COMPAT/gavias-update.sh"
fi

echo
echo "PACKAGED: $OUT_DIR/${LATEST_NAME}.tar.gz"
echo "UPDATER:  $OUT_DIR/gavias-update.sh"
echo "TREE:     $OUT_DIR/$LATEST_NAME/"
echo "LOCAL:    $UPGRADE_DIR/"
echo "HISTORY:  $ARCHIVE_DIR/gavias-deploy-${STAMP}.tar.gz"
echo
echo "生产执行：cd /home/wwwroot/drupalX/upgrade/gavias && ./gavias-update.sh"
