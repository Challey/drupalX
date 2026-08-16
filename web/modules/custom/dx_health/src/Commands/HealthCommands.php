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

}
