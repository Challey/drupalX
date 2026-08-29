#!/usr/bin/env bash
# Merge branches into the current integration branch applying plan rules R1-R4.
#   R1 clean merge  -> --no-ff merge as-is
#   R2 default      -> conflicted file resolved by newer last-commit timestamp
#   R3 guard        -> live-protected paths always keep the integration (ours) side
#   R4 docs         -> docs/README/skill docs kept as ours, flagged for manual union
# Usage: scripts/ops/integrate-branches.sh <ref> [<ref> ...]
# Run from an integration worktree/branch; the log lands in .worktrees/ (ignored).
set -uo pipefail

cd "$(dirname "$0")/../.." || exit 1
LOG="${MERGE_LOG:-.worktrees/merge-log.tsv}"
touch "$LOG"

is_protected() {
  case "$1" in
    web/modules/custom/dx_auth/*|web/modules/custom/dx_payment/*|web/modules/custom/dx_ai_gateway/*|web/modules/custom/dx_ecosystem/*|web/modules/custom/dx_delivery/*|web/modules/custom/dx_migrate/*|setup/nginx/*|setup/ha/*) return 0 ;;
    web/themes/custom/dx_portal_theme/css/login.css) return 0 ;;
    web/themes/custom/dx_portal_theme/js/login.js) return 0 ;;
    web/themes/custom/dx_portal_theme/js/portal.js) return 0 ;;
    web/themes/custom/dx_portal_theme/includes/login_i18n.php) return 0 ;;
    web/themes/custom/dx_portal_theme/templates/page--user--login.html.twig) return 0 ;;
    web/themes/custom/dx_portal_theme/templates/page--ai--chat.html.twig) return 0 ;;
    web/themes/custom/dx_portal_theme/templates/includes/dx-legal-footer.html.twig) return 0 ;;
    web/themes/custom/dx_portal_theme/css/skins/*) return 0 ;;
    *) return 1 ;;
  esac
}

is_docs() {
  case "$1" in
    docs/*|README.md|.cursor/skills/*) return 0 ;;
    *) return 1 ;;
  esac
}

# prints "theirs" when the branch side touched the file later than our side
newer_side() {
  local ours theirs ot tt
  ours="$1"; theirs="$2"; f="$3"
  ot=$(git log -1 --format=%ct "$ours" -- "$f" 2>/dev/null); ot=${ot:-0}
  tt=$(git log -1 --format=%ct "$theirs" -- "$f" 2>/dev/null); tt=${tt:-0}
  if [ "$tt" -gt "$ot" ]; then echo theirs; else echo ours; fi
}

take_ours() {
  git checkout --ours -- "$1" 2>/dev/null || git checkout HEAD -- "$1" 2>/dev/null || git rm -q --cached -- "$1" 2>/dev/null || true
}

take_theirs() {
  git checkout --theirs -- "$1" 2>/dev/null || git checkout "$2" -- "$1" 2>/dev/null || true
}

for b in "$@"; do
  short=${b#origin/}
  echo "=== merge $b ==="
  if [ -e "$(git rev-parse --git-dir)/MERGE_HEAD" ]; then
    echo "  ABORT: a merge is still in progress from the previous iteration"
    exit 1
  fi
  conflicts=$(git merge-tree --write-tree "$b" HEAD 2>&1 | grep -c "CONFLICT")
  if ! git merge --no-ff --no-commit "$b" >/dev/null 2>&1; then
    : # merge stopped: either conflicts or error; handled below
  fi
  unmerged=$(git diff --name-only --diff-filter=U)
  if [ -z "$unmerged" ]; then
    if git diff --cached --quiet; then
      echo "  already up to date, nothing to commit"
      printf '%s\t%s\t%s\t%s\n' "$short" "no-op" "0" "content already in integration" >> "$LOG"
      git merge --abort 2>/dev/null || true
      continue
    fi
    git commit -q -m "Merge branch '$short' into integration/all-branches

R1: merged cleanly, no conflicts." || { echo "  commit failed"; exit 1; }
    files=$(git diff --name-only HEAD^1 HEAD | tr '\n' ' ')
    printf '%s\t%s\t%s\t%s\n' "$short" "R1-clean" "$conflicts" "$files" >> "$LOG"
    echo "  merged clean (R1)"
    continue
  fi

  guarded=""; newest=""; manual=""
  for f in $unmerged; do
    if is_protected "$f"; then
      take_ours "$f"; git add -A -- "$f" 2>/dev/null || true
      guarded="$guarded $f"
    elif is_docs "$f"; then
      take_ours "$f"; git add -A -- "$f" 2>/dev/null || true
      manual="$manual $f"
    else
      if [ "$(newer_side HEAD "$b" "$f")" = theirs ]; then
        take_theirs "$f" "$b"; git add -A -- "$f" 2>/dev/null || true
        newest="$newest $f(theirs)"
      else
        take_ours "$f"; git add -A -- "$f" 2>/dev/null || true
        newest="$newest $f(ours)"
      fi
    fi
  done

  # conflict markers must not survive
  if grep -rn -l '^<<<<<<< ' $unmerged >/dev/null 2>&1; then
    echo "  ABORT: conflict markers left in: $unmerged"
    exit 1
  fi

  if git diff --cached --quiet; then
    printf '%s\t%s\t%s\t%s\n' "$short" "no-op(after-resolve)" "$conflicts" "all conflicts resolved to existing content" >> "$LOG"
    git merge --abort 2>/dev/null || true
    echo "  nothing left to commit after resolving $conflicts conflicts"
    continue
  fi

  msg="Merge branch '$short' into integration/all-branches"
  msg="$msg

Rules applied:"
  [ -n "$guarded" ] && msg="$msg
- R3 live-protected, integration side kept:$guarded"
  [ -n "$newest" ] && msg="$msg
- R2 newest-side wins:$newest"
  [ -n "$manual" ] && msg="$msg
- R4 docs kept at integration side, union pass follows:$manual"
  git commit -q -m "$msg" || { echo "  ABORT: commit failed for $b"; exit 1; }
  printf '%s\t%s\t%s\t%s\n' "$short" "R2/R3/R4-resolved" "$conflicts" "guard:${guarded:-none} newest:${newest:-none} docs:${manual:-none}" >> "$LOG"
  echo "  merged with $conflicts conflict files (guard=$(echo $guarded | wc -w), r2=$(echo $newest | wc -w), docs=$(echo $manual | wc -w))"
done

echo "=== log tail ==="
tail -5 "$LOG"
