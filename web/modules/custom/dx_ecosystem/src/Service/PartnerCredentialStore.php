<?php

declare(strict_types=1);

namespace Drupal\dx_ecosystem\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\dx_ecosystem\Service\Composer\ComposerHostPlan;
use Drupal\dx_ecosystem\Service\Composer\RepositoryRequestAuth;
use Drupal\user\Entity\User;

/**
 * Issues hashed L2 Composer/Git credentials (plaintext shown once).
 *
 * Storage stays the `dx_ecosystem.credentials` keyvalue collection so OE2 data
 * already issued keeps working. Phase I adds the lifecycle around it: rotation
 * history, revocation reasons, usage counters and audit events. Existing public
 * method signatures are unchanged — new information arrives through new methods
 * or optional trailing parameters.
 */
final class PartnerCredentialStore {

  public const COLLECTION = 'dx_ecosystem.credentials';

  /** How many superseded secrets we keep around to say "rotated", not "unknown". */
  public const HISTORY = 5;

  public function __construct(
    protected KeyValueFactoryInterface $keyValueFactory,
    protected DeveloperCertificationStore $certs,
    protected DeveloperGate $gate,
    protected ConfigFactoryInterface $configFactory,
    protected TimeInterface $time,
    protected ?CredentialAuditLog $audit = NULL,
  ) {}

  /**
   * Mint a fresh secret: `dxl2_` + 48 hex characters (24 random bytes).
   */
  public static function mintToken(): string {
    return RepositoryRequestAuth::TOKEN_PREFIX . bin2hex(random_bytes(24));
  }

  /**
   * The only representation ever stored: SHA-256 digest of the plaintext.
   */
  public static function digest(string $plain): string {
    return hash('sha256', trim($plain));
  }

  /**
   * First 12 characters (`dxl2_` + 7 hex) for logs and reports. Not a secret.
   */
  public static function prefixOf(string $plain): string {
    return substr(trim($plain), 0, 12);
  }

  /**
   * @return array{token: string, prefix: string, uid: int, composer: array<string, mixed>, git_clone: string}
   */
  public function issue(int $uid): array {
    $account = User::load($uid);
    if ($account === NULL) {
      throw new \RuntimeException("User $uid not found");
    }
    $actor = $this->requestActor();
    $explain = $this->gate->explain($account);
    $cert = $this->certs->get($uid);
    if ($cert['status'] !== DeveloperCertificationStore::STATUS_CERTIFIED) {
      $this->audit()?->log($uid, CredentialAuditLog::EVENT_VERIFY_FAIL, [
        'code' => RepositoryRequestAuth::CODE_CERT_STALE,
        'actor_uid' => (int) $actor['uid'],
        'ip' => (string) $actor['ip'],
        'path' => 'issue',
      ]);
      throw new \RuntimeException('Developer is not certified for L2 credentials');
    }
    if (in_array($explain['reason'], ['dpa_version_mismatch', 'dpa_ack_missing', 'not_certified', 'anonymous', 'login_required', 'missing_permission'], TRUE)) {
      $this->audit()?->log($uid, CredentialAuditLog::EVENT_VERIFY_FAIL, [
        'code' => RepositoryRequestAuth::CODE_DPA_STALE,
        'actor_uid' => (int) $actor['uid'],
        'ip' => (string) $actor['ip'],
        'path' => 'issue',
      ]);
      throw new \RuntimeException('L2 credential denied: ' . $explain['reason']);
    }

    $previous = $this->store()->get((string) $uid);
    $previous = is_array($previous) ? $previous : NULL;
    $rotations = (int) ($previous['rotations'] ?? 0);
    $isRotate = $previous !== NULL && !empty($previous['hash']);

    $plain = self::mintToken();
    $prefix = self::prefixOf($plain);
    $row = [
      'uid' => $uid,
      'hash' => self::digest($plain),
      'prefix' => $prefix,
      'created' => $this->time->getRequestTime(),
      'revoked' => FALSE,
      // Phase I additions (I2/I3). Absent keys keep behaving like OE2.
      'rotations' => $rotations + ($isRotate ? 1 : 0),
      'retired' => $this->retired($previous, (int) $actor['uid'], CredentialLifecycle::EVENT_ROTATE),
      'issued_by' => (int) $actor['uid'],
      'issued_ip' => (string) $actor['ip'],
      'issued_via' => (string) $actor['via'],
      'revoked_at' => 0,
      'revoked_by' => 0,
      'revoke_reason' => '',
      'last_used' => 0,
      'uses' => 0,
    ];
    $this->store()->set((string) $uid, $row);
    $this->audit()?->log($uid, $isRotate ? CredentialAuditLog::EVENT_ROTATE : CredentialAuditLog::EVENT_ISSUE, [
      'code' => RepositoryRequestAuth::CODE_OK,
      'prefix' => $prefix,
      'actor_uid' => (int) $actor['uid'],
      'ip' => (string) $actor['ip'],
      'path' => (string) $actor['via'],
    ]);
    return [
      'token' => $plain,
      'prefix' => $prefix,
      'uid' => $uid,
      'composer' => $this->composerSnippet($uid, $plain),
      'git_clone' => $this->gitCloneUrl($uid, $plain),
    ];
  }

