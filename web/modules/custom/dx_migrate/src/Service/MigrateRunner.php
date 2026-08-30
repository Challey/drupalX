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
    private readonly ?ReviewPayloadStore $snapshots = NULL,
  ) {}

  /**
   * Import list page into content resources (draft by default).
   *
   * @return array{ok: bool, message: string, imported: int, failed: int, level: string, source: string, template: string}
   */
  public function runL1(string $sourceUrl, bool $dryRun = FALSE, bool $allowFixture = TRUE, string $template = 'auto'): array {
    $html = $this->adapter->loadHtml($sourceUrl, $allowFixture, $template);
    $usedFixture = $sourceUrl === '' || !preg_match('#^https?://#i', trim($sourceUrl));
    $items = $this->adapter->parseList($html, $sourceUrl !== '' ? $sourceUrl : 'fixture', $template);
    $type = $this->adapter->resourceType($template);
    $imported = 0;
    $failed = 0;
    foreach ($items as $item) {
      $payload = [
        'title' => $item['title'],
        'body' => $item['body'],
        'status' => $item['status'],
      ];
      $result = $this->ingest->upsert(
        $type,
        $item['external_id'],
        $payload,
        $dryRun,
        TRUE,
      );
      if (!empty($result['ok'])) {
        $this->snapshots?->remember($type, $item['external_id'], $payload, $sourceUrl, $template, $dryRun);
        $imported++;
      }
      else {
        $failed++;
      }
    }
    $msg = sprintf(
      'L1 migrate %s: imported=%d failed=%d template=%s%s',
      $dryRun ? 'dry-run' : 'upsert',
      $imported,
      $failed,
      $template,
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
      'template' => $template,
    ];
  }

  /**
   * L2: list parse + detail page body/meta enrichment via Ingest.
   *
   * @return array{ok: bool, message: string, imported: int, failed: int, details: int, level: string, source: string, template: string}
   */
  public function runL2(string $sourceUrl, bool $dryRun = FALSE, bool $allowFixture = TRUE, string $template = 'auto', int $detailLimit = 10): array {
    $html = $this->adapter->loadHtml($sourceUrl, $allowFixture, $template);
    $usedFixture = $sourceUrl === '' || !preg_match('#^https?://#i', trim($sourceUrl));
    $items = $this->adapter->parseList($html, $sourceUrl !== '' ? $sourceUrl : 'fixture', $template);
    $type = $this->adapter->resourceType($template);
    $imported = 0;
    $failed = 0;
    $details = 0;
    $limit = max(1, min(40, $detailLimit));

    foreach (array_slice($items, 0, $limit) as $item) {
      $href = (string) ($item['href'] ?? '');
      $detailHtml = $this->adapter->loadDetailHtml($href, $sourceUrl, $allowFixture, $template);
      $payload = [
        'title' => $item['title'],
        'body' => $item['body'],
        'status' => $item['status'],
      ];
      if (is_string($detailHtml) && $detailHtml !== '') {
        $detail = $this->adapter->parseDetail($detailHtml, $template);
        if ($detail['title'] !== '') {
          $payload['title'] = $detail['title'];
        }
        $body = $detail['body_html'];
        $metaHtml = $this->adapter->detailMetaHtml($detail, $template);
        if ($metaHtml !== '') {
          $body = $metaHtml . $body;
        }
        $payload['body'] = ['html' => $body];
        $details++;
      }

      $result = $this->ingest->upsert(
        $type,
        $item['external_id'],
        $payload,
        $dryRun,
        TRUE,
      );
      if (!empty($result['ok'])) {
        $this->snapshots?->remember($type, $item['external_id'], $payload, $sourceUrl, $template, $dryRun);
        $imported++;
      }
      else {
        $failed++;
      }
    }

    $msg = sprintf(
      'L2 migrate %s: imported=%d failed=%d details=%d template=%s%s',
      $dryRun ? 'dry-run' : 'upsert',
      $imported,
      $failed,
      $details,
      $template,
      $usedFixture && $sourceUrl === '' ? ' (fixture)' : ($usedFixture ? ' (fixture fallback)' : ''),
    );
    $this->logger->notice($msg);
    return [
      'ok' => $failed === 0 && $imported > 0,
      'message' => $msg,
      'imported' => $imported,
      'failed' => $failed,
      'details' => $details,
      'level' => 'l2',
      'source' => $sourceUrl !== '' ? $sourceUrl : 'fixture',
      'template' => $template,
    ];
  }

}
