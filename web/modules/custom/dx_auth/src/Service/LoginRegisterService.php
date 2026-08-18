<?php

declare(strict_types=1);

namespace Drupal\dx_auth\Service;

use Drupal\Component\Utility\EmailValidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\user\UserInterface;
use Drupal\user\UserStorageInterface;

/**
 * Email/username lookup and auto-register for unified account login.
 *
 * Recovered from the 2026-08-13 dx_portal LoginRegister work (archived in
 * dx-local-wip-20260815) and moved into dx_auth so the Topstar-style login
 * UI can call it without replacing core /user/login.
 */
final class LoginRegisterService {

  public const MIN_PASSWORD_LENGTH = 8;

  protected UserStorageInterface $userStorage;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EmailValidatorInterface $emailValidator,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerChannelInterface $logger,
  ) {
    $this->userStorage = $this->entityTypeManager->getStorage('user');
  }

  /**
   * Whether first-time email+password should create an account.
   */
  public function isAutoRegisterEnabled(): bool {
    $flag = $this->configFactory->get('dx_auth.settings')->get('account_auto_register');
    return $flag === NULL ? TRUE : (bool) $flag;
  }

  /**
   * Finds an account by email (preferred) or username.
   */
  public function findAccount(string $identifier): ?UserInterface {
    $identifier = trim($identifier);
    if ($identifier === '') {
      return NULL;
    }

    if ($this->emailValidator->isValid($identifier)) {
      $byMail = $this->userStorage->loadByProperties(['mail' => $identifier]);
      if ($account = reset($byMail)) {
        return $account instanceof UserInterface ? $account : NULL;
      }
    }

    $byName = $this->userStorage->loadByProperties(['name' => $identifier]);
    $account = reset($byName);
    return $account instanceof UserInterface ? $account : NULL;
  }

  /**
   * Returns TRUE when the identifier looks like an email address.
   */
  public function isEmail(string $identifier): bool {
    return $this->emailValidator->isValid(trim($identifier));
  }

  /**
   * Validates password strength for auto-registration.
   *
   * @return string|null
   *   Error message, or NULL if acceptable.
   */
  public function validatePassword(string $password): ?string {
    $password = trim($password);
    if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
      return (string) t('密码过短，请至少使用 @min 位字符。', [
        '@min' => self::MIN_PASSWORD_LENGTH,
      ]);
    }
    return NULL;
  }

  /**
   * Creates an active account from email + password.
   *
   * @return array{account?:\Drupal\user\UserInterface, error?:string}
   */
  public function createAccount(string $mail, string $password): array {
    $mail = trim($mail);
    if (!$this->emailValidator->isValid($mail)) {
      return ['error' => (string) t('请使用有效的电子邮箱进行注册。')];
    }

    if ($passwordError = $this->validatePassword($password)) {
      return ['error' => $passwordError];
    }

    if ($this->findAccount($mail)) {
      return ['error' => (string) t('该邮箱已注册，请直接登录。')];
    }

    if (!$this->isAutoRegisterEnabled()) {
      return ['error' => (string) t('新用户自动注册已关闭，请联系管理员。')];
    }

    $name = $this->generateUniqueUsername($mail);

    try {
      /** @var \Drupal\user\UserInterface $account */
      $account = $this->userStorage->create([
        'name' => $name,
        'mail' => $mail,
        'status' => 1,
        'init' => $mail,
      ]);
      $account->setPassword(trim($password));

      $violations = $account->validate();
      if ($violations->count() > 0) {
        $messages = [];
        foreach ($violations as $violation) {
          $messages[] = (string) $violation->getMessage();
        }
        return ['error' => implode(' ', $messages)];
      }

      $account->save();
      $this->logger->notice('Auto-registered user %name <%mail>.', [
        '%name' => $account->getAccountName(),
        '%mail' => $mail,
      ]);

      return ['account' => $account];
    }
    catch (\Throwable $e) {
      $this->logger->error('Auto-register failed for <%mail>: @message', [
        '%mail' => $mail,
        '@message' => $e->getMessage(),
      ]);
      return ['error' => (string) t('该邮箱已注册或注册失败，请使用正确密码登录，或稍后重试。')];
    }
  }

  /**
   * Builds a unique Drupal username from an email local-part.
   */
  public function generateUniqueUsername(string $mail): string {
    $local = strstr($mail, '@', TRUE);
    if ($local === FALSE || $local === '') {
      $local = 'user';
    }
    $base = preg_replace('/[^\x{80}-\x{F7}a-zA-Z0-9_.-]/u', '', $local) ?? '';
    $base = trim($base, '_.-');
    if ($base === '') {
      $base = 'user';
    }
    $base = mb_substr($base, 0, 40);
    $name = $base;
    $i = 0;
    while ($this->userStorage->loadByProperties(['name' => $name])) {
      $i++;
      $suffix = '_' . $i;
      $name = mb_substr($base, 0, UserInterface::USERNAME_MAX_LENGTH - mb_strlen($suffix)) . $suffix;
    }
    return $name;
  }

}
