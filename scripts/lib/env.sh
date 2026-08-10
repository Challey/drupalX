#!/usr/bin/env bash
# Load .env variables from the DrupalX project root.
set -euo pipefail

dx_load_env() {
  local env_file="${1:-}"
  if [[ -z "$env_file" ]]; then
    local script_dir
    script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    env_file="$(cd "$script_dir/../.." && pwd)/.env"
  fi

  if [[ ! -f "$env_file" ]]; then
    echo "Warning: .env not found at $env_file" >&2
    return 0
  fi

  set -a
  # shellcheck disable=SC1090
  source <(grep -v '^#' "$env_file" | grep -v '^$' | sed 's/\r$//')
  set +a

  export DX_DB_HOST="${DX_DB_HOST:-127.0.0.1}"
  export DX_DB_PORT="${DX_DB_PORT:-3306}"
  export DX_DB_USER="${DX_DB_USER:-root}"
  export DX_DB_PASS="${DX_DB_PASS:-}"
  export DX_DB_PLATFORM="${DX_DB_PLATFORM:-dx_platform}"
  export DX_TENANT_SUFFIX="${DX_TENANT_SUFFIX:-drupalx.local}"
  export DX_ADMIN_USER="${DX_ADMIN_USER:-admin}"
  export DX_ADMIN_PASS="${DX_ADMIN_PASS:-admin}"
  export DX_ADMIN_MAIL="${DX_ADMIN_MAIL:-admin@drupalx.local}"
}
