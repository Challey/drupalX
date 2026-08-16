<?php

declare(strict_types=1);

namespace Drupal\dx_migrate\Commands;

use Drupal\dx_migrate\Service\MigrateRunner;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for L1/L2 migrate.
 */
final class MigrateCommands extends DrushCommands {

  public function __construct(
    private readonly MigrateRunner $runner,
  ) {
    parent::__construct();
  }

  /**
   * Run L1 HTML list migrate into DXEP Ingest.
   *
   * @command dx:migrate-l1
   * @option dry-run Validate without writing nodes
   * @option no-fixture Fail instead of using bundled fixture
   * @param string $sourceUrl Legacy list URL (optional — uses fixture when empty)
   * @usage dx:migrate-l1 https://example.gov/news/
   * @usage dx:migrate-l1 --dry-run
   */
  public function migrateL1(string $sourceUrl = '', array $options = [
    'dry-run' => FALSE,
    'no-fixture' => FALSE,
  ]): void {
    $result = $this->runner->runL1(
      $sourceUrl,
      !empty($options['dry-run']),
      empty($options['no-fixture']),
    );
    $this->io()->writeln(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    if (empty($result['ok'])) {
      throw new \RuntimeException($result['message']);
    }
  }

}
