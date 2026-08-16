#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
DRUSH=(vendor/bin/drush)

echo "== dx_opinion smoke =="
# Drop ghost enabled modules missing from this checkout (shared DB drift).
"${DRUSH[@]}" php:eval '
$cfg = \Drupal::configFactory()->getEditable("core.extension");
$mods = $cfg->get("module") ?: [];
$changed = FALSE;
foreach (array_keys($mods) as $name) {
  if (!\Drupal::service("extension.list.module")->exists($name)) {
    unset($mods[$name]);
    $changed = TRUE;
    echo "removed ghost $name\n";
  }
}
if ($changed) {
  $cfg->set("module", $mods)->save();
}
' >/tmp/dx-opinion-ghost.out || true

"${DRUSH[@]}" pm:enable dx_opinion -y >/dev/null
"${DRUSH[@]}" cr >/dev/null

CODE="$("${DRUSH[@]}" php:eval 'echo \Drupal::httpKernel()->handle(\Symfony\Component\HttpFoundation\Request::create("/opinion"))->getStatusCode();')"
[[ "$CODE" == "200" ]]

echo "OK /opinion status=$CODE"
