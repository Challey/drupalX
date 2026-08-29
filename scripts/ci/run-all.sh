#!/usr/bin/env bash
# One runner for the whole verification surface: static checks first (no DB,
# CI-safe), then the domain smoke scripts grouped by what they touch.
#
#   ./scripts/ci/run-all.sh                     # static + every smoke (writes to DB)
#   ./scripts/ci/run-all.sh --static-only       # no Drupal bootstrap, no DB
#   ./scripts/ci/run-all.sh --group=delivery     # one group
#   ./scripts/ci/run-all.sh --list               # show groups and scripts
#   ./scripts/ci/run-all.sh --keep-going          # do not stop on first failure
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

GROUP=
STATIC_ONLY=0
KEEP_GOING=0
LIST_ONLY=0
for arg in "$@"; do
  case "$arg" in
    --group=*) GROUP="${arg#--group=}" ;;
    --static-only) STATIC_ONLY=1 ;;
    --keep-going) KEEP_GOING=1 ;;
    --list) LIST_ONLY=1 ;;
    -h|--help) sed -n '2,12p' "$0"; exit 0 ;;
    *) echo "unknown option: $arg" >&2; exit 2 ;;
  esac
done

# Smoke scripts grouped by domain. Anything unlisted lands in "other".
declare -A SMOKE_GROUPS=(
  [delivery]="delivery-smoke.sh delivery-ops-smoke.sh delivery-todos-smoke.sh l3-handoff-smoke.sh desk-smoke.sh stack-status-smoke.sh www-deliver-smoke.sh"
  [exchange]="exchange-smoke.sh webhook-smoke.sh channel-smoke.sh channel-audit-smoke.sh"
  [migrate]="migrate-smoke.sh migrate-l2-smoke.sh migrate-review-smoke.sh migrate-package-smoke.sh"
  [ecosystem]="ecosystem-smoke.sh l0-publish-smoke.sh l2-credential-smoke.sh l3-source-smoke.sh trust-smoke.sh appstore-trust-smoke.sh"
  [clients]="flutter-shell-smoke.sh miniprogram-shell-smoke.sh clients-isomorph-smoke.sh packer-smoke.sh"
  [platform]="health-smoke.sh ha-smoke.sh certs-smoke.sh theme-smoke.sh opinion-smoke.sh"
)

ALL_SCRIPTS=$(ls scripts/ci/*-smoke.sh 2>/dev/null | xargs -n1 basename)
COVERED=$(for key in "${!SMOKE_GROUPS[@]}"; do echo "${SMOKE_GROUPS[$key]}"; done | tr ' ' '\n' | sed '/^$/d' | sort -u)
UNCOVERED=$(echo "$ALL_SCRIPTS" | grep -Fxv "$COVERED" || true)
if [[ -n "$UNCOVERED" ]]; then
  SMOKE_GROUPS[other]=$(echo "$UNCOVERED" | tr '\n' ' ')
fi

if [[ "$LIST_ONLY" == "1" ]]; then
  echo "== static (always first) =="
  echo "  php -l web/modules/custom web/themes/custom"
  echo "  bash -n scripts/"
  echo "  php scripts/ci/merge-integrity-check.php"
  for key in delivery exchange migrate ecosystem clients platform other; do
    [[ -n "${SMOKE_GROUPS[$key]:-}" ]] || continue
    echo "== group: $key =="
    for s in ${SMOKE_GROUPS[$key]}; do echo "  scripts/ci/$s"; done
  done
  exit 0
fi

PASS=(); FAIL=(); SKIP=()

run_step() {
  local label="$1"; shift
  echo "── $label"
  if "$@"; then
    PASS+=("$label")
  else
    FAIL+=("$label")
    echo "!! FAILED: $label" >&2
    [[ "$KEEP_GOING" == "1" ]] || { summarize; exit 1; }
  fi
}

summarize() {
  echo
  echo "================ summary ================"
  printf 'pass %d  fail %d  skip %d\n' "${#PASS[@]}" "${#FAIL[@]}" "${#SKIP[@]}"
  for f in "${FAIL[@]:-}"; do [[ -n "$f" ]] && echo "  FAIL $f"; done
  for s in "${SKIP[@]:-}"; do [[ -n "$s" ]] && echo "  SKIP $s"; done
}

needs_group() {
  [[ -z "$GROUP" ]] && return 0
  [[ "$GROUP" == "$1" ]] && return 0
  return 1
}

echo "== static checks =="
phperr=0
while IFS= read -r f; do
  php -l "$f" >/dev/null 2>&1 || { echo "  php -l FAIL $f" >&2; phperr=1; }
done < <(find web/modules/custom web/themes/custom -type f \( -name '*.php' -o -name '*.module' -o -name '*.theme' -o -name '*.install' -o -name '*.inc' \) 2>/dev/null)
if [[ "$phperr" == "0" ]]; then PASS+=("php -l custom code"); else FAIL+=("php -l custom code"); fi

sherr=0
while IFS= read -r f; do
  bash -n "$f" 2>/dev/null || { echo "  bash -n FAIL $f" >&2; sherr=1; }
done < <(find scripts tools -type f -name '*.sh' 2>/dev/null)
if [[ "$sherr" == "0" ]]; then PASS+=("bash -n shell scripts"); else FAIL+=("bash -n shell scripts"); fi

run_step "merge integrity check" php scripts/ci/merge-integrity-check.php

if [[ "$STATIC_ONLY" == "1" ]]; then
  summarize
  [[ ${#FAIL[@]} -eq 0 ]] && exit 0 || exit 1
fi

if [[ ! -x vendor/bin/drush ]]; then
  echo "vendor/bin/drush missing — smoke scripts need an installed site" >&2
  for key in delivery exchange migrate ecosystem clients platform other; do
    for s in ${SMOKE_GROUPS[$key]:-}; do SKIP+=("$s (no drush)"); done
  done
  summarize
  exit 1
fi

for key in delivery exchange migrate ecosystem clients platform other; do
  [[ -n "${SMOKE_GROUPS[$key]:-}" ]] || continue
  if ! needs_group "$key"; then
    for s in ${SMOKE_GROUPS[$key]}; do SKIP+=("$s (group != $GROUP)"); done
    continue
  fi
  echo
  echo "== smoke group: $key =="
  for s in ${SMOKE_GROUPS[$key]}; do
    if [[ -f "scripts/ci/$s" ]]; then
      run_step "$key/$s" bash "scripts/ci/$s"
    else
      SKIP+=("$key/$s (absent)")
    fi
  done
done

summarize
[[ ${#FAIL[@]} -eq 0 ]] && exit 0 || exit 1
