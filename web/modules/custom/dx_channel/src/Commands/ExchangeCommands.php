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
   * ZIP must contain package.json (same schema as the JSON file).
   *
   * @command dx:exchange-package-register
   * @param string $path Path to package JSON or ZIP
   * @usage dx:exchange-package-register web/modules/custom/dx_channel/data/packages/demo-package.json
   */
  public function register(string $path): void {
    $result = $this->exchange->registerFromPath($path);
    $this->io()->writeln(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    if (empty($result['ok'])) {
      throw new \RuntimeException('Package registration failed');
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
   * @command dx:exchange-package-apply
   * @option dry-run Validate without writing
   * @param string $packageId Package id
   * @usage dx:exchange-package-apply pkg_demo --dry-run
   */
  public function apply(string $packageId, array $options = ['dry-run' => FALSE]): void {
    $result = $this->exchange->apply($packageId, !empty($options['dry-run']));
    $this->io()->writeln(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    if (empty($result['ok']) && (($result['report']['error'] ?? '') === 'package not found')) {
      throw new \RuntimeException('Package not found');
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
