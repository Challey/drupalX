<?php

declare(strict_types=1);

namespace Drupal\dx_ecosystem\Service;

use Drupal\dx_ecosystem\Service\Composer\RepositoryRequestAuth;

/**
 * L2 credential state machine + verification verdict (pure).
 *
 * The stored keyvalue row only carries facts (hash, revoked flag, timestamps).
 * Everything that decides *why* a token is accepted or refused lives here, so
 * the HTTP middleware, the Drush commands and the pure tests all agree on one
 * set of stable codes.
 *
 * States:
 *   none      – never issued
 *   active    – current token usable
 *   revoked   – explicitly revoked (or revoked together with certification)
 *   suspended – credential itself is fine, but the developer is not certified
 *               for the moment (pending / DPA stale); it can become active again
 *
 * A revoked credential is dead: re-issuing starts a *new* generation (revoked →
 * active) and the old secret never becomes usable again.
 */
final class CredentialLifecycle {

  public const STATE_NONE = 'none';
  public const STATE_ACTIVE = 'active';
  public const STATE_SUSPENDED = 'suspended';
  public const STATE_REVOKED = 'revoked';

  public const EVENT_ISSUE = 'issue';
  public const EVENT_ROTATE = 'rotate';
  public const EVENT_REVOKE = 'revoke';
  public const EVENT_AUTO_REVOKE = 'auto_revoke';
  public const EVENT_SUSPEND = 'suspend';
  public const EVENT_RESUME = 'resume';

  public const SUPERSEDED_YES = 'yes';
  public const SUPERSEDED_NO = 'no';

  /**
   * @return array<string, list<string>>
   */
  public static function transitions(): array {
    return [
      self::STATE_NONE => [self::STATE_ACTIVE],
      self::STATE_ACTIVE => [self::STATE_SUSPENDED, self::STATE_REVOKED],
      self::STATE_SUSPENDED => [self::STATE_ACTIVE, self::STATE_REVOKED],
      self::STATE_REVOKED => [self::STATE_ACTIVE],
    ];
  }

  /**
   * Which event moves a state where, expressed as state => allowed events.
   *
   * @return array<string, list<string>>
   */
  public static function allowedEvents(): array {
    return [
      self::STATE_NONE => [self::EVENT_ISSUE],
      self::STATE_ACTIVE => [self::EVENT_ROTATE, self::EVENT_REVOKE, self::EVENT_AUTO_REVOKE, self::EVENT_SUSPEND],
      self::STATE_SUSPENDED => [self::EVENT_RESUME, self::EVENT_REVOKE, self::EVENT_AUTO_REVOKE],
      self::STATE_REVOKED => [self::EVENT_ISSUE],
    ];
  }

  public static function canTransition(string $from, string $to): bool {
    if ($from === $to) {
      return TRUE;
    }
    $transitions = self::transitions();
    return in_array($to, $transitions[$from] ?? [], TRUE);
  }

  public static function isRevokedState(string $state): bool {
    return $state === self::STATE_REVOKED;
  }

  /**
   * The state a stored credential row is in, ignoring the developer's status.
   *
   * @param array<string, mixed>|null $row
   */
  public static function stateOf(?array $row): string {
    if (!is_array($row) || empty($row['hash'])) {
      return self::STATE_NONE;
    }
    return !empty($row['revoked']) ? self::STATE_REVOKED : self::STATE_ACTIVE;
  }

  /**
   * Applies one event to a state. Illegal events leave the state untouched.
   */
  public static function apply(string $from, string $event): string {
    $target = match ($event) {
      self::EVENT_ISSUE => self::STATE_ACTIVE,
      self::EVENT_ROTATE => self::STATE_ACTIVE,
      self::EVENT_RESUME => self::STATE_ACTIVE,
      self::EVENT_REVOKE, self::EVENT_AUTO_REVOKE => self::STATE_REVOKED,
      self::EVENT_SUSPEND => self::STATE_SUSPENDED,
      default => $from,
    };
    return self::canTransition($from, $target) ? $target : $from;
  }