  /**
   * @return array{uid: int, prefix: string, created: int, revoked: bool}|null
   */
  public function status(int $uid): ?array {
    $row = $this->store()->get((string) $uid);
    if (!is_array($row)) {
      return NULL;
    }
    return [
      'uid' => (int) ($row['uid'] ?? $uid),
      'prefix' => (string) ($row['prefix'] ?? ''),
      'created' => (int) ($row['created'] ?? 0),
      'revoked' => !empty($row['revoked']),
    ];
  }

  /**
   * Revokes a credential.
   *
   * `$reason` / `$actorUid` are trailing optional parameters: every OE2 caller
   * doing `revoke($uid)` keeps working verbatim, while the I2 sync point and the
   * `dx:ecosystem-revoke-credential` command can explain themselves.
   */
  public function revoke(int $uid, string $reason = '', int $actorUid = 0, string $event = CredentialAuditLog::EVENT_REVOKE): void {
    $row = $this->store()->get((string) $uid);
    if (!is_array($row)) {
      return;
    }
    $actor = $this->requestActor();
    $code = $event === CredentialAuditLog::EVENT_AUTO_REVOKE
      ? RepositoryRequestAuth::CODE_CERT_REVOKED
      : RepositoryRequestAuth::CODE_REVOKED;
    $prefix = (string) ($row['prefix'] ?? '');
    // Computed before the digest is wiped: the dead hash is what a replay is
    // matched against, and the secret itself was never stored.
    $retired = $this->retired($row, $actorUid > 0 ? $actorUid : (int) $actor['uid'], CredentialLifecycle::EVENT_REVOKE);
    $row['revoked'] = TRUE;
    $row['hash'] = '';
    $row['revoked_at'] = $this->time->getRequestTime();
    $row['revoke_reason'] = $reason;
    $row['revoked_by'] = $actorUid > 0 ? $actorUid : (int) $actor['uid'];
    $row['retired'] = $retired;
    $this->store()->set((string) $uid, $row);
    $this->audit()?->log($uid, $event, [
      'code' => $code,
      'prefix' => $prefix,
      'actor_uid' => (int) ($row['revoked_by'] ?? 0),
      'ip' => (string) $actor['ip'],
      'path' => $reason === '' ? 'revoke' : substr($reason, 0, 64),
    ]);
  }

  /**
   * Revokes the credential because the developer certification just died (I2).
   */
  public function revokeForCertification(int $uid, string $reason = 'certification_revoked', int $actorUid = 0): void {
    $this->revoke($uid, $reason, $actorUid, CredentialAuditLog::EVENT_AUTO_REVOKE);
  }

  /**
   * OE2 contract: uid for a live token, NULL for anything else.
   */
  public function verify(string $plain): ?int {
    $plain = trim($plain);
    if ($plain === '' || !str_starts_with($plain, RepositoryRequestAuth::TOKEN_PREFIX)) {
      return NULL;
    }
    $hash = self::digest($plain);
    foreach ($this->store()->getAll() as $row) {
      if (!is_array($row) || !empty($row['revoked'])) {
        continue;
      }
      $stored = (string) ($row['hash'] ?? '');
      if ($stored !== '' && hash_equals($stored, $hash)) {
        return (int) ($row['uid'] ?? 0) ?: NULL;
      }
    }
    return NULL;
  }

