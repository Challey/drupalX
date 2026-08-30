<?php

declare(strict_types=1);

namespace Drupal\dx_channel\Commands;

use Drupal\dx_channel\Service\ExchangeService;
use Drush\Commands\DrushCommands;

/**
 * Drush helpers for DXEP Exchange packages.
 */
final class ExchangeCommands extends DrushCommands {

  public function __construct(
    private readonly ExchangeService $exchange,
  ) {
    parent::__construct();
  }

  /**
   * Register an Exchange package from a JSON or ZIP file.
   *
   * ZIP must contain package.json (same schema as the JSON file) plus a
   * checksums.sha256 ledger; an unverifiable archive is refused with a stable
   * error code.
   *
   * @command dx:exchange-package-register
   * @param string $path Path to package JSON or ZIP
   * @usage dx:exchange-package-register web/modules/custom/dx_channel/data/packages/demo-package.json
   */
  public function register(string $path): void {
    $result = $this->exchange->registerFromPath($path);
    $this->io()->writeln(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    if (empty($result['ok'])) {
      $code = (string) ($result['error_code'] ?? '');
      throw new \RuntimeException($code !== '' ? 'Package rejected: ' . $code : 'Package registration failed');
    }
  }

  /**
   * Export a registered package as offline ZIP (package.json inside).
   *
   * @command dx:exchange-package-export
   * @param string $packageId Package id
   * @param string $outPath Destination .zip path
   */
  public function exportZip(string $packageId, string $outPath): void {
    $bytes = $this->exchange->exportZip($packageId);
    if ($bytes === NULL) {
      throw new \RuntimeException('Package not found or ZIP unavailable');
    }
    if (@file_put_contents($outPath, $bytes) === FALSE) {
      throw new \RuntimeException('Cannot write ' . $outPath);
    }
    $this->io()->writeln(json_encode([
      'ok' => TRUE,
      'package_id' => $packageId,
      'path' => $outPath,
      'bytes' => strlen($bytes),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  }

  /**
   * Apply a registered Exchange package.
   *
   * Refused with a stable error code when the sealed checksum no longer matches
   * the stored content (roadmap G3).
   *
   * @command dx:exchange-package-apply
   * @option dry-run Validate without writing
   * @option page Report page to return (1-based)
   * @option page-size Per-item rows in the returned report (max 200)
   * @param string $packageId Package id
   * @usage dx:exchange-package-apply pkg_demo --dry-run
   */
  public function apply(string $packageId, array $options = ['dry-run' => FALSE, 'page' => 1, 'page-size' => 200]): void {
    $result = $this->exchange->apply($packageId, !empty($options['dry-run']), [
      'page' => max(1, (int) ($options['page'] ?: 1)),
      'page_size' => max(1, (int) ($options['page-size'] ?: 200)),
    ]);
    $this->io()->writeln(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    if (empty($result['ok']) && (($result['report']['error'] ?? '') === 'package not found')) {
      throw new \RuntimeException('Package not found');
    }
    if (($result['error_code'] ?? '') !== '') {
      throw new \RuntimeException('Apply refused: ' . $result['error_code']);
    }
  }

  /**
   * Re-apply only the items that failed last time.
   *
   * Idempotent: already applied resources are not replayed, and a replayed
   * resource updates the same node (Ingest keys on type:external_id).
   *
   * @command dx:exchange-package-retry
   * @option dry-run Validate without writing
   * @param string $packageId Package id
   */
  public function retryFailed(string $packageId, array $options = ['dry-run' => FALSE]): void {
    $result = $this->exchange->retryFailed($packageId, !empty($options['dry-run']));
    $this->io()->writeln(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    if (empty($result['ok']) && (($result['report']['error'] ?? '') !== '')) {
      throw new \RuntimeException((string) $result['report']['error']);
    }
  }

  /**
   * Show the stored apply report, paged.
   *
   * @command dx:exchange-package-report
   * @option page Page number (1-based)
   * @option page-size Rows per page (max 200)
   * @option failed-only Print only the failed rows
   * @param string $packageId Package id
   */
  public function report(string $packageId, array $options = ['page' => 1, 'page-size' => 25, 'failed-only' => FALSE]): void {
    $report = $this->exchange->report(
      $packageId,
      max(1, (int) ($options['page'] ?: 1)),
      max(1, (int) ($options['page-size'] ?: 25)),
    );
    if ($report === NULL) {
      throw new \RuntimeException('Package not found');
    }
    if (!empty($options['failed-only'])) {
      $report = $report + ['items' => $report['failed_items'] ?? []];
    }
    $this->io()->writeln(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  }

  /**
   * Verify the checksum sealed at registration against the stored content.
   *
   * @command dx:exchange-package-verify
   * @param string $packageId Package id
   */
  public function verify(string $packageId): void {
    $integrity = $this->exchange->integrity($packageId);
    if ($integrity === NULL) {
      throw new \RuntimeException('Package not found');
    }
    $this->io()->writeln(json_encode($integrity, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    if (empty($integrity['apply_allowed'])) {
      throw new \RuntimeException('Verification failed: ' . (string) ($integrity['apply_error_code'] ?? 'unknown'));
    }
  }

  /**
   * Verify the SHA-256 ledger of an offline ZIP file without registering it.
   *
   * @command dx:exchange-package-verify-archive
   * @param string $path Path to a .zip produced by dx:exchange-package-export
   * @usage dx:exchange-package-verify-archive /tmp/dx-ex-export.zip
   */
  public function verifyArchive(string $path): void {
    $result = $this->exchange->verifyArchive($path);
    $this->io()->writeln(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    if (empty($result['ok'])) {
      throw new \RuntimeException('Archive rejected: ' . (string) ($result['code'] ?? 'unknown'));
    }
  }

  /**
   * List Exchange packages.
   *
   * @command dx:exchange-package-list
   */
  public function listPackages(): void {
    $this->io()->writeln(json_encode($this->exchange->listPackages(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  }

}
