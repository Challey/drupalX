<?php

declare(strict_types=1);

namespace Drupal\dx_auth\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Password\PasswordInterface;
use Drupal\user\UserInterface;

/**
 * Binds enterprise credit IDs to Drupal accounts and performs password login.
 */
class EnterpriseAccountLinker {

  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected PasswordInterface $password,
    protected LoggerChannelInterface $logger,
  ) {}

  /**
   * Creates or updates a credit-code → user binding.
   */
  public function bind(string $creditCode, int $uid, string $companyName = ''): bool {
    $identity = \Drupal::service('dx_auth.enterprise_identity');
    assert($identity instanceof EnterpriseIdentityService);
    $normalized = $identity->normalize($creditCode);
    if ($normalized === '' || !$identity->validate($normalized) || $uid <= 0) {
      return FALSE;
    }

    $now = time();
    $existing = $this->database->select('dx_auth_enterprise', 'e')
      ->fields('e', ['id'])
      ->condition('credit_code', $normalized)
      ->range(0, 1)
      ->execute()
      ->fetchField();

    if ($existing) {
      $this->database->update('dx_auth_enterprise')
        ->fields([
          'uid' => $uid,
          'company_name' => mb_substr($companyName, 0, 255),
          'changed' => $now,
        ])
        ->condition('id', (int) $existing)
        ->execute();
    }
    else {
      $this->database->insert('dx_auth_enterprise')
        ->fields([
          'credit_code' => $normalized,
          'uid' => $uid,
          'company_name' => mb_substr($companyName, 0, 255),
          'created' => $now,
          'changed' => $now,
        ])
        ->execute();
    }

    return TRUE;
  }

  /**
   * Authenticates by enterprise credit ID + account password.
   *
   * @return array{ok: bool, msg: string, user?: \Drupal\user\UserInterface}
   */
  public function loginByEnterprise(string $creditCode, string $password): array {
    /** @var \Drupal\dx_auth\Service\EnterpriseIdentityService $identity */
    $identity = \Drupal::service('dx_auth.enterprise_identity');
    $normalized = $identity->normalize($creditCode);
    if (!$identity->validate($normalized)) {
      return ['ok' => FALSE, 'msg' => 'invalid_credit_code'];
    }
    if ($password === '') {
      return ['ok' => FALSE, 'msg' => 'empty_password'];
    }

    $resolved = $identity->resolve($normalized);
    if (!$resolved['found'] || empty($resolved['uid'])) {
      return ['ok' => FALSE, 'msg' => 'enterprise_not_bound'];
    }

    $user = $this->entityTypeManager->getStorage('user')->load((int) $resolved['uid']);
    if (!$user instanceof UserInterface || !$user->isActive()) {
      return ['ok' => FALSE, 'msg' => 'account_unavailable'];
    }

    $hash = (string) $user->getPassword();
    if ($hash === '' || !$this->password->check($password, $hash)) {
      return ['ok' => FALSE, 'msg' => 'bad_password'];
    }

    // Rehash if the password service requests it.
    if ($this->password->needsRehash($hash)) {
      $user->setPassword($password);
      $user->save();
    }

    return ['ok' => TRUE, 'msg' => 'ok', 'user' => $user];
  }

  /**
   * Lists all enterprise bindings.
   *
   * @return array<int, array{id: int, credit_code: string, uid: int, company_name: string, created: int, changed: int}>
   */
  public function listBindings(): array {
    try {
      $rows = $this->database->select('dx_auth_enterprise', 'e')
        ->fields('e')
        ->orderBy('changed', 'DESC')
        ->execute()
        ->fetchAll(\PDO::FETCH_ASSOC);
    }
    catch (\Throwable $e) {
      $this->logger->error('listBindings failed: @m', ['@m' => $e->getMessage()]);
      return [];
    }

    $out = [];
    foreach ($rows as $row) {
      $out[] = [
        'id' => (int) $row['id'],
        'credit_code' => (string) $row['credit_code'],
        'uid' => (int) $row['uid'],
        'company_name' => (string) $row['company_name'],
        'created' => (int) $row['created'],
        'changed' => (int) $row['changed'],
      ];
    }
    return $out;
  }

}
