<?php

declare(strict_types=1);

namespace Drupal\dx_channel\Service;

/**
 * Delivery-health aggregation for outbound webhooks (roadmap G4).
 *
 * WebhookService keeps the raw counters in state; everything that *interprets*
 * them — success rate, health grading, per-endpoint table rows, exponential
 * backoff for a queued retry — is done here on plain arrays so the numbers the
 * admin screen shows can be asserted without a database.
 *
 * @see \Drupal\dx_channel\Form\WebhookSettingsForm
 * @see \Drupal\dx_channel\Controller\WebhookHealthController
 */
final class WebhookHealth {

  public const STATUS_UNCONFIGURED = 'unconfigured';
  public const STATUS_UNKNOWN = 'unknown';
  public const STATUS_HEALTHY = 'healthy';
  public const STATUS_DEGRADED = 'degraded';
  public const STATUS_FAILING = 'failing';

  /** Success ratio at or above which delivery counts as healthy. */
  public const HEALTHY_RATE = 0.95;

  /** Success ratio at or below which delivery counts as failing. */
  public const FAILING_RATE = 0.5;

  /** Backoff schedule (seconds) applied to dead-letter retries, capped at 8 tries. */
  public const MAX_ATTEMPTS = 8;

  public const BACKOFF_SECONDS = [1, 5, 30, 120, 600, 1800, 3600, 7200];

  /**
   * Blank counter document written by WebhookService::stats().
   *
   * @return array<string, mixed>
   */
  public static function emptyStats(): array {
    return [
      'attempts' => 0,
      'sent' => 0,
      'failed' => 0,
      'retried' => 0,
      'retried_sent' => 0,
      'retried_failed' => 0,
      'dropped' => 0,
      'rate_limited' => 0,
      'by_endpoint' => [],
      'days' => [],
      'updated_at' => '',
    ];
  }

  /**
   * Fold one dispatch outcome into a counter document (immutable).
   *
   * @param array<string, mixed> $stats
   * @param list<array{endpoint: string, ok: bool}> $deliveries
   *
   * @return array<string, mixed>
   */
  public static function recordDispatch(array $stats, string $event, array $deliveries, bool $rateLimited = FALSE, string $at = ''): array {
    $at = $at !== '' ? $at : gmdate('c');
    $day = self::day($at);
    $out = self::normalize($stats);
    $out['last_event'] = $event;
    $out['last_at'] = $at;
    if ($rateLimited) {
      $out['rate_limited']++;
      return $out;
    }
    if ($deliveries === []) {
      // No matching endpoint is not a delivery failure; only note the event.
      return $out;
    }
    $out['attempts'] += count($deliveries);
    $out['days'][$day]['attempts'] = (int) ($out['days'][$day]['attempts'] ?? 0) + count($deliveries);
    foreach ($deliveries as $row) {
      $endpoint = (string) ($row['endpoint'] ?? '');
      $ok = !empty($row['ok']);
      if ($ok) {
        $out['sent']++;
        $out['days'][$day]['sent'] = (int) ($out['days'][$day]['sent'] ?? 0) + 1;
      }
      else {
        $out['failed']++;
        $out['days'][$day]['failed'] = (int) ($out['days'][$day]['failed'] ?? 0) + 1;
      }
      if ($endpoint !== '') {
        $slot = is_array($out['by_endpoint'][$endpoint] ?? NULL) ? $out['by_endpoint'][$endpoint] : [];
        $slot += ['sent' => 0, 'failed' => 0, 'last_at' => '', 'last_ok_at' => '', 'last_error_at' => ''];
        if ($ok) {
          $slot['sent']++;
          $slot['last_ok_at'] = $at;
        }
        else {
          $slot['failed']++;
          $slot['last_error_at'] = $at;
        }
        $slot['last_at'] = $at;
        $out['by_endpoint'][$endpoint] = $slot;
      }
    }
    $out['days'] = self::capDays($out['days']);
    $out['updated_at'] = $at;
    return $out;
  }