  /**
   * Verification with the I2 state machine and a stable error code.
   *
   * Unlike verify() this re-reads the certification on every call: revoking a
   * developer invalidates their token immediately, even if a queued sync point
   * never ran.
   *
   * @return array{ok: bool, uid: int|null, code: string, state: string, message: string, prefix: string}
   */
  public function verifyDetailed(string $plain, array $context = []): array {
    $plain = trim($plain);
    $shape = RepositoryRequestAuth::shapeCode($plain);
    if ($shape !== RepositoryRequestAuth::CODE_OK) {
      $early = $this->verdict(CredentialLifecycle::classify([
        'token_shape' => $shape,
      ]), NULL, '');
      $this->audit()?->log(0, CredentialAuditLog::EVENT_VERIFY_FAIL, $context + [
        'code' => $early['code'],
        'event' => CredentialAuditLog::EVENT_VERIFY_FAIL,
      ]);
      return $early;
    }
    $hash = self::digest($plain);
    $matched = NULL;
    $superseded = CredentialLifecycle::SUPERSEDED_NO;
    $retiredKind = '';
    $prefix = '';
    foreach ($this->store()->getAll() as $row) {
      if (!is_array($row)) {
        continue;
      }
      $uid = (int) ($row['uid'] ?? 0);
      foreach ($this->retiredOf($row) as $entry) {
        if (hash_equals((string) ($entry['hash'] ?? ''), $hash)) {
          $superseded = CredentialLifecycle::SUPERSEDED_YES;
          $retiredKind = (string) ($entry['kind'] ?? '');
          $prefix = (string) ($entry['prefix'] ?? $prefix);
        }
      }
      $stored = (string) ($row['hash'] ?? '');
      if ($stored !== '' && hash_equals($stored, $hash) && $uid > 0) {
        $matched = $uid;
        $prefix = (string) ($row['prefix'] ?? $prefix);
      }
    }
    $liveRow = $matched === NULL ? [] : $this->row($matched);
    $revoked = !empty($liveRow['revoked']) || $retiredKind === CredentialLifecycle::EVENT_REVOKE;
    $certStatus = $matched === NULL
      ? DeveloperCertificationStore::STATUS_NONE
      : $this->certs->get($matched)['status'];
    $gateReason = 'not_certified';
    if ($matched !== NULL && $certStatus === DeveloperCertificationStore::STATUS_CERTIFIED) {
      $account = User::load($matched);
      $gateReason = $account === NULL
        ? 'login_required'
        : (string) $this->gate->explain($account)['reason'];
    }
    $verdict = CredentialLifecycle::classify([
      'token_shape' => $shape,
      'matched' => $matched !== NULL,
      'revoked' => $revoked,
      'superseded' => $superseded,
      'cert_status' => $certStatus,
      'gate_reason' => $gateReason,
    ]);
    $result = $this->verdict($verdict, $matched, $prefix);
    $tracked = $matched ?? 0;
    if ($tracked > 0) {
      // The auth decision is always audited as verify_*; resource usage is
      // counted separately by the repository endpoints.
      $this->recordUsage($tracked, $result['code'], $context + [
        'prefix' => $prefix,
        'event' => $result['ok'] ? CredentialAuditLog::EVENT_VERIFY_OK : CredentialAuditLog::EVENT_VERIFY_FAIL,
      ]);
    }
    elseif ($this->audit() !== NULL) {
      $this->audit()->log(0, CredentialAuditLog::EVENT_VERIFY_FAIL, [
        'code' => $result['code'],
        'prefix' => $prefix,
        'ip' => (string) ($context['ip'] ?? ''),
        'path' => (string) ($context['path'] ?? ''),
        'user_agent' => (string) ($context['user_agent'] ?? ''),
      ]);
    }
    return $result;
  }

