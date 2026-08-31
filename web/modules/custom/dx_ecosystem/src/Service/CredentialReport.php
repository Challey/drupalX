<?php

declare(strict_types=1);

namespace Drupal\dx_ecosystem\Service;

use Drupal\Component\Datetime\TimeInterface;

/**
 * Issue / rotation / usage report for L2 credentials (I3).
 *
 * Two readers, one aggregation: the admin page and
 * `drush dx:ecosystem-credential-report` both render ::build(). The maths is the
 * static ::aggregate() over plain arrays, which is what lets
 * tests/pure-assertions.php pin the numbers down without a database.
 */
final class CredentialReport {

  public const WINDOW_ALL = 0;

  public const DEFAULT_WINDOW = 2592000;

  public function __construct(
    protected PartnerCredentialStore $credentials,
    protected CredentialAuditLog $audit,
    protected TimeInterface $time,
  ) {}

  /**
   * The report as the page and the CLI consume it.
   *
   * @return array{
   *   generated: int,
   *   window: int,
   *   audit_ready: bool,
   *   rows: list<array<string, mixed>>,
   *   totals: array<string, int>,
   *   events: list<array<string, mixed>>
   * }
   */
  public function build(int $windowSeconds = self::DEFAULT_WINDOW, ?int $uid = NULL): array {
    $now = $this->time->getRequestTime();
    $since = $windowSeconds > 0 ? max(0, $now - $windowSeconds) : 0;
    $credentials = $uid === NULL
      ? $this->credentials->listAll()
      : array_values(array_filter($this->credentials->listAll(), static fn(array $row): bool => (int) $row['uid'] === $uid));
    $events = $this->audit->rows($uid, $since, 2000);
    $report = self::aggregate($credentials, $events, $now);
    foreach ($report['rows'] as &$row) {
      // Names need the user storage, so they are added outside the pure join.
      $row['issued_by_name'] = $this->actorName((int) $row['issued_by']);
    }
    unset($row);
    $report['audit_ready'] = $this->audit->isReady();
    $report['window'] = $windowSeconds;
    $report['events'] = array_slice($events, 0, 50);
    return $report;
  }

