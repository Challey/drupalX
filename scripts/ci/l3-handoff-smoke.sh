#!/usr/bin/env bash
# L3 migrate → acceptance.manual_todos (D4-A)
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

if [[ ! -x vendor/bin/drush ]]; then
  echo "skip: vendor/bin/drush missing (template/orchestrator changes still committed)"
  # Static contract: orchestrator must emit manual_todos for l3
  grep -q "manual_todos" web/modules/custom/dx_delivery/src/Service/DeliveryOrchestrator.php
  grep -q "l3_scope" web/modules/custom/dx_delivery/src/Service/DeliveryOrchestrator.php
  grep -q "manual_todos" web/modules/custom/dx_delivery/templates/dx-delivery-blueprint.html.twig
  echo "OK L3 manual_todos (static)"
  exit 0
fi

DRUSH=(vendor/bin/drush)
echo "== L3 manual_todos smoke =="
"${DRUSH[@]}" pm:enable dx_delivery dx_migrate dx_trust dx_health -y >/dev/null
"${DRUSH[@]}" cr >/dev/null

UNIQUE="l3todo$(date +%s | tail -c 5)"
ID="$("${DRUSH[@]}" php:eval "
\$e = \Drupal\dx_delivery\Entity\DeliveryBlueprint::create([
  'label' => 'L3 handoff smoke',
  'machine_name' => '$UNIQUE',
  'site_type' => 'government',
  'theme_pack' => 'gov_steady',
  'layout_profile' => 'gov_default',
  'status' => 'draft',
  'migrate_level' => 'l3',
  'source_url' => 'https://example.gov/legacy/',
  'channels' => json_encode(['web']),
  'capabilities' => '[]',
]);
\$e->setPayload(['migrate' => ['level' => 'l3', 'source_url' => 'https://example.gov/legacy/']]);
\$e->save();
echo \$e->id();
")"
[[ -n "$ID" && "$ID" != "0" ]]
echo "blueprint id=$ID"

"${DRUSH[@]}" dx:delivery-run "$ID" --skip-provision --skip-pack >/tmp/dx-l3-run.out
grep -q '"passed": true' /tmp/dx-l3-run.out
grep -q '"manual_todos"' /tmp/dx-l3-run.out
grep -q 'l3_scope' /tmp/dx-l3-run.out
grep -q 'l3_handoff' /tmp/dx-l3-run.out
grep -q 'L3 marked manual' /tmp/dx-l3-run.out

echo "OK L3 manual_todos"
