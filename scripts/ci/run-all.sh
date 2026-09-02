#!/usr/bin/env bash
# One runner for the whole verification surface.
#
# The run is split into two explicit sections:
#
#   1. gate  — no-DB / no-site checks, ALWAYS run first, unattended-safe:
#              php -l, bash -n, scripts/ci/merge-integrity-check.php, every
#              web/modules/custom/*/tests/pure-assertions.php, the offline
#              segments of auth-smoke.sh / l0-publish-smoke.sh, the L3 manifest
#              schema gate + clients isomorph check, and unit-tests.sh (which
#              gracefully skips when phpunit is not installed).
#   2. site  — smoke scripts that bootstrap Drupal and WRITE to the database.
#              Grouped by domain and skipped unless drush is present and the
#              site section is enabled. Never part of unattended CI.
#
#   ./scripts/ci/run-all.sh                     # gate + every site smoke (writes to DB)
#   ./scripts/ci/run-all.sh --no-db             # gate only (unattended-safe; alias --static-only)
#   ./scripts/ci/run-all.sh --group=delivery    # gate + one site group
#   ./scripts/ci/run-all.sh --list              # show gate steps and site groups
#   ./scripts/ci/run-all.sh --keep-going        # do not stop on first failure
#   ./scripts/ci/run-all.sh --with-kernel       # pass through to unit-tests.sh
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

GROUP=
NO_DB=0
KEEP_GOING=0
LIST_ONLY=0
WITH_KERNEL=0
for arg in "$@"; do
  case "$arg" in
    --group=*) GROUP="${arg#--group=}" ;;
    --no-db|--static-only) NO_DB=1 ;;
    --keep-going) KEEP_GOING=1 ;;
    --with-kernel) WITH_KERNEL=1 ;;
    --list) LIST_ONLY=1 ;;
    -h|--help) sed -n '2,24p' "$0"; exit 0 ;;
    *) echo "unknown option: $arg" >&2; exit 2 ;;
  esac
done

# Site smoke scripts grouped by domain. Anything unlisted lands in "other".
declare -A SMOKE_GROUPS=(
  [delivery]="delivery-smoke.sh delivery-ops-smoke.sh delivery-todos-smoke.sh l3-handoff-smoke.sh desk-smoke.sh stack-status-smoke.sh www-deliver-smoke.sh"
  [exchange]="exchange-smoke.sh webhook-smoke.sh channel-smoke.sh channel-audit-smoke.sh"
  [migrate]="migrate-smoke.sh migrate-l2-smoke.sh migrate-review-smoke.sh migrate-package-smoke.sh"
  [ecosystem]="ecosystem-smoke.sh l0-publish-smoke.sh l2-credential-smoke.sh l3-source-smoke.sh trust-smoke.sh appstore-trust-smoke.sh"
  [clients]="flutter-shell-smoke.sh miniprogram-shell-smoke.sh clients-isomorph-smoke.sh packer-smoke.sh"
  [platform]="health-smoke.sh ha-smoke.sh certs-smoke.sh theme-smoke.sh opinion-smoke.sh auth-smoke.sh"
)

