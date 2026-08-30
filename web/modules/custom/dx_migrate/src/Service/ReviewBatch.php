<?php

declare(strict_types=1);

namespace Drupal\dx_migrate\Service;

/**
 * Pure bookkeeping for review-queue batch operations (roadmap G2).
 *
 * No Drupal services and no database here on purpose: id normalisation, the
 * per-run size guard and the success/failure aggregation all live in this
 * class so they can be asserted by
 * `web/modules/custom/dx_migrate/tests/pure-assertions.php` without booting
 * the site. Every method is a plain function over arrays.
 */
final class ReviewBatch {

  /** Hard ceiling of items processed in one batch run. */
  public const MAX_ITEMS = 50;

  public const ACTION_PUBLISH = 'publish';
  public const ACTION_DISCARD = 'discard';
  public const ACTION_REPLAY = 'replay';

  public const ACTIONS = [self::ACTION_PUBLISH, self::ACTION_DISCARD, self::ACTION_REPLAY];

  /**
   * Clean up caller supplied ids and enforce the single-run ceiling.
   *
   * Blank and duplicate entries are dropped; non-numeric node ids are rejected
   * with a reason instead of being silently skipped. Anything beyond the
   * ceiling is reported as `overflow` so the operator can re-run the rest.
   *
   * @param array<array-key, mixed> $ids
   *
   * @return array{accepted: list<string>, rejected: list<array{input: string, reason: string}>, overflow: list<string>, limit: int}
   */
  public static function normalize(array $ids, int $limit = self::MAX_ITEMS, string $kind = 'nid'): array {
    $limit = max(1, min(self::MAX_ITEMS, $limit));
    $accepted = [];
    $rejected = [];
    $overflow = [];
    $seen = [];
    foreach ($ids as $raw) {
      if (is_array($raw)) {
        $rejected[] = ['input' => '?', 'reason' => 'unsupported value'];
        continue;
      }
      $value = trim((string) $raw);
      if ($value === '') {
        continue;
      }
      if ($kind === 'nid') {
        if (preg_match('/^#?(\d+)$/', $value, $m) !== 1) {
          $rejected[] = ['input' => $value, 'reason' => 'not a node id'];
          continue;
        }
        $value = $m[1];
        if ((int) $value === 0) {
          $rejected[] = ['input' => $value, 'reason' => 'node id must be positive'];
          continue;
        }
      }
      else {
        // External ids: DXEP allows a conservative printable charset.
        if (preg_match('/^[A-Za-z0-9_\-:.]{1,128}$/', $value) !== 1) {
          $rejected[] = ['input' => $value, 'reason' => 'not a valid external id'];
          continue;
        }
      }
      if (isset($seen[$value])) {
        continue;
      }
      $seen[$value] = TRUE;
      if (count($accepted) >= $limit) {
        $overflow[] = $value;
        continue;
      }
      $accepted[] = $value;
    }
    return ['accepted' => $accepted, 'rejected' => $rejected, 'overflow' => $overflow, 'limit' => $limit];
  }

  /**
   * Append one per-item outcome. Immutable: returns the new result list.
   *
   * @param list<array{key: string, ok: bool, message: string}> $results
   *
   * @return list<array{key: string, ok: bool, message: string}>
   */
  public static function record(array $results, string $key, bool $ok, string $message = ''): array {
    $results[] = ['key' => $key, 'ok' => $ok, 'message' => $message];
    return $results;
  }

  /**
   * Aggregate a result list into the report shown to the operator.
   *
   * Partial failure never rolls back the items that already succeeded, so the
   * summary has to list both sides explicitly.
   *
   * @param list<array{key: string, ok: bool, message: string}> $results
   *
   * @return array{total: int, succeeded: int, failed: int, ok: bool, succeeded_items: list<array{key: string, ok: bool, message: string}>, failed_items: list<array{key: string, ok: bool, message: string}>}
   */
  public static function summarize(array $results): array {
    $succeeded = [];
    $failed = [];
    foreach ($results as $row) {
      if (!empty($row['ok'])) {
        $succeeded[] = $row;
      }
      else {
        $failed[] = $row;
      }
    }
    return [
      'total' => count($results),
      'succeeded' => count($succeeded),
      'failed' => count($failed),
      'ok' => $results !== [] && $failed === [],
      'succeeded_items' => $succeeded,
      'failed_items' => $failed,
    ];
  }

  /**
   * Fold normalisation output and per-item results into one report payload.
   *
   * @param array{accepted: list<string>, rejected: list<array{input: string, reason: string}>, overflow: list<string>, limit: int} $plan
   * @param list<array{key: string, ok: bool, message: string}> $results
   *
   * @return array<string, mixed>
   */
  public static function report(string $action, array $plan, array $results): array {
    $summary = self::summarize($results);
    return $summary + [
      'action' => $action,
      'limit' => $plan['limit'],
      'requested' => count($plan['accepted']) + count($plan['rejected']) + count($plan['overflow']),
      'rejected' => $plan['rejected'],
      'overflow' => $plan['overflow'],
      'results' => $results,
    ];
  }

  /**
   * Is this a batch action we know how to run?
   */
  public static function isAction(mixed $action): bool {
    return is_string($action) && in_array($action, self::ACTIONS, TRUE);
  }

}
