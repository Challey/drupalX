<?php

declare(strict_types=1);

namespace Drupal\dx_migrate\Commands;

use Drupal\dx_migrate\Service\L2TemplateException;
use Drupal\dx_migrate\Service\L2TemplateRegistry;
use Drupal\dx_migrate\Service\MigrateRunner;
use Drupal\dx_migrate\Service\ReviewBatch;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for L1/L2 migrate.
 */
final class MigrateCommands extends DrushCommands {

  /** @var \Drupal\dx_migrate\Service\L2TemplateRegistry|NULL */
  private ?L2TemplateRegistry $library = NULL;

  public function __construct(
    private readonly MigrateRunner $runner,
    private readonly ?L2TemplateRegistry $templates = NULL,
  ) {
    parent::__construct();
  }

  /**
   * The template library (injected, or resolved from the container once).
   */
  private function library(): L2TemplateRegistry {
    return $this->library ??= ($this->templates ?? \Drupal::service('dx_migrate.l2_templates'));
  }

  /**
   * Resolve --template without a silent fallback (G1: bad input must be loud).
   *
   * @return array<string, mixed>
   *   The validated definition, so callers can read resource defaults too.
   *
   * @throws \Drupal\dx_migrate\Service\L2TemplateException
   */
  private function resolveTemplate(string $template): array {
    $template = trim($template);
    return $this->library()->definition($template === '' ? 'auto' : $template);
  }