ALL_SCRIPTS=$(ls scripts/ci/*-smoke.sh 2>/dev/null | xargs -n1 basename)
COVERED=$(for key in "${!SMOKE_GROUPS[@]}"; do echo "${SMOKE_GROUPS[$key]}"; done | tr ' ' '\n' | sed '/^$/d' | sort -u)
UNCOVERED=$(echo "$ALL_SCRIPTS" | grep -Fxv "$COVERED" || true)
if [[ -n "$UNCOVERED" ]]; then
  SMOKE_GROUPS[other]=$(echo "$UNCOVERED" | tr '\n' ' ')
fi

if [[ "$LIST_ONLY" == "1" ]]; then
  echo "== gate (no DB, always first) =="
  echo "  php -l web/modules/custom web/themes/custom web/profiles/custom"
  echo "  bash -n scripts/ tools/"
  echo "  php scripts/ci/merge-integrity-check.php"
  echo "  php web/modules/custom/*/tests/pure-assertions.php   (each discovered)"
  echo "  bash scripts/ci/auth-smoke.sh offline                (if present)"
  echo "  bash scripts/ci/l0-publish-smoke.sh offline          (if present)"
  echo "  bash scripts/x-pack-manifest.sh --all                (if present)"
  echo "  python3 tools/clients/isomorph_check.py all          (if present)"
  echo "  bash scripts/ci/unit-tests.sh                        (skips if no phpunit)"
  echo
  for key in delivery exchange migrate ecosystem clients platform other; do
    [[ -n "${SMOKE_GROUPS[$key]:-}" ]] || continue
    echo "== site group: $key (writes to DB) =="
    for s in ${SMOKE_GROUPS[$key]}; do echo "  scripts/ci/$s"; done
  done
  exit 0
fi

PASS=(); FAIL=(); SKIP=()
declare -A PASS_N FAIL_N SKIP_N
BUCKET_ORDER=()

note_bucket() {
  local b="$1" seen=0 x
  for x in ${BUCKET_ORDER[@]+"${BUCKET_ORDER[@]}"}; do [[ "$x" == "$b" ]] && seen=1; done
  [[ "$seen" == 0 ]] && BUCKET_ORDER+=("$b")
}

tally() {
  local bucket="$1" label="$2" ok="$3"
  note_bucket "$bucket"
  if [[ "$ok" == "0" ]]; then
    PASS+=("[$bucket] $label"); PASS_N[$bucket]=$(( ${PASS_N[$bucket]:-0} + 1 ))
  else
    FAIL+=("[$bucket] $label"); FAIL_N[$bucket]=$(( ${FAIL_N[$bucket]:-0} + 1 ))
    echo "!! FAILED: [$bucket] $label" >&2
    [[ "$KEEP_GOING" == "1" ]] || { summarize; exit 1; }
  fi
}

skip_step() {
  local bucket="$1" label="$2" reason="$3"
  note_bucket "$bucket"
  SKIP+=("[$bucket] $label ($reason)"); SKIP_N[$bucket]=$(( ${SKIP_N[$bucket]:-0} + 1 ))
  echo "   skip [$bucket] $label — $reason"
}

run_step() {
  local bucket="$1" label="$2"; shift 2
  echo "── [$bucket] $label"
  "$@"
  local rc=$?
  if [[ $rc -eq 0 ]]; then
    tally "$bucket" "$label" 0
  elif [[ $rc -eq 77 ]]; then
    skip_step "$bucket" "$label" "exit 77 (explicit skip)"
  else
    tally "$bucket" "$label" 1
  fi
}

summarize() {
  echo
  echo "================ summary ================"
  local b
  for b in ${BUCKET_ORDER[@]+"${BUCKET_ORDER[@]}"}; do
    printf '  %-16s pass %d  fail %d  skip %d\n' "$b" \
      "${PASS_N[$b]:-0}" "${FAIL_N[$b]:-0}" "${SKIP_N[$b]:-0}"
  done
  printf 'total: pass %d  fail %d  skip %d\n' "${#PASS[@]}" "${#FAIL[@]}" "${#SKIP[@]}"
  for f in "${FAIL[@]:-}"; do [[ -n "$f" ]] && echo "  FAIL $f"; done
  for s in "${SKIP[@]:-}"; do [[ -n "$s" ]] && echo "  SKIP $s"; done
}

needs_group() {
  [[ -z "$GROUP" ]] && return 0
  [[ "$GROUP" == "$1" ]] && return 0
  return 1
}

# Offline-capable smoke: run its "offline" segment only when the script exists
# AND advertises an offline mode. On an un-integrated tree these are absent and
# are reported as skips rather than executed (never touching the DB).
offline_smoke() {
  local script="$1"
  if [[ ! -f "scripts/ci/$script" ]]; then
    skip_step gate "$script offline" "脚本不存在（待对应线集成）"
  elif ! grep -q "offline" "scripts/ci/$script"; then
    skip_step gate "$script offline" "无 offline 段（待对应线集成）"
  else
    run_step gate "$script offline" bash "scripts/ci/$script" offline
  fi
}

run_gate() {
  echo "== gate: no-DB / no-site checks (unattended-safe) =="

  # 1) php -l over custom code
  local phperr=0 f
  while IFS= read -r f; do
    php -l "$f" >/dev/null 2>&1 || { echo "  php -l FAIL $f" >&2; phperr=1; }
  done < <(find web/modules/custom web/themes/custom web/profiles/custom -type f \
      \( -name '*.php' -o -name '*.module' -o -name '*.theme' -o -name '*.install' -o -name '*.inc' \) 2>/dev/null)
  tally gate "php -l custom code" "$phperr"

  # 2) bash -n over shell scripts
  local sherr=0
  while IFS= read -r f; do
    bash -n "$f" 2>/dev/null || { echo "  bash -n FAIL $f" >&2; sherr=1; }
  done < <(find scripts tools -type f -name '*.sh' 2>/dev/null)
  tally gate "bash -n shell scripts" "$sherr"

  # 3) merge integrity (duplicate entity id / class / route / service id)
  run_step gate "merge integrity check" php scripts/ci/merge-integrity-check.php

  # 4) every pure-assertions harness (no DB, no site, no phpunit)
  local pure=()
  while IFS= read -r f; do pure+=("$f"); done < <(find web/modules/custom web/profiles/custom web/themes/custom \
      -type f -path '*/tests/pure-assertions.php' 2>/dev/null | sort)
  if [[ ${#pure[@]} -eq 0 ]]; then
    skip_step gate "tests/pure-assertions.php" "未发现（待各线集成）"
  else
    for f in "${pure[@]}"; do run_step gate "pure-assertions $f" php "$f"; done
  fi

  # 5) offline segments of the auth / L0 smokes
  offline_smoke auth-smoke.sh
  offline_smoke l0-publish-smoke.sh

  # 6) L3 static gates: manifest schema + cross-end isomorph check
  if [[ -f scripts/x-pack-manifest.sh ]]; then
    run_step gate "L3 manifest schema gate" bash scripts/x-pack-manifest.sh --all
  else
    skip_step gate "L3 manifest schema gate" "scripts/x-pack-manifest.sh 不存在（待 L3 集成）"
  fi
  if [[ -f tools/clients/isomorph_check.py ]] && command -v python3 >/dev/null 2>&1; then
    run_step gate "L3 clients isomorph check" python3 tools/clients/isomorph_check.py all
  else
    skip_step gate "L3 clients isomorph check" "tools/clients/isomorph_check.py 或 python3 不存在（待 L3 集成）"
  fi

  # 7) unit tests (graceful skip when phpunit is not installed)
  if [[ "$WITH_KERNEL" == "1" ]]; then
    run_step gate "unit-tests.sh (unit+kernel)" bash scripts/ci/unit-tests.sh --with-kernel
  else
    run_step gate "unit-tests.sh (unit)" bash scripts/ci/unit-tests.sh
  fi
}

run_gate

if [[ "$NO_DB" == "1" ]]; then
  echo
  echo "(--no-db: site/DB smoke section skipped)"
  summarize
  [[ ${#FAIL[@]} -eq 0 ]] && exit 0 || exit 1
fi

if [[ ! -x vendor/bin/drush ]]; then
  echo "vendor/bin/drush missing — site smoke scripts need an installed site" >&2
  for key in delivery exchange migrate ecosystem clients platform other; do
    for s in ${SMOKE_GROUPS[$key]:-}; do note_bucket "site:$key"; SKIP+=("[site:$key] $s (no drush)"); SKIP_N[site:$key]=$(( ${SKIP_N[site:$key]:-0} + 1 )); done
  done
  summarize
  exit 1
fi

for key in delivery exchange migrate ecosystem clients platform other; do
  [[ -n "${SMOKE_GROUPS[$key]:-}" ]] || continue
  if ! needs_group "$key"; then
    for s in ${SMOKE_GROUPS[$key]}; do note_bucket "site:$key"; SKIP+=("[site:$key] $s (group != $GROUP)"); SKIP_N[site:$key]=$(( ${SKIP_N[site:$key]:-0} + 1 )); done
    continue
  fi
  echo
  echo "== site group: $key (writes to DB) =="
  for s in ${SMOKE_GROUPS[$key]}; do
    if [[ -f "scripts/ci/$s" ]]; then
      run_step "site:$key" "$s" bash "scripts/ci/$s"
    else
      skip_step "site:$key" "$s" "absent"
    fi
  done
done

summarize
[[ ${#FAIL[@]} -eq 0 ]] && exit 0 || exit 1
