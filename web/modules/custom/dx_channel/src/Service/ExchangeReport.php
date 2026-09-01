<?php

declare(strict_types=1);

namespace Drupal\dx_channel\Service;

/**
 * Paging, failure extraction and retry folding for exchange apply reports
 * (roadmap G3).
 *
 * An apply report lists one row per resource, and production packages are
 * allowed up to 500 resources, so the HTTP payload must be sliceable without
 * losing the aggregate counters. Retrying a failed package must also be able to
 * merge its new per-item outcomes into the stored report without duplicating a
 * row that was already applied — both are pure array transforms living here so
 * they are assertable without a database.
 */
final class ExchangeReport {

  public const DEFAULT_PAGE_SIZE = 25;
  public const MAX_PAGE_SIZE = 200;

  /**
   * Slice the per-item rows of a report for one page.
   *
   * Aggregate counters (`applied`, `failed`, `total_*`) always describe the
   * whole run, never the visible page, so a client can render "page 3 of 7,
   * 414 items, 2 failed" from any response.
   *
   * @param array<string, mixed> $report
   *
   * @return array<string, mixed>
   */
  public static function paginate(array $report, int $page = 1, int $pageSize = self::DEFAULT_PAGE_SIZE): array {
    // A bogus page size falls back to the default instead of producing 200 pages.
    $pageSize = $pageSize < 1 ? self::DEFAULT_PAGE_SIZE : min(self::MAX_PAGE_SIZE, $pageSize);
    $items = is_array($report['items'] ?? NULL) ? array_values($report['items']) : [];
    $total = count($items);
    $pages = $total === 0 ? 1 : (int) ceil($total / $pageSize);
    $requested = $page;
    $page = max(1, min($pages, $page));
    $clamped = $requested !== $page;

    $out = $report;
    $out['items'] = array_slice($items, ($page - 1) * $pageSize, $pageSize);
    $out['page'] = $page;
    $out['page_size'] = $pageSize;
    $out['total_pages'] = $pages;
    $out['total_items'] = $total;
    $out['page_clamped'] = $clamped;
    return $out;
  }

  /**
   * Rows that did not apply, ready for a retry.
   *
   * @param array<string, mixed> $report
   *
   * @return list<array{key: string, type: string, external_id: string, issues: mixed}>
   */
  public static function failedItems(array $report): array {
    $out = [];
    foreach (self::items($report) as $row) {
      if (!empty($row['ok'])) {
        continue;
      }
      $type = (string) ($row['type'] ?? 'article');
      $externalId = (string) ($row['external_id'] ?? '');
      $out[] = [
        'key' => self::key($type, $externalId),
        'type' => $type,
        'external_id' => $externalId,
        'issues' => $row['issues'] ?? [],
      ];
    }
    return $out;
  }

  /**
   * The `type:external_id` identity used by DXEP Ingest upserts.
   */
  public static function key(string $type, string $externalId): string {
    return $type . ':' . $externalId;
  }

  /**
   * One row per `type:external_id`, newest outcome winning.
   *
   * A retry therefore cannot grow the report: re-applying an item that already
   * succeeded replaces its row instead of appending a duplicate. Each row also
   * carries how often it has been attempted, which is what lets ops tell a first
   * failure apart from a resource that keeps failing.
   *
   * @param array<string, mixed> $previous
   * @param array<string, mixed> $incoming
   *
   * @return list<array<string, mixed>>
   */
  public static function mergeItems(array $previous, array $incoming): array {
    $rows = [];
    foreach ([self::items($previous), self::items($incoming)] as $set) {
      foreach ($set as $row) {
        if (!is_array($row)) {
          continue;
        }
        $key = self::key((string) ($row['type'] ?? 'article'), (string) ($row['external_id'] ?? ''));
        if ($key === 'article:') {
          // Unidentified rows (e.g. a registration-level error) keep their slot.
          $rows['#' . count($rows)] = $row + ['attempts' => 1];
          continue;
        }
        $seen = $rows[$key] ?? NULL;
        if ($seen !== NULL) {
          $row['attempts'] = (int) ($seen['attempts'] ?? 1) + 1;
        }
        $rows[$key] = $row + ['attempts' => 1];
      }
    }
    return array_values($rows);
  }

  /**
   * Recount a merged item list.
   *
   * @param list<array<string, mixed>> $items
   *
   * @return array{applied: int, failed: int, total: int}
   */
  public static function summarize(array $items): array {
    $applied = 0;
    $failed = 0;
    foreach ($items as $row) {
      if (!empty($row['ok'])) {
        $applied++;
        continue;
      }
      $failed++;
    }
    return ['applied' => $applied, 'failed' => $failed, 'total' => count($items)];
  }

  /**
   * Rebuild a whole report (counters + rows) after a retry run.
   *
   * @param array<string, mixed> $previous the stored report
   * @param array<string, mixed> $incoming the fresh retry report
   *
   * @return array<string, mixed>
   */
  public static function mergeReports(array $previous, array $incoming): array {
    $items = self::mergeItems($previous, $incoming);
    $counts = self::summarize($items);
    $out = $previous + ['applied_at' => gmdate('c'), 'dry_run' => FALSE];
    $out['items'] = $items;
    $out['applied'] = $counts['applied'];
    $out['failed'] = $counts['failed'];
    $out['retried_at'] = (string) ($incoming['applied_at'] ?? gmdate('c'));
    $out['retry_count'] = (int) ($previous['retry_count'] ?? 0) + 1;
    if ($counts['failed'] === 0) {
      $out['retries_exhausted'] = FALSE;
    }
    return $out;
  }

  /**
   * Has this report already been retried as often as we are willing to?
   *
   * @param array<string, mixed> $report
   */
  public static function retriesExhausted(array $report, int $max = 5): bool {
    return (int) ($report['retry_count'] ?? 0) >= max(1, $max);
  }

  /**
   * The machine-readable summary line for logs and `dx:exchange:report`.
   *
   * @param array<string, mixed> $report
   */
  public static function message(array $report): string {
    $counts = self::summarize(self::items($report));
    return sprintf(
      'apply %s: applied=%d failed=%d items=%d',
      empty($report['dry_run']) ? 'run' : 'dry-run',
      $counts['applied'],
      $counts['failed'],
      $counts['total'],
    );
  }

  /**
   * @param array<string, mixed> $report
   *
   * @return list<array<string, mixed>>
   */
  public static function items(array $report): array {
    $items = $report['items'] ?? [];
    if (!is_array($items)) {
      return [];
    }
    return array_values(array_filter($items, 'is_array'));
  }

}
