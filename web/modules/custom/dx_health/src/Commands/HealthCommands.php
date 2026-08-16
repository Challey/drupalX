<?php

declare(strict_types=1);

namespace Drupal\dx_health\Commands;

use Drupal\dx_health\Service\HealthChecker;
use Drush\Commands\DrushCommands;

/**
 * Health check Drush.
 */
final class HealthCommands extends DrushCommands {

  public function __construct(
    private readonly HealthChecker $checker,
  ) {
    parent::__construct();
  }

  /**
   * Platform health.
   *
   * @command dx:health
   */
  public function platform(): void {
    $this->io()->writeln(json_encode($this->checker->platform(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  }

  /**
   * Tenant health.
   *
   * @command dx:health-tenant
   * @param string $machine Tenant machine name
   */
  public function tenant(string $machine): void {
    $this->io()->writeln(json_encode($this->checker->tenant($machine), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  }

  /**
   * Summarize turnkey stack module readiness.
   *
   * @command dx:stack-status
   */
  public function stackStatus(): void {
    $mods = [
      'dx_delivery', 'dx_channel', 'dx_migrate', 'dx_trust', 'dx_health',
      'dx_opinion', 'dx_certs', 'dx_appstore', 'dx_ai_gateway', 'dx_payment',
      'dx_oss', 'dx_theme', 'dx_platform', 'dx_portal',
    ];
    $out = [];
    foreach ($mods as $m) {
      $out[$m] = \Drupal::moduleHandler()->moduleExists($m);
    }
    $ready = count(array_filter($out));
    $this->io()->writeln(json_encode([
      'ready' => $ready,
      'total' => count($mods),
      'modules' => $out,
      'platform_health' => $this->checker->platform()['ok'] ?? FALSE,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  }

}
