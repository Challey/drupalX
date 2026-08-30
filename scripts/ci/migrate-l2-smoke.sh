#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
DRUSH=(vendor/bin/drush)

echo "== dx_migrate L2 smoke =="
"${DRUSH[@]}" pm:enable dx_channel dx_migrate -y >/dev/null
"${DRUSH[@]}" cr >/dev/null

"${DRUSH[@]}" dx:migrate-l2 --template=gov_news --dry-run >/tmp/dx-migrate-l2-gov.out
grep -q '"ok": true' /tmp/dx-migrate-l2-gov.out
grep -q '"details":' /tmp/dx-migrate-l2-gov.out
DETAILS="$(python3 -c 'import json; print(json.load(open("/tmp/dx-migrate-l2-gov.out"))["details"])')"
[[ "$DETAILS" -ge 1 ]]

"${DRUSH[@]}" dx:migrate-l2 --template=ent_article --dry-run >/tmp/dx-migrate-l2-ent.out
grep -q '"ok": true' /tmp/dx-migrate-l2-ent.out
ENT_DETAILS="$(python3 -c 'import json; print(json.load(open("/tmp/dx-migrate-l2-ent.out"))["details"])')"
[[ "$ENT_DETAILS" -ge 1 ]]

# ---------------------------------------------------------------------------
# G1 declarative field-mapping templates (roadmap Phase G).
# A new industry must ship as a YAML file only — no PHP change — and an
# incomplete template must be refused with a per-field message instead of being
# silently degraded to the `auto` mapping.
# ---------------------------------------------------------------------------
TPL_DIR="$ROOT/web/modules/custom/dx_migrate/data/templates"
jget() {
  python3 -c 'import json,sys; d=json.load(open(sys.argv[1])); print(d[sys.argv[2]])' "$1" "$2"
}

"${DRUSH[@]}" dx:migrate-templates >/tmp/dx-migrate-templates.out
for tpl in gov_news ent_article hospital_notice; do
  if ! grep -q "$tpl" /tmp/dx-migrate-templates.out; then
    echo "template '$tpl' missing from dx:migrate-templates"; exit 1
  fi
done
grep -q '^Directories:' /tmp/dx-migrate-templates.out

# Bundled samples validate both by file path and by machine name.
"${DRUSH[@]}" dx:migrate-template-validate "$TPL_DIR/hospital_notice.yml" >/tmp/dx-mig-tplfile.out
sed -n '/^{/,/^}/p' /tmp/dx-mig-tplfile.out > /tmp/dx-mig-tplfile.json
grep -q 'is a valid template (machine name: hospital_notice)' /tmp/dx-mig-tplfile.out
python3 - <<'PY'
import json
d = json.load(open('/tmp/dx-mig-tplfile.json'))
# `extends` is resolved away and the merge is deep: the child overrides the list
# container and detail selectors but keeps the parent's id prefix, body fallback
# and resource defaults.
assert 'extends' not in d, d.keys()
assert d['machine_name'] == 'hospital_notice', d['machine_name']
assert d['spec_version'] == '1.0', d['spec_version']
assert d['list']['fixture'] == 'hospital-notice-list.html', d['list']['fixture']
assert 'notice-board' in d['list']['xpath'], d['list']['xpath']
assert d['item']['external_id']['prefix'] == 'l1_', d['item']['external_id']
assert d['item']['external_id']['length'] == 16, d['item']['external_id']
assert d['detail']['body_fallback_xpath'] == '//p', d['detail']['body_fallback_xpath']
assert 'notice-content' in ' '.join(d['detail']['body_xpath'])
assert d['resource'] == {'type': 'article', 'status': 'draft', 'review': True, 'detail_limit': 10}, d['resource']
print('template inheritance ok')
PY

"${DRUSH[@]}" dx:migrate-template-validate --template=gov_news >/tmp/dx-mig-tplname.out
sed -n '/^{/,/^}/p' /tmp/dx-mig-tplname.out > /tmp/dx-mig-tplname.json
grep -q 'Template "gov_news" is valid.' /tmp/dx-mig-tplname.out
python3 -c "import json;d=json.load(open('/tmp/dx-mig-tplname.json'));assert d['machine_name']=='gov_news';assert 'gov-news' in d['list']['xpath'];print('gov_news resolvable by name')"

