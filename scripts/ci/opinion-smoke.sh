#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
DRUSH=(vendor/bin/drush)

echo "== dx_opinion smoke =="
"${DRUSH[@]}" pm:enable dx_opinion -y >/dev/null
"${DRUSH[@]}" cr >/dev/null

CODE="$("${DRUSH[@]}" php:eval 'echo \Drupal::httpKernel()->handle(\Symfony\Component\HttpFoundation\Request::create("/opinion"))->getStatusCode();')"
[[ "$CODE" == "200" ]]

echo "OK /opinion status=$CODE"