  /**
   * Joins credential rows with their events. Pure: arrays in, arrays out.
   *
   * @param list<array<string, mixed>> $credentials
   * @param list<array<string, mixed>> $events
   *
   * @return array{generated: int, rows: list<array<string, mixed>>, totals: array<string, int>}
   */
  public static function aggregate(array $credentials, array $events, int $now = 0): array {
    $byUid = [];
    foreach ($credentials as $row) {
      $uid = (int) ($row['uid'] ?? 0);
      if ($uid > 0) {
        $byUid[$uid] = $row;
      }
    }
    foreach ($events as $event) {
      $uid = (int) ($event['uid'] ?? 0);
      if ($uid <= 0) {
        continue;
      }
      if (!isset($byUid[$uid])) {
        // Events outlive a deleted credential row; keep them visible.
        $byUid[$uid] = CredentialLifecycle::summary(NULL, $uid);
      }
      $name = (string) ($event['event'] ?? '');
      $bucket = &$byUid[$uid];
      $bucket['event_counts'][$name] = (int) ($bucket['event_counts'][$name] ?? 0) + 1;
      if (in_array($name, CredentialAuditLog::usageEvents(), TRUE)) {
        $bucket['reported_uses'] = (int) ($bucket['reported_uses'] ?? 0) + 1;
        if ((int) ($event['created'] ?? 0) > (int) ($bucket['last_event_used'] ?? 0)) {
          $bucket['last_event_used'] = (int) $event['created'];
        }
      }
      $ip = trim((string) ($event['ip'] ?? ''));
      if ($ip !== '') {
        $bucket['ips'][$ip] = (int) ($bucket['ips'][$ip] ?? 0) + 1;
      }
      if ((int) ($event['created'] ?? 0) > (int) ($bucket['last_event'] ?? 0)) {
        $bucket['last_event'] = (int) $event['created'];
        $bucket['last_event_name'] = $name;
        $bucket['last_event_code'] = (string) ($event['code'] ?? '');
      }
      if ($name === CredentialAuditLog::EVENT_VERIFY_FAIL || $name === CredentialAuditLog::EVENT_DENIED) {
        $bucket['denials'] = (int) ($bucket['denials'] ?? 0) + 1;
      }
      if (($name === CredentialAuditLog::EVENT_ISSUE || $name === CredentialAuditLog::EVENT_ROTATE)
        && (int) ($event['actor_uid'] ?? 0) > 0) {
        $bucket['issuer'] = (int) $event['actor_uid'];
        $bucket['issuer_ip'] = $ip;
      }
      unset($bucket);
    }

    $rows = [];
    $totals = [
      'credentials' => 0,
      'active' => 0,
      'suspended' => 0,
      'revoked' => 0,
      'never_issued' => 0,
      'uses' => 0,
      'rotations' => 0,
      'denials' => 0,
      'issued_by_others' => 0,
    ];
    foreach ($byUid as $uid => $bucket) {
      $ips = $bucket['ips'] ?? [];
      arsort($ips);
      $state = (string) ($bucket['state'] ?? CredentialLifecycle::STATE_NONE);
      $uses = max((int) ($bucket['uses'] ?? 0), (int) ($bucket['reported_uses'] ?? 0));
      $lastUsed = max((int) ($bucket['last_used'] ?? 0), (int) ($bucket['last_event_used'] ?? 0));
      $issuer = (int) ($bucket['issuer'] ?? $bucket['issued_by'] ?? 0);
      $row = [
        'uid' => $uid,
        'state' => $state,
        'prefix' => (string) ($bucket['prefix'] ?? ''),
        'issued_by' => $issuer,
        'issued_by_name' => self::actorLabel($issuer),
        'issued_ip' => (string) ($bucket['issued_ip'] ?? $bucket['issuer_ip'] ?? ''),
        'issued_via' => (string) ($bucket['issued_via'] ?? ''),
        'created' => (int) ($bucket['created'] ?? 0),
        'rotations' => (int) ($bucket['rotations'] ?? 0),
        'uses' => $uses,
        'last_used' => $lastUsed,
        'idle_days' => $lastUsed > 0 && $now > 0 ? max(0, (int) floor(($now - $lastUsed) / 86400)) : NULL,
        'revoked_at' => (int) ($bucket['revoked_at'] ?? 0),
        'revoke_reason' => (string) ($bucket['revoke_reason'] ?? ''),
        'cert_status' => (string) ($bucket['cert_status'] ?? ''),
        'denials' => (int) ($bucket['denials'] ?? 0),
        'distinct_ips' => count($ips),
        'top_ips' => array_slice(array_keys($ips), 0, 3),
        'event_counts' => $bucket['event_counts'] ?? [],
        'last_event' => (int) ($bucket['last_event'] ?? 0),
        'last_event_name' => (string) ($bucket['last_event_name'] ?? ''),
        'last_event_code' => (string) ($bucket['last_event_code'] ?? ''),
      ];
      $rows[] = $row;
      $totals['credentials']++;
      $totals[$state] = ($totals[$state] ?? 0) + 1;
      if ($state === CredentialLifecycle::STATE_NONE) {
        $totals['never_issued']++;
      }
      $totals['uses'] += $row['uses'];
      $totals['rotations'] += $row['rotations'];
      $totals['denials'] += $row['denials'];
      if ($issuer > 0 && $issuer !== $uid) {
        $totals['issued_by_others']++;
      }
    }
    usort($rows, static fn(array $a, array $b): int => [$b['last_used'], $b['uses']] <=> [$a['last_used'], $a['uses']]);
    return [
      'generated' => $now,
      'rows' => $rows,
      'totals' => $totals,
    ];
  }

  /**
   * Column order shared by the render array and the CLI table.
   *
   * @return list<string>
   */
  public static function columns(): array {
    return [
      'uid',
      'state',
      'prefix',
      'issued_by',
      'issued_ip',
      'created',
      'rotations',
      'uses',
      'last_used',
      'idle_days',
      'denials',
      'distinct_ips',
      'revoked_at',
      'revoke_reason',
    ];
  }

  /**
   * Source text of one column header, so page and CLI speak the same words.
   */
  public static function headerLabel(string $column): string {
    return match ($column) {
      'uid' => 'UID',
      'state' => '状态',
      'prefix' => '前缀',
      'issued_by' => '签发者',
      'issued_ip' => '来源 IP',
      'created' => '签发时间',
      'rotations' => '轮换',
      'uses' => '调用次数',
      'last_used' => '最后使用',
      'idle_days' => '闲置天数',
      'denials' => '拒绝',
      'distinct_ips' => 'IP 数',
      'revoked_at' => '吊销时间',
      'revoke_reason' => '吊销原因',
      default => $column,
    };
  }

  /**
   * Static actor label used by the pure aggregate: uid-N, or an em dash.
   */
  public static function actorLabel(int $uid): string {
    return $uid > 0 ? 'uid-' . $uid : '—';
  }

  /**
   * Account name of an actor, resolved only where a container is available.
   *
   * The audit table stores uids; a deleted account must still be readable in
   * the report, so everything falls back to ::actorLabel().
   */
  public function actorName(int $uid): string {
    if ($uid <= 0) {
      return self::actorLabel($uid);
    }
    try {
      $account = \Drupal::entityTypeManager()->getStorage('user')->load($uid);
    }
    catch (\Throwable) {
      $account = NULL;
    }
    $name = $account === NULL ? '' : (string) $account->getAccountName();
    return $name !== '' ? $name : self::actorLabel($uid);
  }

}
