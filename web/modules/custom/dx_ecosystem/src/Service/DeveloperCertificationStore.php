<?php

declare(strict_types=1);

namespace Drupal\dx_ecosystem\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Developer certification state machine (OE2 / O5-A).
 *
 * States: none → pending (after DPA) → certified | revoked.
 */
final class DeveloperCertificationStore {

  public const STATUS_NONE = 'none';
  public const STATUS_PENDING = 'pending';
  public const STATUS_CERTIFIED = 'certified';
  public const STATUS_REVOKED = 'revoked';

  public function __construct(
    protected KeyValueFactoryInterface $keyValueFactory,
    protected AccountProxyInterface $currentUser,
    protected TimeInterface $time,
  ) {}

  /**
   * @return array{uid: int, status: string, dpa_version: string, updated: int, note: string, reviewer_uid: int}
   */
  public function get(?int $uid = NULL): array {
    $uid = $uid ?? (int) $this->currentUser->id();
    $row = $this->store()->get((string) $uid);
    if (!is_array($row)) {
      return $this->emptyRow($uid);
    }
    return [
      'uid' => (int) ($row['uid'] ?? $uid),
      'status' => (string) ($row['status'] ?? self::STATUS_NONE),
      'dpa_version' => (string) ($row['dpa_version'] ?? ''),
      'updated' => (int) ($row['updated'] ?? 0),
      'note' => (string) ($row['note'] ?? ''),
      'reviewer_uid' => (int) ($row['reviewer_uid'] ?? 0),
    ];
  }

  /**
   * Mark developer pending after DPA sign (does not auto-certify).
   */
  public function markPending(int $uid, string $dpaVersion, string $note = ''): array {
    $current = $this->get($uid);
    // Already certified with same DPA version stays certified.
    if ($current['status'] === self::STATUS_CERTIFIED && $current['dpa_version'] === $dpaVersion) {
      return $current;
    }
    return $this->write($uid, self::STATUS_PENDING, $dpaVersion, $note, 0);
  }

  public function certify(int $uid, string $dpaVersion, string $note = '', ?int $reviewerUid = NULL): array {
    $reviewerUid = $reviewerUid ?? (int) $this->currentUser->id();
    return $this->write($uid, self::STATUS_CERTIFIED, $dpaVersion, $note, $reviewerUid);
  }

  public function revoke(int $uid, string $note = '', ?int $reviewerUid = NULL): array {
    $current = $this->get($uid);
    $reviewerUid = $reviewerUid ?? (int) $this->currentUser->id();
    $row = $this->write($uid, self::STATUS_REVOKED, $current['dpa_version'], $note, $reviewerUid);
    if (\Drupal::hasService('dx_ecosystem.credentials')) {
      \Drupal::service('dx_ecosystem.credentials')->revoke($uid);
    }
    return $row;
  }

  /**
   * @return list<array{uid: int, status: string, dpa_version: string, updated: int, note: string, reviewer_uid: int}>
   */
  public function listByStatus(?string $status = NULL): array {
    $out = [];
    foreach ($this->store()->getAll() as $row) {
      if (!is_array($row)) {
        continue;
      }
      $normalized = [
        'uid' => (int) ($row['uid'] ?? 0),
        'status' => (string) ($row['status'] ?? self::STATUS_NONE),
        'dpa_version' => (string) ($row['dpa_version'] ?? ''),
        'updated' => (int) ($row['updated'] ?? 0),
        'note' => (string) ($row['note'] ?? ''),
        'reviewer_uid' => (int) ($row['reviewer_uid'] ?? 0),
      ];
      if ($status !== NULL && $normalized['status'] !== $status) {
        continue;
      }
      $out[] = $normalized;
    }
    usort($out, static fn(array $a, array $b): int => $b['updated'] <=> $a['updated']);
    return $out;
  }

  /**
   * @return array{uid: int, status: string, dpa_version: string, updated: int, note: string, reviewer_uid: int}
   */
  protected function write(int $uid, string $status, string $dpaVersion, string $note, int $reviewerUid): array {
    $row = [
      'uid' => $uid,
      'status' => $status,
      'dpa_version' => $dpaVersion,
      'updated' => $this->time->getRequestTime(),
      'note' => $note,
      'reviewer_uid' => $reviewerUid,
    ];
    $this->store()->set((string) $uid, $row);
    return $row;
  }

  /**
   * @return array{uid: int, status: string, dpa_version: string, updated: int, note: string, reviewer_uid: int}
   */
  protected function emptyRow(int $uid): array {
    return [
      'uid' => $uid,
      'status' => self::STATUS_NONE,
      'dpa_version' => '',
      'updated' => 0,
      'note' => '',
      'reviewer_uid' => 0,
    ];
  }

  protected function store(): \Drupal\Core\KeyValueStore\KeyValueStoreInterface {
    return $this->keyValueFactory->get('dx_ecosystem.certs');
  }

}