  /**
   * Turns the stored row plus the current certification into a verdict.
   *
   * @param array{
   *   token_shape?: string,
   *   matched?: bool,
   *   revoked?: bool,
   *   superseded?: string,
   *   cert_status?: string,
   *   gate_reason?: string
   * } $facts
   *
   * @return array{ok: bool, code: string, state: string, message: string}
   */
  public static function classify(array $facts): array {
    $shape = (string) ($facts['token_shape'] ?? RepositoryRequestAuth::CODE_OK);
    if ($shape !== RepositoryRequestAuth::CODE_OK) {
      return self::verdict(FALSE, $shape === '' ? RepositoryRequestAuth::CODE_MISSING : $shape, self::stateOf(NULL));
    }
    $matched = (bool) ($facts['matched'] ?? FALSE);
    $revoked = (bool) ($facts['revoked'] ?? FALSE);
    $superseded = (string) ($facts['superseded'] ?? self::SUPERSEDED_NO);
    $certStatus = (string) ($facts['cert_status'] ?? DeveloperCertificationStore::STATUS_NONE);
    $gateReason = (string) ($facts['gate_reason'] ?? 'not_certified');

    if ($revoked) {
      return self::verdict(FALSE, RepositoryRequestAuth::CODE_REVOKED, self::STATE_REVOKED);
    }
    if ($certStatus === DeveloperCertificationStore::STATUS_REVOKED) {
      // I2 sync point: certification death kills the token immediately, even if
      // the revoke propagation to the credential row has not run yet.
      return self::verdict(FALSE, RepositoryRequestAuth::CODE_CERT_REVOKED, self::STATE_REVOKED);
    }
    if (!$matched) {
      return self::verdict(
        FALSE,
        $superseded === self::SUPERSEDED_YES ? RepositoryRequestAuth::CODE_ROTATED : RepositoryRequestAuth::CODE_UNKNOWN,
        self::STATE_NONE,
      );
    }
    if ($gateReason === 'dpa_version_mismatch' || $gateReason === 'dpa_ack_missing') {
      return self::verdict(FALSE, RepositoryRequestAuth::CODE_DPA_STALE, self::STATE_SUSPENDED);
    }
    if ($certStatus !== DeveloperCertificationStore::STATUS_CERTIFIED) {
      return self::verdict(FALSE, RepositoryRequestAuth::CODE_CERT_STALE, self::STATE_SUSPENDED);
    }
    if ($gateReason === 'missing_permission') {
      return self::verdict(FALSE, RepositoryRequestAuth::CODE_CERT_STALE, self::STATE_SUSPENDED);
    }
    return self::verdict(TRUE, RepositoryRequestAuth::CODE_OK, self::STATE_ACTIVE);
  }

  /**
   * Summary row for the report page / Drush, derived from a credential row.
   *
   * @param array<string, mixed>|null $row
   *
   * @return array{uid: int, prefix: string, state: string, created: int, revoked_at: int, rotations: int, last_used: int, uses: int}
   */
  public static function summary(?array $row, int $uid = 0): array {
    $row = is_array($row) ? $row : [];
    return [
      'uid' => (int) ($row['uid'] ?? $uid),
      'prefix' => (string) ($row['prefix'] ?? ''),
      'state' => self::stateOf($row),
      'created' => (int) ($row['created'] ?? 0),
      'revoked_at' => (int) ($row['revoked_at'] ?? 0),
      'rotations' => (int) ($row['rotations'] ?? 0),
      'last_used' => (int) ($row['last_used'] ?? 0),
      'uses' => (int) ($row['uses'] ?? 0),
    ];
  }

  /**
   * @return array{ok: bool, code: string, state: string, message: string}
   */
  private static function verdict(bool $ok, string $code, string $state): array {
    return [
      'ok' => $ok,
      'code' => $code,
      'state' => $state,
      'message' => RepositoryRequestAuth::messageFor($code),
    ];
  }

}
