#!/usr/bin/env bash
# DrupalX unit / kernel test runner (roadmap Phase Q4).
#
#   bash scripts/ci/unit-tests.sh                 # Unit suite only (no DB)
#   bash scripts/ci/unit-tests.sh --with-kernel   # Unit + Kernel (Kernel needs SIMPLETEST_DB)
#   bash scripts/ci/unit-tests.sh --help
#
# Behaviour:
#   * If vendor/bin/phpunit is NOT installed, this script prints the exact
#     install command and exits 0 (graceful skip) so unattended CI never fails
#     just because the dev dependency is absent on a production docroot.
#   * Unit tests are pure (no database) and safe for the no-DB gate.
#   * Kernel tests need a writable test database; they are OFF by default and
#     only run with --with-kernel. Set SIMPLETEST_DB before enabling them.
#
# phpunit is a require-dev dependency (drupal/core-dev). It is intentionally NOT
# installed by this lane; the integrator runs composer on the main copy. See
# docs/lanes/L6-docs-ci.md and docs/integration-report-2026-09.md.
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

WITH_KERNEL=0
for arg in "$@"; do
  case "$arg" in
    --with-kernel) WITH_KERNEL=1 ;;
    -h|--help) sed -n '2,22p' "$0"; exit 0 ;;
    *) echo "unknown option: $arg" >&2; exit 2 ;;
  esac
done

CONFIG="phpunit.xml.dist"
PHPUNIT="vendor/bin/phpunit"

install_hint() {
  echo "     安装命令（由集成方在主副本 /home/wwwroot/drupalX 上执行；本线禁止跑 composer）："
  echo "       composer require --dev drupal/core-dev:^11.4 --dry-run   # 先看会不会动生产包"
  echo "       composer require --dev drupal/core-dev:^11.4             # 确认无误后实际安装"
  echo "     安装后重跑：bash scripts/ci/unit-tests.sh [--with-kernel]"
}

if [[ ! -x "$PHPUNIT" ]]; then
  echo "== DrupalX unit tests =="
  echo "SKIP 未安装 phpunit，跳过（vendor/bin/phpunit 不存在）。"
  install_hint
  exit 0
fi

if [[ ! -f "$CONFIG" ]]; then
  echo "ERROR 找不到 $CONFIG（应位于仓库根）。" >&2
  exit 1
fi

status=0

echo "== DrupalX unit tests (suite: unit) =="
"$PHPUNIT" -c "$CONFIG" --testsuite unit || status=1

if [[ "$WITH_KERNEL" == "1" ]]; then
  echo
  echo "== DrupalX kernel tests (suite: kernel) =="
  if [[ -z "${SIMPLETEST_DB:-}" ]]; then
    echo "WARN  SIMPLETEST_DB 未设置；Kernel 测试需要可写测试库。示例：" >&2
    echo "        export SIMPLETEST_DB=mysql://user:pass@127.0.0.1/drupalx_test" >&2
    echo "        export SIMPLETEST_BASE_URL=http://localhost" >&2
  fi
  "$PHPUNIT" -c "$CONFIG" --testsuite kernel || status=1
else
  echo
  echo "(Kernel 测试默认跳过；用 --with-kernel 开启，需 SIMPLETEST_DB。)"
fi

exit "$status"