  /**
   * Fold one dead-letter retry run into a counter document (immutable).
   *
   * @param array<string, mixed> $stats
   *
   * @return array<string, mixed>
   */
  public static function recordRetry(array $stats, int $sent, int $failed, int $dropped = 0, string $at = ''): array {
    $at = $at !== '' ? $at : gmdate('c');
    $day = self::day($at);
    $out = self::normalize($stats);
    $sent = max(0, $sent);
    $failed = max(0, $failed);
    $out['retried'] += $sent + $failed;
    $out['retried_sent'] += $sent;
    $out['retried_failed'] += $failed;
    $out['dropped'] += max(0, $dropped);
    $out['attempts'] += $sent + $failed;
    $out['sent'] += $sent;
    $out['failed'] += $failed;
    $out['days'][$day]['attempts'] = (int) ($out['days'][$day]['attempts'] ?? 0) + $sent + $failed;
    $out['days'][$day]['sent'] = (int) ($out['days'][$day]['sent'] ?? 0) + $sent;
    $out['days'][$day]['failed'] = (int) ($out['days'][$day]['failed'] ?? 0) + $failed;
    $out['days'] = self::capDays($out['days']);
    $out['last_retry_at'] = $at;
    $out['updated_at'] = $at;
    return $out;
  }

  /**
   * Build the health report from counters plus the current queue depth.
   *
   * @param array<string, mixed> $stats
   * @param array<string, mixed> $endpoints id => endpoint row
   *
   * @return array<string, mixed>
   */
  public static function report(array $stats, array $endpoints = [], int $deadLetters = 0, int $windowDays = 7): array {
    $stats = self::normalize($stats);
    $window = self::windowTotals($stats['days'], $windowDays);
    $attempts = (int) $window['attempts'];
    $sent = (int) $window['sent'];
    $failed = (int) $window['failed'];
    $rate = $attempts === 0 ? NULL : round($sent / $attempts, 4);

    return [
      'window_days' => max(1, $windowDays),
      'status' => self::grade($attempts, $sent, self::configured($endpoints)),
      'configured' => self::configured($endpoints),
      'enabled' => self::enabled($endpoints),
      'endpoints_total' => count($endpoints),
      'endpoints_enabled' => count(array_filter(
        $endpoints,
        static fn($ep): bool => is_array($ep) && !empty($ep['enabled']),
      )),
      'attempts' => $attempts,
      'sent' => $sent,
      'failed' => $failed,
      'success_rate' => $rate,
      'success_rate_percent' => $rate === NULL ? NULL : round($rate * 100, 2),
      'lifetime' => [
        'attempts' => (int) $stats['attempts'],
        'sent' => (int) $stats['sent'],
        'failed' => (int) $stats['failed'],
      ],
      'retried' => (int) $stats['retried'],
      'retried_sent' => (int) $stats['retried_sent'],
      'retried_failed' => (int) $stats['retried_failed'],
      'dropped' => (int) $stats['dropped'],
      'rate_limited' => (int) $stats['rate_limited'],
      'dead_letters' => max(0, $deadLetters),
      'last_at' => (string) ($stats['last_at'] ?? ''),
      'last_retry_at' => (string) ($stats['last_retry_at'] ?? ''),
      'by_endpoint' => self::endpointRows($stats, $endpoints),
      'daily' => $window['rows'],
    ];
  }

  /**
   * Per-endpoint rows for the admin table, most failing first.
   *
   * @param array<string, mixed> $stats
   * @param array<string, mixed> $endpoints
   *
   * @return list<array<string, mixed>>
   */
  public static function endpointRows(array $stats, array $endpoints = []): array {
    $stats = self::normalize($stats);
    $ids = array_keys($endpoints + $stats['by_endpoint']);
    $rows = [];
    foreach ($ids as $id) {
      $slot = is_array($stats['by_endpoint'][$id] ?? NULL) ? $stats['by_endpoint'][$id] : [];
      $ep = is_array($endpoints[$id] ?? NULL) ? $endpoints[$id] : [];
      $sent = (int) ($slot['sent'] ?? 0);
      $failed = (int) ($slot['failed'] ?? 0);
      $attempts = $sent + $failed;
      $rows[] = [
        'id' => (string) $id,
        'url' => (string) ($ep['url'] ?? ''),
        'enabled' => !empty($ep['enabled']),
        'events' => array_values(array_map('strval', is_array($ep['events'] ?? NULL) ? $ep['events'] : [])),
        'attempts' => $attempts,
        'sent' => $sent,
        'failed' => $failed,
        'success_rate' => $attempts === 0 ? NULL : round($sent / $attempts, 4),
        'last_at' => (string) ($slot['last_at'] ?? ''),
        'last_ok_at' => (string) ($slot['last_ok_at'] ?? ''),
        'last_error_at' => (string) ($slot['last_error_at'] ?? ''),
      ];
    }
    usort($rows, static function (array $a, array $b): int {
      return [$b['failed'], $a['id']] <=> [$a['failed'], $b['id']];
    });
    return $rows;
  }

