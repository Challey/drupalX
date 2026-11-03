<?php

/**
 * Acceptance script for the dx_nasa mirror subsite (local and production).
 *
 * Usage:
 *   php vendor/drush/drush/drush.php php:script scripts/verify_nasa_mirror.php
 *
 * Renders each representative mirror path through http_kernel and asserts
 * HTTP 200 with a non-empty page. Exits non-zero on failures.
 */

$paths = [
  // Zone + single-segment slugs.
  '/nasa',
  '/nasa/apod',
  '/nasa/en/universe',
  '/nasa/zh/universe',
  // Depth 2 slugs (URL depth 4) - previously 404 before per-depth routes.
  '/nasa/zh/universe/overview',
  '/nasa/en/universe/overview',
  '/nasa/zh/universe/stars',
  '/nasa/en/universe/stars',
  '/nasa/zh/universe/galaxies',
  '/nasa/en/universe/galaxies',
  '/nasa/zh/universe/black-holes',
  '/nasa/en/universe/black-holes',
  '/nasa/zh/universe/the-big-bang',
  '/nasa/en/universe/the-big-bang',
  '/nasa/zh/universe/dark-matter-dark-energy',
  '/nasa/en/universe/dark-matter-dark-energy',
  '/nasa/zh/kids/universe',
  '/nasa/en/kids/universe',
  // Depth 3 slugs (URL depth 5).
  '/nasa/zh/universe/overview/building-blocks',
  '/nasa/en/universe/galaxies/types',
  '/nasa/zh/universe/black-holes/anatomy',
  // Depth 4 slugs (URL depth 6).
  '/nasa/zh/universe/stories/quick-reads/decoding-nebulae',
  '/nasa/en/universe/stories/quick-reads/decoding-nebulae',
  '/nasa/zh/universe/stories/quick-reads/neutron-stars-are-weird',
  // dx_youth zone regression checks.
  '/edu',
  '/edu/universe',
];

$kernel = \Drupal::service('http_kernel');
$failures = 0;
foreach ($paths as $path) {
  $request = \Symfony\Component\HttpFoundation\Request::create($path);
  try {
    $response = $kernel->handle($request);
    $status = $response->getStatusCode();
    $len = strlen((string) $response->getContent());
    $ok = $status === 200 && $len > 4000;
    if (!$ok) {
      $failures++;
    }
    echo str_pad($path, 62), $status, ' len=', $len, $ok ? ' OK' : ' FAIL', "\n";
  }
  catch (\Throwable $e) {
    $failures++;
    echo str_pad($path, 62), 'EXC ', get_class($e), ': ', mb_substr($e->getMessage(), 0, 90), "\n";
  }
}
echo "\nfailures: {$failures}\n";
exit($failures === 0 ? 0 : 1);