# An incomplete template file is refused, loudly, one message per missing key.
cat > /tmp/dx_mig_tpl_broken.yml <<'YML'
label: 'Broken template'
spec_version: '1.0'
YML
if "${DRUSH[@]}" dx:migrate-template-validate /tmp/dx_mig_tpl_broken.yml >/tmp/dx-mig-tplbroken.out 2>&1; then
  echo "an incomplete template must not validate"; exit 1
fi
grep -q 'is invalid' /tmp/dx-mig-tplbroken.out
for field in list.fixture list.xpath item.max_items item.external_id.source item.body.parts \
             detail.title_xpath detail.body_empty_html fetch.user_agent resource.type resource.detail_limit; do
  if ! grep -q "$field" /tmp/dx-mig-tplbroken.out; then
    echo "no validation issue reported for '$field'"; exit 1
  fi
done
ISSUES="$(grep -c '^  - ' /tmp/dx-mig-tplbroken.out || true)"
[[ "$ISSUES" -ge 20 ]] || { echo "expected one issue per missing key, got $ISSUES"; exit 1; }
rm -f /tmp/dx_mig_tpl_broken.yml

# The new sample template drives a real L2 run, and --limit still wins over the
# template's own resource.detail_limit default.
"${DRUSH[@]}" dx:migrate-l2 --template=hospital_notice --dry-run >/tmp/dx-migrate-l2-notice.out
grep -q '"ok": true' /tmp/dx-migrate-l2-notice.out
grep -q '"template": "hospital_notice"' /tmp/dx-migrate-l2-notice.out
NOTICE_DETAILS="$(jget /tmp/dx-migrate-l2-notice.out details)"
[[ "$NOTICE_DETAILS" -ge 1 ]] || { echo "hospital_notice produced no enriched detail"; exit 1; }

"${DRUSH[@]}" dx:migrate-l2 --template=gov_news --limit=1 --dry-run >/tmp/dx-migrate-l2-limit.out
grep -q '"ok": true' /tmp/dx-migrate-l2-limit.out
[[ "$(jget /tmp/dx-migrate-l2-limit.out imported)" -eq 1 ]] || { echo "--limit=1 must cap the run"; exit 1; }

# Drop-in extensibility: register an extra template directory in config and the
# new machine name is usable immediately (no PHP change, no rebuild).
EXTRA="/tmp/dx-mig-templates"
mkdir -p "$EXTRA"
cat > "$EXTRA/smoke_bulletin.yml" <<'YML'
# G1 验收样本：仅新增数据文件即可被 dx:migrate-l2 --template=smoke_bulletin 使用。
extends: _base
label: 'Smoke bulletin (data only)'
weight: 90
list:
  fixture: hospital-notice-list.html
  xpath: '//*[contains(@class,"notice-board")]//a[@href]'
YML
"${DRUSH[@]}" php:eval '
$c = \Drupal::configFactory()->getEditable("dx_migrate.settings");
$c->setValue("template_dirs", ['"\"$EXTRA\""']);
$c->save();
echo "dirs=" . implode(",", (array) $c->get("template_dirs"));' >/tmp/dx-mig-tpldirs.out
grep -q "dirs=$EXTRA" /tmp/dx-mig-tpldirs.out
"${DRUSH[@]}" dx:migrate-templates >/tmp/dx-mig-tpllist.out
grep -q 'smoke_bulletin' /tmp/dx-mig-tpllist.out
"${DRUSH[@]}" dx:migrate-l2 --template=smoke_bulletin --dry-run >/tmp/dx-mig-l2-extra.out
grep -q '"ok": true' /tmp/dx-mig-l2-extra.out
grep -q '"template": "smoke_bulletin"' /tmp/dx-mig-l2-extra.out
[[ "$(jget /tmp/dx-mig-l2-extra.out details)" -ge 1 ]] || { echo "data-only template produced no details"; exit 1; }

# Unregistering the directory removes the template again — nothing is cached
# across processes and no stale entry stays usable.
"${DRUSH[@]}" php:eval '
$c = \Drupal::configFactory()->getEditable("dx_migrate.settings");
$c->setValue("template_dirs", []);
$c->save();
echo "reset";' >/dev/null
if "${DRUSH[@]}" dx:migrate-l2 --template=smoke_bulletin --dry-run >/tmp/dx-mig-l2-extra-off.out 2>&1; then
  echo "a template from an unregistered directory must not resolve"; exit 1
fi
grep -q 'Unknown migrate template' /tmp/dx-mig-l2-extra-off.out
rm -rf "$EXTRA"

echo "OK gov_details=$DETAILS ent_details=$ENT_DETAILS notice_details=$NOTICE_DETAILS template_issues=$ISSUES"