  /**
   * Grade delivery health for a window.
   */
  public static function grade(int $attempts, int $sent, bool $configured = TRUE): string {
    if (!$configured) {
      return self::STATUS_UNCONFIGURED;
    }
    if ($attempts === 0) {
      return self::STATUS_UNKNOWN;
    }
    $rate = $sent / $attempts;
    if ($rate >= self::HEALTHY_RATE) {
      return self::STATUS_HEALTHY;
    }
    if ($rate <= self::FAILING_RATE) {
      return self::STATUS_FAILING;
    }
    return self::STATUS_DEGRADED;
  }

  /**
   * Does any endpoint (site-level or registered) look reachable at all?
   *
   * @param array<string, mixed> $endpoints
   */
  public static function configured(array $endpoints): bool {
    foreach ($endpoints as $ep) {
      if (is_array($ep) && trim((string) ($ep['url'] ?? '')) !== '') {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * @param array<string, mixed> $endpoints
   */
  public static function enabled(array $endpoints): bool {
    foreach ($endpoints as $ep) {
      if (is_array($ep) && !empty($ep['enabled']) && trim((string) ($ep['url'] ?? '')) !== '') {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Sum the per-day counters inside the last $windowDays days.
   *
   * @param array<string, array<string, mixed>> $days
   *
   * @return array{attempts: int, sent: int, failed: int, rows: list<array{day: string, attempts: int, sent: int, failed: int}>}
   */
  public static function windowTotals(array $days, int $windowDays, ?string $today = NULL): array {
    $windowDays = max(1, $windowDays);
    $today = $today !== '' && $today !== NULL ? $today : gmdate('Y-m-d');
    // Plain UTC day arithmetic: strtotime() is local-timezone aware, which would
    // shift the window by a day on a +08:00 host.
    $cutoff = gmdate('Y-m-d', (int) strtotime($today . ' 00:00:00 UTC') - ($windowDays - 1) * 86400);
    $attempts = $sent = $failed = 0;
    $rows = [];
    ksort($days);
    foreach ($days as $day => $row) {
      if (!is_array($row) || (string) $day < $cutoff || (string) $day > $today) {
        continue;
      }
      $a = (int) ($row['attempts'] ?? 0);
      $s = (int) ($row['sent'] ?? 0);
      $f = (int) ($row['failed'] ?? 0);
      $attempts += $a;
      $sent += $s;
      $failed += $f;
      $rows[] = ['day' => (string) $day, 'attempts' => $a, 'sent' => $s, 'failed' => $f];
    }
    return ['attempts' => $attempts, 'sent' => $sent, 'failed' => $failed, 'rows' => $rows];
  }

  /**
   * Seconds to wait before retry number $attempt (1 = the first retry).
   *
   * Exponential, capped by the 8-try budget of docs/data-exchange.md §10.3; a
   * payload that exhausted the budget stays in the dead-letter queue.
   */
  public static function backoffSeconds(int $attempt): int {
    if ($attempt < 1) {
      return 0;
    }
    if ($attempt > self::MAX_ATTEMPTS) {
      return -1;
    }
    return self::BACKOFF_SECONDS[min(count(self::BACKOFF_SECONDS), $attempt) - 1];
  }

  /**
   * May a payload that already had $retries attempts be retried again?
   */
  public static function mayRetry(int $retries, int $max = self::MAX_ATTEMPTS): bool {
    return $retries < max(1, $max);
  }

  /**
   * Fill in missing keys so an old state document cannot break the report.
   *
   * @param array<string, mixed> $stats
   *
   * @return array<string, mixed>
   */
  public static function normalize(array $stats): array {
    // Existing counters must win; the defaults only fill in missing keys.
    $out = $stats + self::emptyStats();
    foreach (['attempts', 'sent', 'failed', 'retried', 'retried_sent', 'retried_failed', 'rate_limited', 'dropped'] as $key) {
      $out[$key] = (int) $out[$key];
    }
    foreach (['by_endpoint', 'days'] as $key) {
      $out[$key] = is_array($out[$key]) ? $out[$key] : [];
    }
    return $out;
  }

  /**
   * Keep the daily series bounded (state is a serialized blob).
   *
   * @param array<string, array<string, mixed>> $days
   *
   * @return array<string, array<string, mixed>>
   */
  private static function capDays(array $days, int $keep = 120): array {
    if (count($days) <= $keep) {
      return $days;
    }
    ksort($days);
    return array_slice($days, -$keep, NULL, TRUE);
  }

  private static function day(string $iso): string {
    return substr($iso, 0, 10);
  }

}