  /**
   * Run L1 HTML list migrate into DXEP Ingest.
   *
   * @command dx:migrate-l1
   * @option dry-run Validate without writing nodes
   * @option no-fixture Fail instead of using bundled fixture
   * @option template Field-mapping template machine name (see dx:migrate-templates)
   * @param string $sourceUrl Legacy list URL (optional — uses fixture when empty)
   * @usage dx:migrate-l1 https://example.gov/news/
   * @usage dx:migrate-l1 --dry-run --template=gov_news
   */
  public function migrateL1(string $sourceUrl = '', array $options = [
    'dry-run' => FALSE,
    'no-fixture' => FALSE,
    'template' => 'auto',
  ]): void {
    $def = $this->resolveTemplate((string) ($options['template'] ?: 'auto'));
    $template = (string) ($def['machine_name'] ?? 'auto');
    $result = $this->runner->runL1(
      $sourceUrl,
      !empty($options['dry-run']),
      empty($options['no-fixture']),
      $template,
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
   * @option template Field-mapping template machine name (see dx:migrate-templates)
   * @option limit Max detail pages to enrich (default: the template's own limit)
   * @param string $sourceUrl Legacy list URL (optional — uses fixture when empty)
   * @usage dx:migrate-l2 --template=gov_news --dry-run
   * @usage dx:migrate-l2 https://example.gov/news/ --limit=5
   * @usage dx:migrate-l2 --template=hospital_notice
   */
  public function migrateL2(string $sourceUrl = '', array $options = [
    'dry-run' => FALSE,
    'no-fixture' => FALSE,
    'template' => 'auto',
    'limit' => NULL,
  ]): void {
    $def = $this->resolveTemplate((string) ($options['template'] ?: 'auto'));
    $template = (string) ($def['machine_name'] ?? 'auto');
    $limit = $options['limit'] === NULL || $options['limit'] === ''
      ? (int) ($def['resource']['detail_limit'] ?? 10)
      : (int) $options['limit'];
    $result = $this->runner->runL2(
      $sourceUrl,
      !empty($options['dry-run']),
      empty($options['no-fixture']),
      $template,
      $limit,
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
   * @option template Field-mapping template machine name
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
    $def = $this->resolveTemplate((string) ($options['template'] ?: 'gov_news'));
    $template = (string) ($def['machine_name'] ?? 'gov_news');
    $type = (string) ($def['resource']['type'] ?? 'article');
    /** @var \Drupal\dx_migrate\Service\L1HtmlAdapter $adapter */
    $adapter = \Drupal::service('dx_migrate.l1_html');
    $html = $adapter->loadHtml($sourceUrl, TRUE, $template);
    $items = $adapter->parseList($html, $sourceUrl !== '' ? $sourceUrl : 'fixture', $template);
    $resources = [];
    foreach ($items as $item) {
      $resources[] = [
        'type' => $type,
        'external_id' => $item['external_id'],
        'title' => $item['title'],
        'body' => $item['body'],
        'status' => $item['status'],
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
        'counts' => [$type => count($resources)],
        'mode' => 'incremental',
        'require_review' => (bool) ($def['resource']['review'] ?? TRUE),
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

  /**
   * List the declarative L2 field-mapping templates (G1).
   *
   * @command dx:migrate-templates
   * @usage dx:migrate-templates
   */
  public function templateList(): void {
    $rows = [];
    foreach ($this->library()->describe() as $row) {
      $rows[] = [
        $row['machine_name'],
        $row['label'],
        $row['internal'] ? 'internal parent' : ($row['ok'] ? 'ok' : 'INVALID'),
        $row['resource_type'],
        $row['list_fixture'],
      ];
    }
    $this->io()->table(['Template', 'Label', 'Status', 'Type', 'Fixture'], $rows);
    $invalid = $this->library()->invalid();
    foreach ($invalid as $name => $issues) {
      $this->io()->error(sprintf('Template "%s" is invalid: %s', $name, L2TemplateException::formatIssues($issues)));
    }
    $this->io()->writeln(sprintf('Directories: %s', implode(', ', $this->library()->directories())));
  }

  /**
   * Validate a field-mapping template file or a loaded template name (G1).
   *
   * @command dx:migrate-template-validate
   * @option template Validate an installed template by machine name instead
   * @param string $file Absolute or relative path to a candidate template file
   * @usage dx:migrate-template-validate web/modules/custom/dx_migrate/data/templates/hospital_notice.yml
   * @usage dx:migrate-template-validate --template=gov_news
   */
  public function templateValidate(string $file = '', array $options = ['template' => '']): void {
    $template = trim((string) ($options['template'] ?? ''));
    try {
      if ($template !== '') {
        $def = $this->library()->definition($template);
        $this->io()->writeln(json_encode($def, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $this->io()->success(sprintf('Template "%s" is valid.', $template));
        return;
      }
      if (trim($file) === '') {
        throw new \InvalidArgumentException('Give a template file path or --template=<machine_name>.');
      }
      $def = $this->library()->loadFile($file);
      $this->io()->writeln(json_encode($def, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
      $this->io()->success(sprintf('File %s is a valid template (machine name: %s).', $file, (string) $def['machine_name']));
    }
    catch (L2TemplateException $e) {
      $this->io()->error($e->getMessage());
      foreach ($e->issues() as $issue) {
        $this->io()->writeln(sprintf('  - %s: %s', $issue['field'], $issue['issue']));
      }
      throw $e;
    }
  }

  /**
   * Batch publish / discard / replay review-queue items (G2).
   *
   * Successful items are never rolled back when a later one fails; the JSON
   * report lists both sides.
   *
   * @command dx:migrate-review-batch
   * @option limit Max items in this run (default and ceiling 50)
   * @option dry-run Only list what would be processed (no writes)
   * @param string $action publish|discard|replay
   * @param string $ids Comma/space separated node ids (publish/discard) or external ids (replay)
   * @usage dx:migrate-review-batch publish 12,13,14
   * @usage dx:migrate-review-batch replay l1_3f2a8c9d0e1b2a34
   * @usage dx:migrate-review-batch discard --limit=5 "12 13"
   */
  public function reviewBatch(string $action = '', string $ids = '', array $options = [
    'limit' => ReviewBatch::MAX_ITEMS,
    'dry-run' => FALSE,
  ]): void {
    if (!ReviewBatch::isAction($action)) {
      throw new \InvalidArgumentException('Action must be one of: ' . implode(', ', ReviewBatch::ACTIONS) . '.');
    }
    /** @var array<array-key, mixed> $raw */
    $raw = preg_split('/[\s,;]+/', trim($ids)) ?: [];
    $raw = array_values(array_filter($raw, static fn($v): bool => trim((string) $v) !== ''));
    $limit = max(1, min(ReviewBatch::MAX_ITEMS, (int) ($options['limit'] ?: ReviewBatch::MAX_ITEMS)));

    if (!empty($options['dry-run'])) {
      $plan = ReviewBatch::normalize($raw, $limit, $action === ReviewBatch::ACTION_REPLAY ? 'external_id' : 'nid');
      $this->io()->writeln(json_encode([
        'ok' => TRUE,
        'dry_run' => TRUE,
        'action' => $action,
        'limit' => $plan['limit'],
        'would_process' => $plan['accepted'],
        'rejected' => $plan['rejected'],
        'overflow' => $plan['overflow'],
      ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
      return;
    }

    /** @var \Drupal\dx_migrate\Service\ReviewBatchRunner $batch */
    $batch = \Drupal::service('dx_migrate.review_batch');
    $report = $batch->run($action, $raw, $limit);
    $this->io()->writeln(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    if (empty($report['ok'])) {
      $this->io()->warning(sprintf('%d item(s) failed; %d succeeded and were kept.', $report['failed'], $report['succeeded']));
    }
  }

}