  /**
   * Lifecycle state of a user's credential, honouring certification (I2).
   *
   * @return array{uid: int, state: string, prefix: string, created: int, rotations: int, uses: int, last_used: int, revoked_at: int, revoke_reason: string, issued_by: int, issued_ip: string, cert_status: string}
   */
  public function lifecycle(int $uid): array {
    $row = $this->row($uid);
    $cert = $this->certs->get($uid);
    $state = CredentialLifecycle::stateOf($row);
    if ($state === CredentialLifecycle::STATE_ACTIVE) {
      $suspended = $cert['status'] !== DeveloperCertificationStore::STATUS_CERTIFIED
        || in_array((string) ($this->gate->explain(User::load($uid) ?: new AnonymousUserSession())['reason']), ['dpa_version_mismatch', 'dpa_ack_missing', 'missing_permission'], TRUE);
      $state = $suspended ? CredentialLifecycle::STATE_SUSPENDED : CredentialLifecycle::STATE_ACTIVE;
    }
    return [
      'uid' => $uid,
      'state' => $state,
      'prefix' => (string) ($row['prefix'] ?? ''),
      'created' => (int) ($row['created'] ?? 0),
      'rotations' => (int) ($row['rotations'] ?? 0),
      'uses' => (int) ($row['uses'] ?? 0),
      'last_used' => (int) ($row['last_used'] ?? 0),
      'revoked_at' => (int) ($row['revoked_at'] ?? 0),
      'revoke_reason' => (string) ($row['revoke_reason'] ?? ''),
      'issued_by' => (int) ($row['issued_by'] ?? 0),
      'issued_ip' => (string) ($row['issued_ip'] ?? ''),
      'cert_status' => $cert['status'],
    ];
  }

  /**
   * Every credential row, normalized, for the report (I3).
   *
   * @return list<array<string, mixed>>
   */
  public function listAll(): array {
    $out = [];
    foreach ($this->store()->getAll() as $row) {
      if (!is_array($row)) {
        continue;
      }
      $uid = (int) ($row['uid'] ?? 0);
      $out[] = $this->lifecycle($uid) + ['prefix_only' => (string) ($row['prefix'] ?? '')];
    }
    usort($out, static fn(array $a, array $b): int => $b['last_used'] <=> $a['last_used']);
    return $out;
  }

  /**
   * Bumps the usage counters and mirrors the event into the audit table.
   *
   * @param array<string, mixed> $context
   */
  public function recordUsage(int $uid, string $code, array $context = []): void {
    if ($uid <= 0) {
      return;
    }
    $event = (string) ($context['event'] ?? CredentialAuditLog::EVENT_VERIFY_OK);
    if (!in_array($event, CredentialAuditLog::events(), TRUE)) {
      $event = CredentialAuditLog::EVENT_VERIFY_OK;
    }
    if (in_array($event, CredentialAuditLog::usageEvents(), TRUE)) {
      $row = $this->row($uid);
      if ($row !== []) {
        $row['uses'] = (int) ($row['uses'] ?? 0) + 1;
        $row['last_used'] = $this->time->getRequestTime();
        $row['last_code'] = $code;
        $this->store()->set((string) $uid, $row);
      }
    }
    $this->audit()?->log($uid, $event, [
      'code' => $code,
      'prefix' => (string) ($context['prefix'] ?? ''),
      'actor_uid' => (int) ($context['actor_uid'] ?? 0),
      'ip' => (string) ($context['ip'] ?? ''),
      'path' => (string) ($context['path'] ?? ''),
      'user_agent' => (string) ($context['user_agent'] ?? ''),
    ]);
  }

  /**
   * Raw stored row (never exposes the plaintext: it does not exist at rest).
   *
   * @return array<string, mixed>
   */
  public function row(int $uid): array {
    $row = $this->store()->get((string) $uid);
    return is_array($row) ? $row : [];
  }

  /**
   * @return array<string, mixed>
   */
  public function composerSnippet(int $uid, string $plain): array {
    return [
      'http-basic' => [
        $this->composerHost() => [
          'username' => 'dx-uid-' . $uid,
          'password' => $plain,
        ],
      ],
    ];
  }

