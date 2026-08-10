#!/usr/bin/env bash
# Load .env variables from the DrupalX project root.
set -euo pipefail

dcn_load_env() {
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

  export DCN_DB_HOST="${DCN_DB_HOST:-127.0.0.1}"
  export DCN_DB_PORT="${DCN_DB_PORT:-3306}"
  export DCN_DB_USER="${DCN_DB_USER:-root}"
  export DCN_DB_PASS="${DCN_DB_PASS:-}"
  export DCN_DB_PLATFORM="${DCN_DB_PLATFORM:-dcn_platform}"
  export DCN_TENANT_SUFFIX="${DCN_TENANT_SUFFIX:-drupalx.local}"
  export DCN_ADMIN_USER="${DCN_ADMIN_USER:-admin}"
  export DCN_ADMIN_PASS="${DCN_ADMIN_PASS:-admin}"
  export DCN_ADMIN_MAIL="${DCN_ADMIN_MAIL:-admin@drupalx.local}"
}
