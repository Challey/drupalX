<?php

declare(strict_types=1);

namespace Drupal\dx_auth\Controller;

use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\dx_auth\Service\LoginRegisterService;
use Drupal\user\UserAuthenticationInterface;
use Drupal\user\UserAuthInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * JSON login-or-register for the account tab on unified login.
 */
class AccountAuthController extends ControllerBase {

  public function __construct(
    protected LoginRegisterService $loginRegister,
    protected FloodInterface $flood,
    protected CsrfTokenGenerator $csrfToken,
    protected UserAuthInterface|UserAuthenticationInterface $userAuth,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('dx_auth.login_register'),
      $container->get('flood'),
      $container->get('csrf_token'),
      $container->get('user.auth'),
    );
  }

  /**
   * POST /dx/auth/account_login
   */
  public function login(Request $request): JsonResponse {
    if (!$this->csrfHeaderValid($request)) {
      return $this->json(0, '安全校验失败，请刷新页面后重试');
    }

    $identifier = trim((string) ($request->request->get('name') ?? ''));
    $password = (string) ($request->request->get('password') ?? $request->request->get('pass') ?? '');
    $destination = (string) ($request->request->get('destination') ?? '/');

    $ip = $request->getClientIp() ?: '0.0.0.0';
    if (!$this->flood->isAllowed('dx_auth.account_login_ip', 30, 3600, $ip)) {
      return $this->json(0, '尝试次数过多，请稍后再试');
    }

    if ($identifier === '' || $password === '') {
      return $this->json(0, '请填写账号与密码。');
    }

    $account = $this->loginRegister->findAccount($identifier);
    $created = FALSE;

    if ($account instanceof UserInterface) {
      if ($account->isBlocked()) {
        $this->flood->register('dx_auth.account_login_ip', 3600, $ip);
        return $this->json(0, '该账号尚未激活或已被禁用。');
      }
      if (!$this->passwordsMatch($account, $password)) {
        $this->flood->register('dx_auth.account_login_ip', 3600, $ip);
        return $this->json(0, '密码不正确，请重试。或通过「忘记密码」重置。');
      }
    }
    else {
      if (!$this->loginRegister->isEmail($identifier)) {
        $this->flood->register('dx_auth.account_login_ip', 3600, $ip);
        return $this->json(0, '未检测到账号。新用户请使用有效的电子邮箱进行自动注册。');
      }
      if ($passwordError = $this->loginRegister->validatePassword($password)) {
        return $this->json(0, $passwordError);
      }
      $result = $this->loginRegister->createAccount($identifier, $password);
      if (!empty($result['error'])) {
        $this->flood->register('dx_auth.account_login_ip', 3600, $ip);
        return $this->json(0, $result['error']);
      }
      $account = $result['account'] ?? NULL;
      if (!$account instanceof UserInterface) {
        return $this->json(0, '自动注册失败，请稍后重试。');
      }
      $created = TRUE;
    }

    user_login_finalize($account);
    \Drupal::service('session')->save();

    if ($destination === '' || !str_starts_with($destination, '/') || str_starts_with($destination, '//') || str_contains($destination, '/user/login')) {
      $destination = '/';
    }
    if ($created) {
      $destination .= (str_contains($destination, '?') ? '&' : '?') . 'dx_new=1';
    }

    return $this->json(1, $created ? '未检测到账号，已为您自动注册并登录' : 'ok', [
      'uid' => (int) $account->id(),
      'redirect' => $destination,
      'created' => $created,
    ], $destination);
  }

  /**
   * Authenticates against Drupal's user.auth service.
   */
  protected function passwordsMatch(UserInterface $account, string $password): bool {
    if ($this->userAuth instanceof UserAuthenticationInterface) {
      return $this->userAuth->authenticateAccount($account, $password);
    }
    return (bool) $this->userAuth->authenticate($account->getAccountName(), $password);
  }

  /**
   * Validates X-CSRF-Token for anonymous and authenticated POSTs.
   */
  protected function csrfHeaderValid(Request $request): bool {
    $token = (string) $request->headers->get('X-CSRF-Token', '');
    if ($token === '') {
      return FALSE;
    }
    if ($this->csrfToken->validate($token, CsrfRequestHeaderAccessCheck::TOKEN_KEY)) {
      return TRUE;
    }
    return $this->csrfToken->validate($token, 'rest');
  }

  /**
   * Builds a Topstar-compatible JSON payload.
   */
  protected function json(int $code, string $msg, array $data = [], ?string $redirect = NULL): JsonResponse {
    $payload = [
      'code' => $code,
      'msg' => $msg,
      'data' => $data,
    ];
    if ($redirect !== NULL) {
      $payload['redirect'] = $redirect;
    }
    return new JsonResponse($payload);
  }

}
