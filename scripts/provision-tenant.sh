#!/usr/bin/env bash
# Provision a tenant via Drush dx:tenant-provision.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

# shellcheck source=lib/env.sh
source "$SCRIPT_DIR/lib/env.sh"
dx_load_env "$PROJECT_ROOT/.env"

MACHINE_NAME="${1:-}"
if [[ -z "$MACHINE_NAME" ]]; then
  echo "Usage: $0 <machine_name> [--label=Label] [--mail=owner@example.com]" >&2
  exit 1
fi
shift

DRUSH="$PROJECT_ROOT/vendor/bin/drush"
cd "$PROJECT_ROOT"

echo "==> Provisioning tenant: $MACHINE_NAME"
"$DRUSH" dx:tenant-provision "$MACHINE_NAME" "$@"
echo "==> Tenant provision complete"
