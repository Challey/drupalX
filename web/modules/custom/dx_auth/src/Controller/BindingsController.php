<?php

declare(strict_types=1);

namespace Drupal\dx_auth\Controller;

use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\dx_auth\Service\EnterpriseAccountLinker;
use Drupal\dx_auth\Service\GoogleAuthService;
use Drupal\dx_auth\Service\SmsAuthService;
use Drupal\dx_auth\Service\SocialAccountLinker;
use Drupal\dx_auth\Service\WechatAuthService;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Authenticated unified-login bind page and JSON actions.
 */
class BindingsController extends ControllerBase {

  public function __construct(
    protected SocialAccountLinker $social,
    protected EnterpriseAccountLinker $enterprise,
    protected GoogleAuthService $google,
    protected SmsAuthService $sms,
    protected WechatAuthService $wechat,
    protected CsrfTokenGenerator $csrfToken,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('dx_auth.social_linker'),
      $container->get('dx_auth.account_linker'),
      $container->get('dx_auth.google'),
      $container->get('dx_auth.sms'),
      $container->get('dx_auth.wechat'),
      $container->get('csrf_token'),
    );
  }

  /**
   * GET /dx/auth/bindings
   */
  public function page(): array {
    $account = $this->currentAccount();
    $googleOn = $this->google->isAvailable(\Drupal::request());
    $build = $this->themeBuild($account, $googleOn);
    $build['#attached']['library'][] = 'dx_auth/bindings';
    $build['#attached']['drupalSettings']['dxAuth'] = [
      'csrfToken' => $this->csrfToken->get(CsrfRequestHeaderAccessCheck::TOKEN_KEY),
      'smsEnabled' => $this->sms->isEnabled(),
      'wechatEnabled' => $this->wechat->isEnabled(),
      'smsSendPath' => '/dx/auth/sms_send',
      'bindMobilePath' => '/dx/auth/bind_mobile',
      'claimAccountPath' => '/dx/auth/claim_account',
      'wechatQrPath' => '/dx/auth/wechat_qrcode',
      'wechatPollPath' => '/dx/auth/wechat_poll',
    ];
    $build['#cache'] = [
      'max-age' => 0,
      'contexts' => ['user', 'url', 'ip'],
    ];
    return $build;
  }

  /**
   * GET /dx/auth/bindings/status
   */
  public function status(): JsonResponse {
    $account = $this->currentAccount();
    if (!$account) {
      return new JsonResponse(['code' => 0, 'msg' => '请先登录']);
    }
    return new JsonResponse(['code' => 1, 'data' => $this->statusPayload($account)]);
  }

  /**
   * POST /dx/auth/bind_mobile
   */
  public function bindMobile(Request $request): JsonResponse {
    $account = $this->currentAccount();
    if (!$account) {
      return new JsonResponse(['code' => 0, 'msg' => '请先登录']);
    }
    $mobile = (string) ($request->request->get('mobile') ?? '');
    $code = (string) ($request->request->get('code') ?? '');
    if (!$this->social->verifySmsCode($mobile, $code)) {
      return new JsonResponse(['code' => 0, 'msg' => '验证码错误或已过期']);
    }
    $result = $this->social->bindMobileToUser($account, $mobile);
    $fresh = $this->currentAccount();
    return new JsonResponse([
      'code' => !empty($result['ok']) ? 1 : 0,
      'msg' => $this->social->messageFor((string) ($result['msg'] ?? '')),
      'data' => $fresh ? $this->statusPayload($fresh) : [],
    ]);
  }

  /**
   * POST /dx/auth/claim_account
   */
  public function claimAccount(Request $request): JsonResponse {
    $account = $this->currentAccount();
    if (!$account) {
      return new JsonResponse(['code' => 0, 'msg' => '请先登录']);
    }
    $login = (string) ($request->request->get('login') ?? '');
    $password = (string) ($request->request->get('password') ?? '');
    $result = $this->social->claimAccountByPassword($account, $login, $password);
    $fresh = $this->currentAccount();
    return new JsonResponse([
      'code' => !empty($result['ok']) ? 1 : 0,
      'msg' => $this->social->messageFor((string) ($result['msg'] ?? '')),
      'data' => $fresh ? $this->statusPayload($fresh) : [],
    ]);
  }

  /**
   * Theme array for the bind page.
   */
  protected function themeBuild(?UserInterface $account, bool $googleOn): array {
    $uid = $account ? (int) $account->id() : 0;
    $status = $this->social->statusForUid($uid);
    return [
      '#theme' => 'dx_auth_bindings',
      '#account_name' => $account ? $account->getAccountName() : '',
      '#account_mail' => $account ? (string) $account->getEmail() : '',
      '#enterprise' => $this->enterprise->creditCodesForUid($uid),
      '#wechat' => $status['wechat'],
      '#mobile' => $status['mobile'],
      '#mobile_masked' => $status['mobile_masked'],
      '#google' => $status['google'],
      '#google_email' => $status['google_email'],
      '#google_available' => $googleOn,
      '#sms_enabled' => $this->sms->isEnabled(),
      '#wechat_enabled' => $this->wechat->isEnabled(),
      '#wechat_jump' => Url::fromRoute('dx_auth.wechat_jump', [], [
        'query' => ['return_to' => '/dx/auth/bindings'],
      ])->toString(),
      '#google_jump' => $googleOn ? Url::fromRoute('dx_auth.google_jump', [], [
        'query' => ['return_to' => '/dx/auth/bindings'],
      ])->toString() : '',
    ];
  }

  /**
   * JSON status for the bind UI.
   */
  protected function statusPayload(UserInterface $account): array {
    $uid = (int) $account->id();
    $s = $this->social->statusForUid($uid);
    return [
      'account' => [
        'name' => $account->getAccountName(),
        'mail' => (string) $account->getEmail(),
      ],
      'enterprise' => $this->enterprise->creditCodesForUid($uid),
      'mobile' => ['bound' => $s['mobile'], 'value' => $s['mobile_masked']],
      'wechat' => ['bound' => $s['wechat']],
      'google' => ['bound' => $s['google'], 'email' => $s['google_email']],
    ];
  }

  /**
   * Current user entity or NULL.
   */
  protected function currentAccount(): ?UserInterface {
    $account = $this->entityTypeManager()->getStorage('user')->load($this->currentUser()->id());
    return $account instanceof UserInterface ? $account : NULL;
  }

}