  /**
   * The host a partner must authenticate against.
   *
   * OE2 had the single l2_composer_host knob. Phase I added a full base url
   * (real Satis/Artifactory, or a file:// loopback root), and the copy-paste
   * auth.json on /dx/ecosystem/partner has to follow it - otherwise the
   * snippet names a server that no longer answers. With the placeholder
   * config the result is byte-for-byte the OE2 snippet.
   */
  protected function composerHost(): string {
    $config = $this->configFactory->get('dx_ecosystem.settings');
    $fallback = (string) ($config->get('l2_composer_host') ?: ComposerHostPlan::PLACEHOLDER_COMPOSER_HOST);
    $baseUrl = trim((string) ($config->get('l2_composer_base_url') ?? ''));
    $root = trim((string) ($config->get('l2_repository_root') ?? ''));
    if ($baseUrl === '' && $root === '') {
      return $fallback;
    }
    $raw = $config->raw();
    $host = (string) (is_array($raw) ? ComposerHostPlan::fromSettings($raw)['composer_host'] : $fallback);
    return $host !== '' ? $host : $fallback;
  }

  public function gitCloneUrl(int $uid, string $plain): string {
    $host = (string) ($this->configFactory->get('dx_ecosystem.settings')->get('l2_git_host') ?: 'git.drupalx.local');
    return sprintf('https://dx-uid-%d:%s@%s/partner.git', $uid, $plain, $host);
  }

  protected function store(): \Drupal\Core\KeyValueStore\KeyValueStoreInterface {
    return $this->keyValueFactory->get(self::COLLECTION);
  }

  /**
   * Retired digests of one row (rotated-out + revoked), newest last.
   *
   * @param array<string, mixed>|null $row
   *
   * @return list<array<string, mixed>>
   */
  protected function retiredOf(?array $row): array {
    if (!is_array($row) || !is_array($row['retired'] ?? NULL)) {
      return [];
    }
    return array_values(array_filter($row['retired'], static fn($e): bool => is_array($e)));
  }

  /**
   * Appends the outgoing secret of $previous and caps the list.
   *
   * @param array<string, mixed>|null $previous
   */
  protected function retired(?array $previous, int $byUid, string $kind): array {
    $list = $this->retiredOf($previous);
    $hash = (string) ($previous['hash'] ?? '');
    if ($hash !== '') {
      $list[] = [
        'hash' => $hash,
        'prefix' => (string) ($previous['prefix'] ?? ''),
        'at' => (int) ($previous['created'] ?? $this->time->getRequestTime()),
        'kind' => $kind,
        'by' => $byUid,
      ];
    }
    return array_slice($list, -self::HISTORY);
  }

  /**
   * @param array{ok: bool, code: string, state: string, message: string} $verdict
   *
   * @return array{ok: bool, uid: int|null, code: string, state: string, message: string, prefix: string}
   */
  protected function verdict(array $verdict, ?int $uid, string $prefix): array {
    return [
      'ok' => (bool) $verdict['ok'],
      'uid' => $verdict['ok'] ? $uid : NULL,
      'code' => (string) $verdict['code'],
      'state' => (string) $verdict['state'],
      'message' => (string) $verdict['message'],
      'prefix' => $prefix,
    ];
  }

  /**
   * Who/where the current call comes from; safe when there is no request.
   *
   * @return array{uid: int, ip: string, via: string}
   */
  protected function requestActor(): array {
    try {
      $current = \Drupal::currentUser();
      $request = \Drupal::request();
      return [
        'uid' => (int) ($current !== NULL ? $current->id() : 0),
        'ip' => $request !== NULL ? (string) $request->getClientIp() : CredentialAuditLog::fallbackIp(),
        'via' => $request !== NULL && $request->isCli() ? 'cli' : 'web',
      ];
    }
    catch (\Throwable) {
      return ['uid' => 0, 'ip' => CredentialAuditLog::fallbackIp(), 'via' => 'cli'];
    }
  }

  /**
   * Audit log, lazily resolved so the service works before updatedb ran.
   */
  protected function audit(): ?CredentialAuditLog {
    if ($this->audit !== NULL) {
      return $this->audit;
    }
    if (\Drupal::hasService('dx_ecosystem.audit')) {
      /** @var \Drupal\dx_ecosystem\Service\CredentialAuditLog $audit */
      $audit = \Drupal::service('dx_ecosystem.audit');
      return $audit;
    }
    return NULL;
  }

}
