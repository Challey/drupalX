<?php

declare(strict_types=1);

namespace Drupal\dx_ecosystem\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\user\Entity\User;

/**
 * Issues hashed L2 Composer/Git credentials (plaintext shown once).
 */
final class PartnerCredentialStore {

  public const COLLECTION = 'dx_ecosystem.credentials';

  public function __construct(
    protected KeyValueFactoryInterface $keyValueFactory,
    protected DeveloperCertificationStore $certs,
    protected DeveloperGate $gate,
    protected ConfigFactoryInterface $configFactory,
    protected TimeInterface $time,
  ) {}

  /**
   * @return array{token: string, prefix: string, uid: int, composer: array<string, mixed>, git_clone: string}
   */
  public function issue(int $uid): array {
    $account = User::load($uid);
    if ($account === NULL) {
      throw new \RuntimeException("User $uid not found");
    }
    $explain = $this->gate->explain($account);
    $cert = $this->certs->get($uid);
    if ($cert['status'] !== DeveloperCertificationStore::STATUS_CERTIFIED) {
      throw new \RuntimeException('Developer is not certified for L2 credentials');
    }
    if (in_array($explain['reason'], ['dpa_version_mismatch', 'dpa_ack_missing', 'not_certified', 'anonymous', 'login_required', 'missing_permission'], TRUE)) {
      throw new \RuntimeException('L2 credential denied: ' . $explain['reason']);
    }

    $plain = 'dxl2_' . bin2hex(random_bytes(24));
    $prefix = substr($plain, 0, 12);
    $row = [
      'uid' => $uid,
      'hash' => hash('sha256', $plain),
      'prefix' => $prefix,
      'created' => $this->time->getRequestTime(),
      'revoked' => FALSE,
    ];
    $this->store()->set((string) $uid, $row);
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

  public function revoke(int $uid): void {
    $row = $this->store()->get((string) $uid);
    if (!is_array($row)) {
      return;
    }
    $row['revoked'] = TRUE;
    $row['hash'] = '';
    $this->store()->set((string) $uid, $row);
  }

  public function verify(string $plain): ?int {
    $plain = trim($plain);
    if ($plain === '' || !str_starts_with($plain, 'dxl2_')) {
      return NULL;
    }
    $hash = hash('sha256', $plain);
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
   * @return array<string, mixed>
   */
  public function composerSnippet(int $uid, string $plain): array {
    $host = (string) ($this->configFactory->get('dx_ecosystem.settings')->get('l2_composer_host') ?: 'packages.drupalx.local');
    return [
      'http-basic' => [
        $host => [
          'username' => 'dx-uid-' . $uid,
          'password' => $plain,
        ],
      ],
    ];
  }

  public function gitCloneUrl(int $uid, string $plain): string {
    $host = (string) ($this->configFactory->get('dx_ecosystem.settings')->get('l2_git_host') ?: 'git.drupalx.local');
    return sprintf('https://dx-uid-%d:%s@%s/partner.git', $uid, $plain, $host);
  }

  protected function store(): \Drupal\Core\KeyValueStore\KeyValueStoreInterface {
    return $this->keyValueFactory->get(self::COLLECTION);
  }

}
