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

  /**
   * Build an Exchange package JSON from L1 list fixture/template (no apply).
   *
   * @command dx:migrate-package
   * @option template List template
   * @option package-id Override package id
   * @param string $sourceUrl Optional list URL
   */
  public function migratePackage(string $sourceUrl = '', array $options = [
    'template' => 'gov_news',
    'package-id' => '',
  ]): void {
    if (!\Drupal::hasService('dx_channel.exchange')) {
      throw new \RuntimeException('dx_channel.exchange missing');
    }
    /** @var \Drupal\dx_migrate\Service\L1HtmlAdapter $adapter */
    $adapter = \Drupal::service('dx_migrate.l1_html');
    $template = (string) ($options['template'] ?: 'gov_news');
    $html = $adapter->loadHtml($sourceUrl, TRUE, $template);
    $items = $adapter->parseList($html, $sourceUrl !== '' ? $sourceUrl : 'fixture', $template);
    $resources = [];
    foreach ($items as $item) {
      $resources[] = [
        'type' => 'article',
        'external_id' => $item['external_id'],
        'title' => $item['title'],
        'body' => $item['body'],
        'status' => 'draft',
      ];
    }
    $packageId = (string) ($options['package-id'] ?: ('pkg_mig_' . substr(hash('sha256', $template . count($resources)), 0, 10)));
    $body = [
      'manifest' => [
        'spec' => 'DXEP',
        'spec_version' => '1.0',
        'package_id' => $packageId,
        'tenant_id' => 'platform',
        'created_at' => gmdate('c'),
        'source' => ['system' => 'dx_migrate', 'base_url' => $sourceUrl ?: 'fixture'],
        'counts' => ['article' => count($resources)],
        'mode' => 'incremental',
        'require_review' => TRUE,
      ],
      'resources' => $resources,
    ];
    /** @var \Drupal\dx_channel\Service\ExchangeService $exchange */
    $exchange = \Drupal::service('dx_channel.exchange');
    $result = $exchange->register($body);
    $this->io()->writeln(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    if (empty($result['ok'])) {
      throw new \RuntimeException('Package registration failed');
    }
  }

  /**
   * List pending migrate review drafts (JSON).
   *
   * @command dx:migrate-review-list
   * @option bundle Filter by node bundle
   */
  public function reviewList(array $options = ['bundle' => '']): void {
    /** @var \Drupal\dx_channel\Service\IngestService $ingest */
    $ingest = \Drupal::service('dx_channel.ingest');
    $map = $ingest->getExternalMap();
    $nidToKeys = [];
    foreach ($map as $key => $nid) {
      $nidToKeys[(int) $nid][] = (string) $key;
    }
    $bundleFilter = trim((string) ($options['bundle'] ?? ''));
    $items = [];
    if ($nidToKeys !== []) {
      foreach (\Drupal::entityTypeManager()->getStorage('node')->loadMultiple(array_keys($nidToKeys)) as $node) {
        if (!$node instanceof \Drupal\node\NodeInterface || $node->isPublished()) {
          continue;
        }
        if ($bundleFilter !== '' && $node->bundle() !== $bundleFilter) {
          continue;
        }
        $items[] = [
          'nid' => (int) $node->id(),
          'title' => $node->label(),
          'bundle' => $node->bundle(),
          'external_ids' => $nidToKeys[(int) $node->id()] ?? [],
        ];
      }
    }
    $this->io()->writeln(json_encode([
      'ok' => TRUE,
      'pending' => count($items),
      'items' => $items,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  }

}
