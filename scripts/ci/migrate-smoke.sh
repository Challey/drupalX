#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
DRUSH=(vendor/bin/drush)

echo "== dx_migrate smoke =="
"${DRUSH[@]}" pm:enable dx_channel dx_migrate -y >/dev/null
"${DRUSH[@]}" cr >/dev/null

"${DRUSH[@]}" dx:migrate-l1 --dry-run >/tmp/dx-migrate-l1.out
grep -q '"ok": true' /tmp/dx-migrate-l1.out
grep -q '"imported":' /tmp/dx-migrate-l1.out
IMPORTED="$(python3 -c 'import json,sys; print(json.load(open("/tmp/dx-migrate-l1.out"))["imported"])')"
[[ "$IMPORTED" -ge 1 ]]

"${DRUSH[@]}" dx:migrate-l1 --template=gov_news --dry-run >/tmp/dx-migrate-l1-gov.out
grep -q '"ok": true' /tmp/dx-migrate-l1-gov.out

# ---------------------------------------------------------------------------
# G1 field-mapping templates, L1 side.
# The bundled `auto` mapping must still be the default (behaviour preserved for
# production runs started without --template), the template actually used must
# be echoed back, and a typo must fail loudly instead of degrading to `auto`.
# ---------------------------------------------------------------------------
grep -q '"template": "auto"' /tmp/dx-migrate-l1.out
grep -q '"template": "gov_news"' /tmp/dx-migrate-l1-gov.out

"${DRUSH[@]}" dx:migrate-templates >/tmp/dx-migrate-templates-l1.out
for tpl in gov_news ent_article hospital_notice; do
  if ! grep -q "$tpl" /tmp/dx-migrate-templates-l1.out; then
    echo "template '$tpl' missing from dx:migrate-templates"; exit 1
  fi
done
grep -q '^Directories:' /tmp/dx-migrate-templates-l1.out
# `_base` is an internal parent: it shows up as such, and it is never usable as
# a --template value (asserted right below, because the table may wrap rows).
grep -q 'internal parent' /tmp/dx-migrate-templates-l1.out

if "${DRUSH[@]}" dx:migrate-l1 --template=no_such_template --dry-run >/tmp/dx-migrate-l1-bad.out 2>&1; then
  echo "an unknown --template must not fall back silently"; exit 1
fi
grep -q 'Unknown migrate template' /tmp/dx-migrate-l1-bad.out
grep -q 'no_such_template' /tmp/dx-migrate-l1-bad.out

# Internal parents cannot be used directly either.
if "${DRUSH[@]}" dx:migrate-l1 --template=_base --dry-run >/tmp/dx-migrate-l1-base.out 2>&1; then
  echo "--template=_base must be refused"; exit 1
fi
grep -q 'internal parent' /tmp/dx-migrate-l1-base.out

echo "OK imported=$IMPORTED (dry-run fixture + gov_news template, template library live)"
