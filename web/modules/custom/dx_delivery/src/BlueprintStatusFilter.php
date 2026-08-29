<?php

declare(strict_types=1);

namespace Drupal\dx_delivery;

/**
 * Four-state partition of the blueprint list (Phase F / F1).
 *
 * The admin list, the drush smokes and the operator board all need the same
 * draft / confirmed / executed / failed-retry split. The blueprint status field
 * has five machine values (draft, confirmed, running, completed, failed), so the
 * mapping lives here as a pure function set: it can be asserted without a
 * database, and the list builder only asks it for query conditions.
 */
final class BlueprintStatusFilter {

  /**
   * Query parameter carrying the segment on /admin/dx/delivery.
   */
  public const QUERY_KEY = 'status';

  /**
   * Segment used when the query parameter is absent, empty or unknown.
   */
  public const ALL = 'all';

  /**
   * Segment => the blueprint statuses it collects.
   *
   * @var array<string, list<string>>
   */
  private const SEGMENTS = [
    'draft' => ['draft'],
    'confirmed' => ['confirmed'],
    'executed' => ['running', 'completed'],
    'failed' => ['failed'],
  ];

  /**
   * The four operator segments, in display order.
   *
   * @return list<string>
   */
  public static function segments(): array {
    return array_keys(self::SEGMENTS);
  }

  /**
   * Normalise raw query input onto a known segment.
   *
   * Unknown, malformed and historical single-status values never error out:
   * running/completed collapse into executed, anything else falls back to the
   * unfiltered list so an old bookmark keeps rendering.
   */
  public static function normalize(mixed $raw): string {
    if (!is_string($raw)) {
      return self::ALL;
    }
    $key = strtolower(trim($raw));
    if ($key === '' || $key === self::ALL) {
      return self::ALL;
    }
    return match ($key) {
      'running', 'completed' => 'executed',
      default => isset(self::SEGMENTS[$key]) ? $key : self::ALL,
    };
  }

  /**
   * Statuses a segment collects; empty means "no condition".
   *
   * @return list<string>
   */
  public static function statuses(string $segment): array {
    $normalized = self::normalize($segment);
    return $normalized === self::ALL ? [] : self::SEGMENTS[$normalized];
  }

  /**
   * Whether a blueprint status belongs to a segment.
   */
  public static function matches(string $segment, string $status): bool {
    $statuses = self::statuses($segment);
    return $statuses === [] || in_array($status, $statuses, TRUE);
  }

  /**
   * The segment a status would be found under (first hit wins).
   */
  public static function segmentOf(string $status): string {
    foreach (self::SEGMENTS as $segment => $statuses) {
      if (in_array($status, $statuses, TRUE)) {
        return $segment;
      }
    }
    return self::ALL;
  }

  /**
   * Roll status => count pairs up into segment => count pairs.
   *
   * @param array<string, int> $counts
   *   Raw status counts.
   * @param bool $withAll
   *   Prefix the roll-up with the unfiltered total.
   *
   * @return array<string, int>
   */
  public static function rollup(array $counts, bool $withAll = TRUE): array {
    $out = [];
    if ($withAll) {
      $out[self::ALL] = array_sum($counts);
    }
    foreach (self::SEGMENTS as $segment => $statuses) {
      $out[$segment] = 0;
      foreach ($statuses as $status) {
        $out[$segment] += (int) ($counts[$status] ?? 0);
      }
    }
    return $out;
  }

}
