<?php

declare(strict_types=1);

namespace Drupal\dx_ecosystem\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Append-only event stream behind the L2 credential report (I3).
 *
 * Deliberately *not* keyvalue: the report needs "who issued this, from which IP,
 * how often is it used, when was the last hit", which is a query, and keyvalue
 * gives no secondary index. The table is owned by this module alone
 * (`dx_l2_credential_event`, see dx_ecosystem.install) — no existing entity or
 * base field is touched.
 *
 * Every write is best-effort: if the schema has not been deployed yet (the site
 * is between code sync and `drush updatedb`) the credential flow must keep
 * working exactly as before, so failures degrade to a watchdog notice.
 */
final class CredentialAuditLog {

  public const TABLE = 'dx_l2_credential_event';

  public const EVENT_ISSUE = 'issue';
  public const EVENT_ROTATE = 'rotate';
  public const EVENT_REVOKE = 'revoke';
  public const EVENT_AUTO_REVOKE = 'auto_revoke';
  public const EVENT_VERIFY_OK = 'verify_ok';
  public const EVENT_VERIFY_FAIL = 'verify_fail';
  public const EVENT_METADATA = 'metadata';
  public const EVENT_DOWNLOAD = 'download';
  public const EVENT_DENIED = 'denied';

  /**
   * @return list<string>
   */
  public static function events(): array {
    return [
      self::EVENT_ISSUE,
      self::EVENT_ROTATE,
      self::EVENT_REVOKE,
      self::EVENT_AUTO_REVOKE,
      self::EVENT_VERIFY_OK,
      self::EVENT_VERIFY_FAIL,
      self::EVENT_METADATA,
      self::EVENT_DOWNLOAD,
      self::EVENT_DENIED,
    ];
  }

  /**
   * Events that count as "the credential was actually used".
   *
   * @return list<string>
   */
  public static function usageEvents(): array {
    return [self::EVENT_VERIFY_OK, self::EVENT_METADATA, self::EVENT_DOWNLOAD];
  }

  public function __construct(
    protected Connection $connection,
    protected LoggerChannelInterface $logger,
  ) {}

  /**
   * True once dx_ecosystem_update_9003() (or a fresh install) created the table.
   */
  public function isReady(): bool {
    try {
      return $this->connection->schema()->tableExists(self::TABLE);
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  /**
   * Records one event. Never throws; returns FALSE when it could not persist.
   *
   * @param array<string, mixed> $context
   *   Recognised keys: code, prefix, actor_uid, ip, path, user_agent, time.
   */
  public function log(int $uid, string $event, array $context = []): bool {
    if (!in_array($event, self::events(), TRUE)) {
      $this->logger->warning('Unknown L2 credential event @event', ['@event' => $event]);
      return FALSE;
    }
    if (!$this->isReady()) {
      return FALSE;
    }
    $row = [
      'uid' => max(0, $uid),
      'event' => $event,
      'code' => self::truncate((string) ($context['code'] ?? ''), 64),
      'token_prefix' => self::truncate((string) ($context['prefix'] ?? ''), 16),
      'actor_uid' => max(0, (int) ($context['actor_uid'] ?? 0)),
      'ip' => self::truncate((string) ($context['ip'] ?? self::fallbackIp()), 64),
      'path' => self::truncate((string) ($context['path'] ?? ''), 255),
      'user_agent' => self::truncate((string) ($context['user_agent'] ?? ''), 255),
      'created' => (int) ($context['time'] ?? time()),
    ];
    try {
      $this->connection->insert(self::TABLE)->fields($row)->execute();
      return TRUE;
    }
    catch (\Throwable) {
      // A broken audit trail must never break partner access.
      $this->logger->notice('L2 credential audit write skipped (uid @uid, @event)', [
        '@uid' => $row['uid'],
        '@event' => $row['event'],
      ]);
      return FALSE;
    }
  }

  /**
   * Raw event rows, newest first. Empty when the table is not deployed yet.
   *
   * @return list<array<string, mixed>>
   */
  public function rows(?int $uid = NULL, int $since = 0, int $limit = 500): array {
    if (!$this->isReady()) {
      return [];
    }
    $query = $this->connection->select(self::TABLE, 'e')
      ->fields('e', [
        'id', 'uid', 'event', 'code', 'token_prefix', 'actor_uid', 'ip', 'path', 'created',
      ])
      ->range(0, max(1, min($limit, 5000)));
    if ($uid !== NULL) {
      $query->condition('uid', $uid);
    }
    if ($since > 0) {
      $query->condition('created', $since, '>=');
    }
    $query->orderBy('created', 'DESC')->orderBy('id', 'DESC');
    $out = [];
    foreach ($query->execute()->fetchAllAssoc('id') as $row) {
      $out[] = [
        'id' => (int) $row->id,
        'uid' => (int) $row->uid,
        'event' => (string) $row->event,
        'code' => (string) $row->code,
        'token_prefix' => (string) $row->token_prefix,
        'actor_uid' => (int) $row->actor_uid,
        'ip' => (string) $row->ip,
        'path' => (string) $row->path,
        'created' => (int) $row->created,
      ];
    }
    return $out;
  }

  /**
   * Drops every event of one uid (used by the rotate-retention drush command).
   */
  public function forget(int $uid): int {
    if (!$this->isReady() || $uid <= 0) {
      return 0;
    }
    try {
      return (int) $this->connection->delete(self::TABLE)
        ->condition('uid', $uid)
        ->execute();
    }
    catch (\Throwable) {
      return 0;
    }
  }

  /**
   * Trims old events; returns how many rows went away. 0 keeps everything.
   */
  public function prune(int $olderThanSeconds): int {
    if (!$this->isReady() || $olderThanSeconds <= 0) {
      return 0;
    }
    try {
      return (int) $this->connection->delete(self::TABLE)
        ->condition('created', time() - $olderThanSeconds, '<')
        ->execute();
    }
    catch (\Throwable) {
      return 0;
    }
  }

  /**
   * CLI has no request; record the host the command ran on so rows stay auditable.
   */
  public static function fallbackIp(): string {
    return 'cli';
  }

  private static function truncate(string $value, int $limit): string {
    $value = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value) ?? $value;
    $value = trim($value);
    if (strlen($value) <= $limit) {
      return $value;
    }
    return substr($value, 0, max(0, $limit - 4)) . ' ...';
  }

}
