<?php

declare(strict_types=1);

namespace Drupal\dx_migrate\Service;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\dx_channel\Service\IngestService;

/**
 * Runs L1/L2 migrate jobs into DXEP Ingest.
 */
final class MigrateRunner {

  public function __construct(
    private readonly L1HtmlAdapter $adapter,
    private readonly IngestService $ingest,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Import list page into content resources (draft by default).
   *
   * @return array{ok: bool, message: string, imported: int, failed: int, level: string, source: string}
   */
  public function runL1(string $sourceUrl, bool $dryRun = FALSE, bool $allowFixture = TRUE): array {
    $html = $this->adapter->loadHtml($sourceUrl, $allowFixture);
    $usedFixture = $sourceUrl === '' || !preg_match('#^https?://#i', trim($sourceUrl));
    $items = $this->adapter->parseList($html, $sourceUrl !== '' ? $sourceUrl : 'fixture');
    $imported = 0;
    $failed = 0;
    foreach ($items as $item) {
      $result = $this->ingest->upsert(
        'article',
        $item['external_id'],
        [
          'title' => $item['title'],
          'body' => $item['body'],
          'status' => $item['status'],
        ],
        $dryRun,
        TRUE,
      );
      if (!empty($result['ok'])) {
        $imported++;
      }
      else {
        $failed++;
      }
    }
    $msg = sprintf(
      'L1 migrate %s: imported=%d failed=%d%s',
      $dryRun ? 'dry-run' : 'upsert',
      $imported,
      $failed,
      $usedFixture && $sourceUrl === '' ? ' (fixture)' : ($usedFixture ? ' (fixture fallback)' : ''),
    );
    $this->logger->notice($msg);
    return [
      'ok' => $failed === 0,
      'message' => $msg,
      'imported' => $imported,
      'failed' => $failed,
      'level' => 'l1',
      'source' => $sourceUrl !== '' ? $sourceUrl : 'fixture',
    ];
  }

  /**
   * L2 currently reuses L1 list parse; deeper field mapping comes later.
   *
   * @return array{ok: bool, message: string, imported: int, failed: int, level: string, source: string}
   */
  public function runL2(string $sourceUrl, bool $dryRun = FALSE, bool $allowFixture = TRUE): array {
    $result = $this->runL1($sourceUrl, $dryRun, $allowFixture);
    $result['level'] = 'l2';
    $result['message'] = str_replace('L1 ', 'L2 (list-pass) ', $result['message']);
    return $result;
  }

}
