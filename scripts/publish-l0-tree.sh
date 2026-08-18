#!/usr/bin/env bash
# Publish the L0 public-framework tree from docs/l0-whitelist.yml.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DEST="${1:-/tmp/drupalx-l0-public}"
cd "$ROOT"
php "$ROOT/scripts/publish-l0-tree.php" "$DEST"
echo "OK L0 published → $DEST"
