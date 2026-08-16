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
   * @option template List template: auto|gov_news|ent_article|legacy
   * @param string $sourceUrl Legacy list URL (optional — uses fixture when empty)
   * @usage dx:migrate-l1 https://example.gov/news/
   * @usage dx:migrate-l1 --dry-run --template=gov_news
   */
  public function migrateL1(string $sourceUrl = '', array $options = [
    'dry-run' => FALSE,
    'no-fixture' => FALSE,
    'template' => 'auto',
  ]): void {
    $result = $this->runner->runL1(
      $sourceUrl,
      !empty($options['dry-run']),
      empty($options['no-fixture']),
      (string) ($options['template'] ?: 'auto'),
    );
    $this->io()->writeln(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    if (empty($result['ok'])) {
      throw new \RuntimeException($result['message']);
    }
  }

  /**
   * Run L2 list+detail migrate into DXEP Ingest.
   *
   * @command dx:migrate-l2
   * @option dry-run Validate without writing nodes
   * @option no-fixture Fail instead of using bundled fixture
   * @option template List template: auto|gov_news|ent_article|legacy
   * @option limit Max detail pages to enrich (default 10)
   * @param string $sourceUrl Legacy list URL (optional — uses fixture when empty)
   * @usage dx:migrate-l2 --template=gov_news --dry-run
   * @usage dx:migrate-l2 https://example.gov/news/ --limit=5
   */
  public function migrateL2(string $sourceUrl = '', array $options = [
    'dry-run' => FALSE,
    'no-fixture' => FALSE,
    'template' => 'auto',
    'limit' => 10,
  ]): void {
    $result = $this->runner->runL2(
      $sourceUrl,
      !empty($options['dry-run']),
      empty($options['no-fixture']),
      (string) ($options['template'] ?: 'auto'),
      (int) ($options['limit'] ?: 10),
    );
    $this->io()->writeln(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    if (empty($result['ok'])) {
      throw new \RuntimeException($result['message']);
    }
  }

}
