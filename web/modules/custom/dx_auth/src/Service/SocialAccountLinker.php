<?php

declare(strict_types=1);

namespace Drupal\dx_auth\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\TempStore\SharedTempStoreFactory;
use Drupal\user\Entity\User;
use Drupal\user\UserAuthenticationInterface;
use Drupal\user\UserAuthInterface;
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
    protected AccountProxyInterface $currentUser,
    protected UserAuthInterface|UserAuthenticationInterface $userAuth,
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
    if ($bound = $this->bindToCurrent('mobile', $mobile)) {
      return $bound;
    }
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
    if ($bound = $this->bindToCurrent('wechat', $openid)) {
      return $bound;
    }
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
    if ($bound = $this->bindToCurrent('google', $googleSub, $email)) {
      return $bound;
    }

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
   * Binding status for the unified-login page.
   *
   * @return array{wechat: bool, google: bool, google_email: string, mobile: bool, mobile_masked: string}
   */
  public function statusForUid(int $uid): array {
    $empty = [
      'wechat' => FALSE,
      'google' => FALSE,
      'google_email' => '',
      'mobile' => FALSE,
      'mobile_masked' => '',
    ];
    if ($uid <= 0) {
      return $empty;
    }
    $wechat = $this->database->select('dx_auth_wechat', 'w')
      ->fields('w', ['openid'])
      ->condition('uid', $uid)
      ->range(0, 1)
      ->execute()
      ->fetchField();
    $google = $this->database->select('dx_auth_google', 'g')
      ->fields('g', ['google_sub', 'email'])
      ->condition('uid', $uid)
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();
    $mobile = (string) $this->database->select('dx_auth_mobile', 'm')
      ->fields('m', ['mobile'])
      ->condition('uid', $uid)
      ->range(0, 1)
      ->execute()
      ->fetchField();
    return [
      'wechat' => (bool) $wechat,
      'google' => !empty($google['google_sub']),
      'google_email' => (string) ($google['email'] ?? ''),
      'mobile' => $mobile !== '',
      'mobile_masked' => $mobile !== '' ? $this->maskMobile($mobile) : '',
    ];
  }

  /**
   * If the visitor is already logged in, bind this identity instead of creating a user.
   *
   * @return array{user: \Drupal\user\UserInterface, created: bool, bound?: bool, conflict?: bool}|null
   */
  protected function bindToCurrent(string $kind, string $key, string $email = ''): ?array {
    $currentId = (int) $this->currentUser->id();
    if ($currentId < 1 || $key === '') {
      return NULL;
    }
    $user = $this->entityTypeManager->getStorage('user')->load($currentId);
    if (!$user instanceof UserInterface) {
      return NULL;
    }

    $existingUid = match ($kind) {
      'wechat' => $this->database->select('dx_auth_wechat', 'w')->fields('w', ['uid'])->condition('openid', $key)->range(0, 1)->execute()->fetchField(),
      'mobile' => $this->database->select('dx_auth_mobile', 'm')->fields('m', ['uid'])->condition('mobile', $key)->range(0, 1)->execute()->fetchField(),
      'google' => $this->database->select('dx_auth_google', 'g')->fields('g', ['uid'])->condition('google_sub', $key)->range(0, 1)->execute()->fetchField(),
      default => NULL,
    };
    if ($existingUid && (int) $existingUid !== $currentId) {
      $other = $this->entityTypeManager->getStorage('user')->load((int) $existingUid);
      if ($other instanceof UserInterface) {
        $merge = $this->mergeUsers($user, $other);
        if (empty($merge['ok'])) {
          return [
            'user' => $user,
            'created' => FALSE,
            'conflict' => TRUE,
            'msg' => (string) ($merge['msg'] ?? 'conflict'),
          ];
        }
        $reloaded = $this->entityTypeManager->getStorage('user')->load($currentId);
        if ($reloaded instanceof UserInterface) {
          $user = $reloaded;
        }
      }
    }
    match ($kind) {
      'wechat' => $this->bindWechat($currentId, $key),
      'mobile' => $this->bindMobile($currentId, $key),
      'google' => $this->bindGoogle($currentId, $key, $email),
      default => NULL,
    };
    return ['user' => $user, 'created' => FALSE, 'bound' => TRUE];
  }

  /**
   * Masks a mobile number for display.
   */
  protected function maskMobile(string $mobile): string {
    $digits = preg_replace('/\D+/', '', $mobile) ?? '';
    if (strlen($digits) < 7) {
      return $mobile;
    }
    return substr($digits, 0, 3) . '****' . substr($digits, -4);
  }

  /**
   * Bind verified mobile to the logged-in user (merge if the number is elsewhere).
   *
   * @return array{ok: bool, msg: string, merged_uid?: int}
   */
  public function bindMobileToUser(UserInterface $user, string $mobile): array {
    $mobile = $this->normalizeMobile($mobile);
    if ($mobile === '') {
      return ['ok' => FALSE, 'msg' => 'invalid_mobile'];
    }
    $uid = (int) $user->id();
    $mine = (string) $this->database->select('dx_auth_mobile', 'm')
      ->fields('m', ['mobile'])
      ->condition('uid', $uid)
      ->range(0, 1)
      ->execute()
      ->fetchField();
    if ($mine === $mobile) {
      return ['ok' => TRUE, 'msg' => 'already_bound'];
    }
    $otherUid = $this->database->select('dx_auth_mobile', 'm')
      ->fields('m', ['uid'])
      ->condition('mobile', $mobile)
      ->range(0, 1)
      ->execute()
      ->fetchField();
    if ($otherUid && (int) $otherUid !== $uid) {
      $other = $this->entityTypeManager->getStorage('user')->load((int) $otherUid);
      if ($other instanceof UserInterface) {
        $merge = $this->mergeUsers($user, $other);
        if (empty($merge['ok'])) {
          return $merge;
        }
        $user = $this->entityTypeManager->getStorage('user')->load($uid) ?: $user;
      }
    }
    $this->bindMobile($uid, $mobile);
    return ['ok' => TRUE, 'msg' => 'bound'];
  }

  /**
   * Link WeChat openid to a Drupal user (bind / merge).
   *
   * @return array{ok: bool, msg: string, uid?: int}
   */
  public function linkOpenidToUser(int $uid, string $openid): array {
    $openid = trim($openid);
    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$user instanceof UserInterface || $openid === '') {
      return ['ok' => FALSE, 'msg' => 'user_not_found'];
    }
    $existingUid = $this->database->select('dx_auth_wechat', 'w')
      ->fields('w', ['uid'])
      ->condition('openid', $openid)
      ->range(0, 1)
      ->execute()
      ->fetchField();
    if ($existingUid && (int) $existingUid === $uid) {
      return ['ok' => TRUE, 'msg' => 'already_linked', 'uid' => $uid];
    }
    if ($existingUid && (int) $existingUid !== $uid) {
      $other = $this->entityTypeManager->getStorage('user')->load((int) $existingUid);
      if ($other instanceof UserInterface) {
        $merge = $this->mergeUsers($user, $other);
        if (empty($merge['ok'])) {
          return $merge;
        }
      }
    }
    $this->bindWechat($uid, $openid);
    return ['ok' => TRUE, 'msg' => 'linked', 'uid' => $uid];
  }

  /**
   * Link Google subject to a Drupal user (bind / merge).
   *
   * @return array{ok: bool, msg: string, uid?: int}
   */
  public function linkGoogleToUser(int $uid, string $googleSub, string $email = ''): array {
    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$user instanceof UserInterface) {
      return ['ok' => FALSE, 'msg' => 'user_not_found'];
    }
    $googleSub = trim($googleSub);
    $email = mb_strtolower(trim($email));
    if ($googleSub === '') {
      return ['ok' => FALSE, 'msg' => 'empty_sub'];
    }
    $existingUid = $this->database->select('dx_auth_google', 'g')
      ->fields('g', ['uid'])
      ->condition('google_sub', $googleSub)
      ->range(0, 1)
      ->execute()
      ->fetchField();
    if ($existingUid && (int) $existingUid === $uid) {
      $this->bindGoogle($uid, $googleSub, $email);
      return ['ok' => TRUE, 'msg' => 'already_linked', 'uid' => $uid];
    }
    if ($existingUid && (int) $existingUid !== $uid) {
      $other = $this->entityTypeManager->getStorage('user')->load((int) $existingUid);
      if ($other instanceof UserInterface) {
        $merge = $this->mergeUsers($user, $other);
        if (empty($merge['ok'])) {
          return $merge;
        }
      }
    }
    if ($email !== '' && !$user->getEmail()) {
      $user->setEmail($email);
      $user->save();
    }
    $this->bindGoogle($uid, $googleSub, $email);
    return ['ok' => TRUE, 'msg' => 'linked', 'uid' => $uid];
  }

  /**
   * Claim another account by email/username + password and merge into current.
   *
   * @return array{ok: bool, msg: string, merged_uid?: int}
   */
  public function claimAccountByPassword(UserInterface $current, string $login, string $password): array {
    $login = trim($login);
    if ($login === '' || $password === '') {
      return ['ok' => FALSE, 'msg' => 'empty_credentials'];
    }
    $storage = $this->entityTypeManager->getStorage('user');
    $other = NULL;
    if (str_contains($login, '@')) {
      $found = $storage->loadByProperties(['mail' => $login]);
      $other = $found ? reset($found) : NULL;
    }
    if (!$other instanceof UserInterface) {
      $byName = $storage->loadByProperties(['name' => $login]);
      $other = $byName ? reset($byName) : NULL;
    }
    if (!$other instanceof UserInterface) {
      return ['ok' => FALSE, 'msg' => 'account_not_found'];
    }
    if ((int) $other->id() === (int) $current->id()) {
      return ['ok' => TRUE, 'msg' => 'same'];
    }
    $ok = FALSE;
    if ($this->userAuth instanceof UserAuthenticationInterface) {
      $ok = $this->userAuth->authenticateAccount($other, $password);
    }
    else {
      $ok = (bool) $this->userAuth->authenticate($other->getAccountName(), $password);
    }
    if (!$ok) {
      return ['ok' => FALSE, 'msg' => 'bad_password'];
    }
    return $this->mergeUsers($current, $other);
  }

  /**
   * Merge $other into $primary; block the orphan afterward.
   *
   * @return array{ok: bool, msg: string, merged_uid?: int}
   */
  public function mergeUsers(UserInterface $primary, UserInterface $other): array {
    $pid = (int) $primary->id();
    $oid = (int) $other->id();
    if ($pid === $oid) {
      return ['ok' => TRUE, 'msg' => 'same'];
    }
    if ($oid === 1) {
      return ['ok' => FALSE, 'msg' => 'protected_account'];
    }

    $pm = (string) $this->database->select('dx_auth_mobile', 'm')->fields('m', ['mobile'])->condition('uid', $pid)->range(0, 1)->execute()->fetchField();
    $om = (string) $this->database->select('dx_auth_mobile', 'm')->fields('m', ['mobile'])->condition('uid', $oid)->range(0, 1)->execute()->fetchField();
    if ($pm !== '' && $om !== '' && $pm !== $om) {
      return ['ok' => FALSE, 'msg' => 'mobile_conflict'];
    }

    $this->reassignIdentityRows('dx_auth_wechat', 'openid', $oid, $pid);
    $this->reassignIdentityRows('dx_auth_google', 'google_sub', $oid, $pid);
    if ($om !== '') {
      if ($pm === $om) {
        $this->database->delete('dx_auth_mobile')->condition('uid', $oid)->execute();
      }
      else {
        $this->database->update('dx_auth_mobile')->fields(['uid' => $pid])->condition('uid', $oid)->execute();
      }
    }
    if ($this->database->schema()->tableExists('dx_auth_enterprise')) {
      $this->database->update('dx_auth_enterprise')->fields(['uid' => $pid, 'changed' => time()])->condition('uid', $oid)->execute();
    }

    $primary = $this->entityTypeManager->getStorage('user')->load($pid);
    $other = $this->entityTypeManager->getStorage('user')->load($oid);
    if ($primary instanceof UserInterface && $other instanceof UserInterface) {
      if (!$primary->getEmail() && $other->getEmail()) {
        $primary->setEmail($other->getEmail());
      }
      foreach ($other->getRoles(TRUE) as $role) {
        $primary->addRole($role);
      }
      $primary->save();
      try {
        $other->block();
        $name = $other->getAccountName();
        if (!str_starts_with($name, 'merged_')) {
          $other->setUsername('merged_' . $oid . '_' . mb_substr($name, 0, 40));
        }
        $other->save();
      }
      catch (\Throwable $e) {
        $this->logger->warning('Merge block failed for uid @u: @m', [
          '@u' => (string) $oid,
          '@m' => $e->getMessage(),
        ]);
      }
    }

    $this->logger->notice('Merged uid @o into uid @p.', [
      '@o' => (string) $oid,
      '@p' => (string) $pid,
    ]);
    return ['ok' => TRUE, 'msg' => 'merged', 'merged_uid' => $oid];
  }

  /**
   * User-facing Chinese copy for merge/bind result keys.
   */
  public function messageFor(string $key): string {
    $map = [
      'already_bound' => '该手机已绑定当前账号',
      'bound' => '手机绑定成功',
      'already_linked' => '已绑定到当前账号',
      'linked' => '绑定成功',
      'merged' => '已验证并合并到当前账号',
      'same' => '已是同一账号',
      'empty_credentials' => '请填写账号和密码',
      'account_not_found' => '未找到该用户名或邮箱',
      'bad_password' => '密码错误，无法验证归属',
      'mobile_conflict' => '两边账号手机号冲突，请联系客服',
      'protected_account' => '不能合并管理员账号，请联系客服',
      'invalid_mobile' => '手机号格式不正确',
      'user_not_found' => '用户不存在',
    ];
    return $map[$key] ?? $key;
  }

  /**
   * Moves identity rows from $fromUid to $toUid, dropping duplicates of the unique key.
   */
  protected function reassignIdentityRows(string $table, string $uniqueField, int $fromUid, int $toUid): void {
    if (!$this->database->schema()->tableExists($table)) {
      return;
    }
    $rows = $this->database->select($table, 't')
      ->fields('t', ['id', $uniqueField])
      ->condition('uid', $fromUid)
      ->execute()
      ->fetchAll();
    foreach ($rows as $row) {
      $dup = $this->database->select($table, 't')
        ->fields('t', ['id'])
        ->condition($uniqueField, $row->{$uniqueField})
        ->condition('uid', $toUid)
        ->range(0, 1)
        ->execute()
        ->fetchField();
      if ($dup) {
        $this->database->delete($table)->condition('id', (int) $row->id)->execute();
        continue;
      }
      $this->database->update($table)
        ->fields(['uid' => $toUid])
        ->condition('id', (int) $row->id)
        ->execute();
    }
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
