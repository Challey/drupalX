<?php

declare(strict_types=1);

/**
 * Rebuild the versioned offline ZIP fixture `demo-package.zip`.
 *
 * Not part of the runtime: run it manually whenever `demo-package.json` changes
 * (the ZIP carries the same document under a different `package_id`, plus the
 * SHA-256 ledger that registration now demands).
 *
 *   php web/modules/custom/dx_channel/data/packages/make-demo-zip.php
 *
 * Pure PHP: requires only the ExchangeChecksums class file (it has no Drupal
 * dependencies), so it runs without a bootstrap, a database or the network.
 */

$moduleDir = dirname(__DIR__, 2);
require_once $moduleDir . '/src/Service/ExchangeChecksums.php';

use Drupal\dx_channel\Service\ExchangeChecksums;

$source = $moduleDir . '/data/packages/demo-package.json';
$target = $moduleDir . '/data/packages/demo-package.zip';

$raw = @file_get_contents($source);
if ($raw === FALSE) {
  fwrite(STDERR, "cannot read {$source}\n");
  exit(1);
}
$package = json_decode((string) $raw, TRUE);
if (!is_array($package)) {
  fwrite(STDERR, "demo-package.json is not valid JSON\n");
  exit(1);
}

// The ZIP fixture must not collide with the JSON fixture in the package state.
$package['manifest']['package_id'] = 'pkg_demo_zip_fixture';
$package['manifest']['source']['system'] = 'fixture-zip';
unset($package['manifest']['content_sha256']);

$json = json_encode($package, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
if ($json === FALSE) {
  fwrite(STDERR, "cannot encode package document\n");
  exit(1);
}

$files = ['package.json' => $json];
$ledger = ExchangeChecksums::renderLedger(
  ExchangeChecksums::ledger($files),
  (string) $package['manifest']['package_id'],
);

if (!class_exists(ZipArchive::class)) {
  fwrite(STDERR, "ZipArchive extension is required\n");
  exit(1);
}
$zip = new ZipArchive();
if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
  fwrite(STDERR, "cannot write {$target}\n");
  exit(1);
}
$zip->addFromString('package.json', $json);
$zip->addFromString(ExchangeChecksums::LEDGER_NAME, $ledger);
$zip->close();

printf(
  "wrote %s (%d bytes)\n%s\n",
  $target,
  filesize($target) ?: 0,
  $ledger,
);
