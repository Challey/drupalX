<?php

declare(strict_types=1);

namespace Drupal\dx_migrate\Service;

use Drupal\Core\State\StateInterface;

/**
 * Snapshot store behind "replay by external id" (roadmap G2).
 *
 * dx_migrate ingests drafts and then throws the parsed payload away, so a
 * rejected draft could only be rebuilt by re-fetching the legacy portal. Each
 * successful ingest therefore leaves a bounded snapshot of the exact DXEP
 * payload here, keyed like the Ingest map (`type:external_id`), which lets the
 * review queue replay a single item on demand.
 *
 * The pruning / lookup logic is pure and static so it can be asserted without
 * a database.
 */
final class ReviewPayloadStore {

  public const STATE_KEY = 'dx_migrate.review_payloads';

  /** Snapshots are kept newest-last; older entries are dropped beyond this. */
  public const MAX_SNAPSHOTS = 500;

  public function __construct(
    private readonly StateInterface $state,
  ) {}

  /**
   * Every snapshot keyed by `type:external_id`.
   *
   * @return array<string, array<string, mixed>>
   */
  public function all(): array {
    $store = $this->state->get(self::STATE_KEY, []);
    return is_array($store) ? $store : [];
  }

  /**
   * Remember the payload that produced (or would produce) one resource.
   *
   * @param array<string, mixed> $payload
   */
  public function remember(
    string $type,
    string $externalId,
    array $payload,
    string $source = '',
    string $template = '',
    bool $dryRun = FALSE,
  ): void {
    if ($dryRun || $type === '' || $externalId === '') {
      return;
    }
    $row = [
      'type' => $type,
      'external_id' => $externalId,
      'payload' => $payload,
      'source' => $source,
      'template' => $template,
      'captured_at' => time(),
    ];
    $this->state->set(self::STATE_KEY, self::put($this->all(), $type . ':' . $externalId, $row));
  }

  /**
   * Fetch one snapshot by type + external id (or the first match by external id).
   *
   * @return array<string, mixed>|NULL
   */
  public function find(string $externalId, string $type = ''): ?array {
    return self::lookup($this->all(), $externalId, $type);
  }

  /**
   * Drop a snapshot (used after a discard so replays report honestly).
   */
  public function forget(string $type, string $externalId): void {
    $store = $this->all();
    $key = $type . ':' . $externalId;
    if (!isset($store[$key])) {
      foreach (array_keys($store) as $candidate) {
        if (str_ends_with((string) $candidate, ':' . $externalId)) {
          unset($store[$candidate]);
        }
      }
    }
    else {
      unset($store[$key]);
    }
    $this->state->set(self::STATE_KEY, $store);
  }

  /**
   * Drop a snapshot by its exact `type:external_id` store key.
   */
  public function forgetKey(string $storeKey): void {
    $store = $this->all();
    if (!isset($store[$storeKey])) {
      return;
    }
    unset($store[$storeKey]);
    $this->state->set(self::STATE_KEY, $store);
  }

  /**
   * Insert/refresh one row and prune back to the ceiling. Newest entry last.
   *
   * @param array<string, array<string, mixed>> $store
   * @param array<string, mixed> $row
   *
   * @return array<string, array<string, mixed>>
   */
  public static function put(array $store, string $key, array $row, int $max = self::MAX_SNAPSHOTS): array {
    unset($store[$key]);
    $store[$key] = $row;
    $max = max(1, $max);
    if (count($store) > $max) {
      $store = array_slice($store, -$max, NULL, TRUE);
    }
    return $store;
  }

  /**
   * Look up a snapshot; an empty `$type` matches any type for that external id.
   *
   * @param array<string, array<string, mixed>> $store
   *
   * @return array<string, mixed>|NULL
   */
  public static function lookup(array $store, string $externalId, string $type = ''): ?array {
    $externalId = trim($externalId);
    if ($externalId === '') {
      return NULL;
    }
    if ($type !== '') {
      $row = $store[$type . ':' . $externalId] ?? NULL;
      return is_array($row) ? $row : NULL;
    }
    $needle = ':' . $externalId;
    foreach (array_reverse($store, TRUE) as $key => $row) {
      if (str_ends_with((string) $key, $needle) && is_array($row)) {
        return $row;
      }
    }
    return NULL;
  }

  /**
   * Compact statistics for the Drush listing and the batch form.
   *
   * @param array<string, array<string, mixed>> $store
   *
   * @return array{snapshots: int, by_type: array<string, int>, oldest: string, newest: string}
   */
  public static function stats(array $store): array {
    $byType = [];
    $oldest = '';
    $newest = '';
    foreach ($store as $row) {
      if (!is_array($row)) {
        continue;
      }
      $type = (string) ($row['type'] ?? 'unknown');
      $byType[$type] = ($byType[$type] ?? 0) + 1;
      $at = date('c', (int) ($row['captured_at'] ?? 0));
      if ($oldest === '' || $at < $oldest) {
        $oldest = $at;
      }
      if ($at > $newest) {
        $newest = $at;
      }
    }
    ksort($byType);
    return [
      'snapshots' => count($store),
      'by_type' => $byType,
      'oldest' => $oldest,
      'newest' => $newest,
    ];
  }

}
