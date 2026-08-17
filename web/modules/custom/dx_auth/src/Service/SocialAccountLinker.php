<?php

declare(strict_types=1);

namespace Drupal\dx_auth\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\TempStore\SharedTempStoreFactory;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/**
 * Binds WeChat / mobile / Google identities (Topstar AccountLinker port).
 */
class SocialAccountLinker {

  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected SharedTempStoreFactory $tempStoreFactory,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerChannelInterface $logger,
  ) {}

  /**
   * Verifies Aliyun SMS OTP from tempstore.
   */
  public function verifySmsCode(string $mobile, string $code): bool {
    $mobile = $this->normalizeMobile($mobile);
    if ($mobile === '' || $code === '') {
      return FALSE;
    }
    $store = $this->tempStoreFactory->get('dx_auth_sms');
    $row = $store->get('mobile_code_' . $mobile);
    if (empty($row['code']) || ($row['expire'] ?? 0) < time()) {
      return FALSE;
    }
    return (string) $row['code'] === (string) $code;
  }

  /**
   * Stores an OTP for later verify (TTL 300s).
   */
  public function storeSmsCode(string $mobile, string $code): void {
    $mobile = $this->normalizeMobile($mobile);
    $store = $this->tempStoreFactory->get('dx_auth_sms');
    $store->set('mobile_code_' . $mobile, [
      'code' => $code,
      'expire' => time() + 300,
    ]);
  }

  /**
   * Login or create by verified mobile.
   *
   * @return array{user: \Drupal\user\UserInterface, created: bool}
   */
  public function loginOrCreateByMobile(string $mobile): array {
    $mobile = $this->normalizeMobile($mobile);
    $uid = $this->database->select('dx_auth_mobile', 'm')
      ->fields('m', ['uid'])
      ->condition('mobile', $mobile)
      ->range(0, 1)
      ->execute()
      ->fetchField();
    if ($uid) {
      $user = $this->entityTypeManager->getStorage('user')->load((int) $uid);
      if ($user instanceof UserInterface && $user->isActive()) {
        return ['user' => $user, 'created' => FALSE];
      }
    }

    $byName = user_load_by_name($mobile);
    if ($byName instanceof UserInterface) {
      $this->bindMobile((int) $byName->id(), $mobile);
      return ['user' => $byName, 'created' => FALSE];
    }

    $user = User::create();
    $user->setUsername($this->uniqueUsername('m' . substr($mobile, -8)));
    $user->activate();
    $user->save();
    $this->bindMobile((int) $user->id(), $mobile);
    return ['user' => $user, 'created' => TRUE];
  }

  /**
   * Login or create by WeChat openid.
   *
   * @return array{user: \Drupal\user\UserInterface, created: bool}
   */
  public function loginOrCreateByWechat(string $openid): array {
    $openid = trim($openid);
    $uid = $this->database->select('dx_auth_wechat', 'w')
      ->fields('w', ['uid'])
      ->condition('openid', $openid)
      ->range(0, 1)
      ->execute()
      ->fetchField();
    if ($uid) {
      $user = $this->entityTypeManager->getStorage('user')->load((int) $uid);
      if ($user instanceof UserInterface && $user->isActive()) {
        return ['user' => $user, 'created' => FALSE];
      }
    }

    $user = User::create();
    $user->setUsername($this->uniqueUsername('wx' . substr(md5($openid), 0, 10)));
    $user->activate();
    $user->save();
    $this->bindWechat((int) $user->id(), $openid);
    return ['user' => $user, 'created' => TRUE];
  }

  /**
   * Login or create by Google subject + verified email.
   *
   * @return array{user: \Drupal\user\UserInterface, created: bool}
   */
  public function loginOrCreateByGoogle(string $googleSub, string $email, string $displayName = ''): array {
    $googleSub = trim($googleSub);
    $email = mb_strtolower(trim($email));

    $uid = $this->database->select('dx_auth_google', 'g')
      ->fields('g', ['uid'])
      ->condition('google_sub', $googleSub)
      ->range(0, 1)
      ->execute()
      ->fetchField();
    if ($uid) {
      $user = $this->entityTypeManager->getStorage('user')->load((int) $uid);
      if ($user instanceof UserInterface && $user->isActive()) {
        return ['user' => $user, 'created' => FALSE];
      }
    }

    if ($email !== '') {
      $users = $this->entityTypeManager->getStorage('user')->loadByProperties(['mail' => $email]);
      if ($users) {
        $user = reset($users);
        $this->bindGoogle((int) $user->id(), $googleSub, $email);
        return ['user' => $user, 'created' => FALSE];
      }
    }

    $user = User::create();
    $base = $displayName !== '' ? preg_replace('/[^a-zA-Z0-9_\-\x{4e00}-\x{9fff}]+/u', '', $displayName) : '';
    $user->setUsername($this->uniqueUsername($base !== '' ? $base : ('g' . substr(md5($googleSub), 0, 10))));
    if ($email !== '') {
      $user->setEmail($email);
    }
    $user->activate();
    $user->save();
    $this->bindGoogle((int) $user->id(), $googleSub, $email);
    return ['user' => $user, 'created' => TRUE];
  }

  /**
   * Binds openid to uid.
   */
  public function bindWechat(int $uid, string $openid): void {
    $openid = trim($openid);
    $existing = $this->database->select('dx_auth_wechat', 'w')
      ->fields('w', ['id', 'uid'])
      ->condition('openid', $openid)
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();
    if ($existing) {
      $this->database->update('dx_auth_wechat')
        ->fields(['uid' => $uid])
        ->condition('id', (int) $existing['id'])
        ->execute();
      return;
    }
    $this->database->insert('dx_auth_wechat')
      ->fields(['openid' => $openid, 'uid' => $uid, 'created' => time()])
      ->execute();
  }

  /**
   * Binds mobile to uid.
   */
  public function bindMobile(int $uid, string $mobile): void {
    $mobile = $this->normalizeMobile($mobile);
    $existing = $this->database->select('dx_auth_mobile', 'm')
      ->fields('m', ['id'])
      ->condition('mobile', $mobile)
      ->range(0, 1)
      ->execute()
      ->fetchField();
    if ($existing) {
      $this->database->update('dx_auth_mobile')
        ->fields(['uid' => $uid])
        ->condition('id', (int) $existing)
        ->execute();
      return;
    }
    $this->database->insert('dx_auth_mobile')
      ->fields(['mobile' => $mobile, 'uid' => $uid, 'created' => time()])
      ->execute();
  }

  /**
   * Binds Google sub to uid.
   */
  public function bindGoogle(int $uid, string $googleSub, string $email = ''): void {
    $googleSub = trim($googleSub);
    $email = mb_strtolower(trim($email));
    $existing = $this->database->select('dx_auth_google', 'g')
      ->fields('g', ['id'])
      ->condition('google_sub', $googleSub)
      ->range(0, 1)
      ->execute()
      ->fetchField();
    if ($existing) {
      $this->database->update('dx_auth_google')
        ->fields(['uid' => $uid, 'email' => $email])
        ->condition('id', (int) $existing)
        ->execute();
      return;
    }
    $this->database->insert('dx_auth_google')
      ->fields([
        'google_sub' => $googleSub,
        'email' => $email,
        'uid' => $uid,
        'created' => time(),
      ])
      ->execute();
  }

  /**
   * Normalizes mobile to digits / leading +.
   */
  public function normalizeMobile(string $mobile): string {
    $mobile = trim($mobile);
    $mobile = preg_replace('/[^\d+]/', '', $mobile) ?? '';
    if (str_starts_with($mobile, '+')) {
      return '+' . preg_replace('/\D+/', '', substr($mobile, 1));
    }
    return preg_replace('/\D+/', '', $mobile) ?? '';
  }

  /**
   * Ensures a unique username.
   */
  protected function uniqueUsername(string $base): string {
    $base = trim($base) ?: 'user';
    $base = mb_substr($base, 0, 50);
    $candidate = $base;
    $i = 0;
    while (user_load_by_name($candidate)) {
      $i++;
      $candidate = mb_substr($base, 0, 45) . $i;
    }
    return $candidate;
  }

}
