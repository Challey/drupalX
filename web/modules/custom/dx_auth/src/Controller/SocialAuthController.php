<?php

declare(strict_types=1);

namespace Drupal\dx_auth\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\TempStore\SharedTempStoreFactory;
use Drupal\Core\Url;
use Drupal\dx_auth\Service\GoogleAuthService;
use Drupal\dx_auth\Service\SmsAuthService;
use Drupal\dx_auth\Service\SocialAccountLinker;
use Drupal\dx_auth\Service\WechatAuthService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\TrustedRedirectResponse;

/**
 * WeChat / SMS / Google login endpoints (Topstar wechatquery + aliyunsms port).
 */
class SocialAuthController extends ControllerBase {

  public function __construct(
    protected WechatAuthService $wechat,
    protected SmsAuthService $sms,
    protected GoogleAuthService $google,
    protected SocialAccountLinker $linker,
    protected SharedTempStoreFactory $tempStoreFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('dx_auth.wechat'),
      $container->get('dx_auth.sms'),
      $container->get('dx_auth.google'),
      $container->get('dx_auth.social_linker'),
      $container->get('tempstore.shared'),
    );
  }

  /**
   * GET /dx/auth/wechat_qrcode
   */
  public function wechatQrcode(Request $request): JsonResponse {
    if (!$this->wechat->isEnabled()) {
      return new JsonResponse(['code' => 0, 'msg' => '微信登录未开通']);
    }
    $redirect = (string) $request->query->get('redirect_url', '/');
    $sceneId = uniqid('dx', TRUE);
    $store = $this->tempStoreFactory->get('dx_auth_wechat');
    $store->set($sceneId, ['redirect_url' => $redirect, 'status' => 0]);
    $qr = $this->wechat->createLoginQr($sceneId);
    if (!$qr) {
      return new JsonResponse(['code' => 0, 'msg' => '二维码生成失败']);
    }
    return new JsonResponse(['code' => 1, 'data' => $qr]);
  }

  /**
   * GET /dx/auth/wechat_poll?scene_id=
   */
  public function wechatPoll(Request $request): JsonResponse {
    $sceneId = (string) $request->query->get('scene_id', '');
    if ($sceneId === '') {
      return new JsonResponse(['code' => 0, 'msg' => '参数错误']);
    }
    $row = $this->tempStoreFactory->get('dx_auth_wechat')->get($sceneId);
    if (!empty($row['status']) && (int) $row['status'] === 1) {
      return new JsonResponse(['code' => 1, 'msg' => 'success']);
    }
    return new JsonResponse(['code' => 0, 'msg' => 'pending']);
  }

  /**
   * GET /dx/auth/wechat_middle?scene_id=
   */
  public function wechatMiddle(Request $request): Response {
    $sceneId = (string) $request->query->get('scene_id', '');
    $store = $this->tempStoreFactory->get('dx_auth_wechat');
    $row = $store->get($sceneId);
    if (empty($row['status']) || (int) $row['status'] !== 1 || empty($row['uid'])) {
      return new Response(
        '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>登录确认中...</title>' .
        '<script>setTimeout(function(){location.reload();},2000);</script></head>' .
        '<body>请稍后，正在确认登录状态...</body></html>'
      );
    }
    $user = $this->entityTypeManager()->getStorage('user')->load((int) $row['uid']);
    if ($user) {
      user_login_finalize($user);
      \Drupal::service('session')->save();
    }
    $redirect = $this->normalizePath((string) ($row['redirect_url'] ?? '/'));
    $store->delete($sceneId);
    return $this->forceRedirect($redirect);
  }

  /**
   * GET/POST /dx/auth/wechat_callback — MP server URL.
   */
  public function wechatCallback(Request $request): Response {
    $config = $this->config('dx_auth.settings');
    if (!empty($config->get('wechat_switch'))) {
      return new Response((string) $request->query->get('echostr', ''));
    }

    $signature = (string) $request->query->get('signature', '');
    $timestamp = (string) $request->query->get('timestamp', '');
    $nonce = (string) $request->query->get('nonce', '');
    if ($signature !== '' && !$this->wechat->checkSignature($signature, $timestamp, $nonce)) {
      return new Response('invalid signature', 403);
    }

    $raw = (string) $request->getContent();
    if ($raw === '') {
      return new Response('success');
    }

    $xml = @simplexml_load_string($raw, \SimpleXMLElement::class, LIBXML_NOCDATA);
    if (!$xml) {
      return new Response('success');
    }
    $msgType = strtolower((string) ($xml->MsgType ?? ''));
    $event = strtoupper((string) ($xml->Event ?? ''));
    $openid = (string) ($xml->FromUserName ?? '');
    $eventKey = (string) ($xml->EventKey ?? '');

    if ($msgType === 'event' && ($event === 'SCAN' || $event === 'SUBSCRIBE') && $openid !== '') {
      $sceneId = str_replace('qrscene_', '', $eventKey);
      if ($sceneId !== '') {
        $login = $this->linker->loginOrCreateByWechat($openid);
        $store = $this->tempStoreFactory->get('dx_auth_wechat');
        $existing = $store->get($sceneId);
        if (!is_array($existing)) {
          $existing = [];
        }
        $existing['status'] = 1;
        $existing['uid'] = (int) $login['user']->id();
        $existing['openid'] = $openid;
        $store->set($sceneId, $existing);
      }
    }

    return new Response('success');
  }

  /**
   * GET /dx/auth/wechat_jump — in-WeChat OAuth.
   */
  public function wechatJump(Request $request): Response {
    if (!$this->wechat->isEnabled()) {
      $this->messenger()->addError($this->t('WeChat login is not configured.'));
      return $this->forceRedirect('/user/login');
    }

    $destination = $this->normalizePath((string) (
      $request->query->get('return_to')
      ?: $request->query->get('destination')
      ?: (\Drupal::service('session')->get('dx_wechat_oauth_destination') ?: '/')
    ));

    if ($this->currentUser()->isAuthenticated()) {
      return $this->forceRedirect($destination);
    }

    $code = (string) $request->query->get('code', '');
    if ($code === '') {
      \Drupal::service('session')->set('dx_wechat_oauth_destination', $destination);
      $state = base64_encode(json_encode(['destination' => $destination]));
      $callback = Url::fromRoute('dx_auth.wechat_jump', [], ['absolute' => TRUE])->toString();
      $url = $this->wechat->buildOauthAuthorizeUrl($callback, $state);
      return new TrustedRedirectResponse($url);
    }

    $openid = $this->wechat->openidFromCode($code);
    if ($openid === '') {
      return $this->forceRedirect('/user/login');
    }
    $login = $this->linker->loginOrCreateByWechat($openid);
    user_login_finalize($login['user']);
    \Drupal::service('session')->save();

    $state = (string) $request->query->get('state', '');
    if ($state !== '') {
      $decoded = json_decode((string) base64_decode($state, TRUE), TRUE) ?: [];
      if (!empty($decoded['destination'])) {
        $destination = $this->normalizePath((string) $decoded['destination']);
      }
    }
    \Drupal::service('session')->remove('dx_wechat_oauth_destination');
    return $this->forceRedirect($destination);
  }

  /**
   * GET /dx/auth/sms_send?mobile=
   */
  public function smsSend(Request $request): JsonResponse {
    $mobile = (string) $request->query->get('mobile', '');
    $ip = $request->getClientIp() ?: '0.0.0.0';
    $result = $this->sms->sendCode($mobile, $ip);
    if ($result === TRUE) {
      return new JsonResponse(['code' => 1, 'msg' => 'send success']);
    }
    $messages = [
      'sms_disabled' => '短信登录未开通',
      'invalid_mobile' => '手机号格式不正确',
      'flood' => '发送过于频繁，请稍后再试',
    ];
    return new JsonResponse(['code' => 0, 'msg' => $messages[$result] ?? (string) $result]);
  }

  /**
   * POST /dx/auth/sms_login
   */
  public function smsLogin(Request $request): JsonResponse {
    $mobile = (string) ($request->request->get('mobile') ?? $request->query->get('mobile', ''));
    $code = (string) ($request->request->get('code') ?? $request->query->get('code', ''));
    $destination = $this->normalizePath((string) ($request->request->get('destination') ?? $request->query->get('destination', '/')));

    if (!$this->sms->isEnabled()) {
      return new JsonResponse(['code' => 0, 'msg' => '短信登录未开通']);
    }
    if (!$this->linker->verifySmsCode($mobile, $code)) {
      return new JsonResponse(['code' => 0, 'msg' => '验证码错误或已过期']);
    }
    $login = $this->linker->loginOrCreateByMobile($mobile);
    user_login_finalize($login['user']);
    \Drupal::service('session')->save();
    return new JsonResponse([
      'code' => 1,
      'msg' => 'ok',
      'redirect' => $destination,
      'data' => ['uid' => (int) $login['user']->id(), 'redirect' => $destination],
    ]);
  }

  /**
   * GET /dx/auth/google_jump
   */
  public function googleJump(Request $request): Response {
    if (!$this->google->isAvailable($request)) {
      $this->messenger()->addError($this->t('Google sign-in is not available.'));
      return $this->forceRedirect('/user/login');
    }

    $destination = $this->normalizePath((string) (
      $request->query->get('return_to')
      ?: $request->query->get('destination')
      ?: (\Drupal::service('session')->get('dx_google_oauth_destination') ?: '/')
    ));

    if ($this->currentUser()->isAuthenticated() && empty($request->query->get('code'))) {
      return $this->forceRedirect($destination);
    }

    $code = (string) $request->query->get('code', '');
    if ($code === '') {
      \Drupal::service('session')->set('dx_google_oauth_destination', $destination);
      $state = base64_encode(json_encode([
        'destination' => $destination,
        'nonce' => bin2hex(random_bytes(8)),
      ], JSON_UNESCAPED_SLASHES));
      \Drupal::service('session')->set('dx_google_oauth_state', $state);
      $url = $this->google->buildAuthorizeUrl($this->google->redirectUri($request), $state);
      return new TrustedRedirectResponse($url);
    }

    $state = (string) $request->query->get('state', '');
    $saved = (string) \Drupal::service('session')->get('dx_google_oauth_state', '');
    if ($state === '' || $saved === '' || !hash_equals($saved, $state)) {
      return $this->forceRedirect('/user/login');
    }
    $stateData = json_decode((string) base64_decode($state, TRUE), TRUE) ?: [];
    if (!empty($stateData['destination'])) {
      $destination = $this->normalizePath((string) $stateData['destination']);
    }

    try {
      $profile = $this->google->profileFromCode($code, $request);
    }
    catch (\Throwable $e) {
      $this->getLogger('dx_auth')->error('Google OAuth failed: @m', ['@m' => $e->getMessage()]);
      $this->messenger()->addError($this->t('Google sign-in failed.'));
      return $this->forceRedirect('/user/login');
    }

    if ($profile['sub'] === '' || $profile['email'] === '' || !$profile['email_verified']) {
      $this->messenger()->addError($this->t('Google account email is missing or not verified.'));
      return $this->forceRedirect('/user/login');
    }

    $login = $this->linker->loginOrCreateByGoogle($profile['sub'], $profile['email'], $profile['name']);
    user_login_finalize($login['user']);
    \Drupal::service('session')->save();
    \Drupal::service('session')->remove('dx_google_oauth_destination');
    \Drupal::service('session')->remove('dx_google_oauth_state');
    return $this->forceRedirect($destination);
  }

  /**
   * Same-site relative path only.
   */
  protected function normalizePath(string $url): string {
    if ($url === '') {
      return '/';
    }
    if (filter_var($url, FILTER_VALIDATE_URL)) {
      $parts = parse_url($url);
      $url = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }
    if (!str_starts_with($url, '/') || str_starts_with($url, '//') || str_contains($url, '/user/login')) {
      return '/';
    }
    return $url;
  }

  /**
   * HTML meta redirect (Topstar forceRedirect).
   */
  protected function forceRedirect(string $url): Response {
    $url = $this->normalizePath($url);
    $safe = htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $js = json_encode($url, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    return new Response(
      '<!DOCTYPE html><html><head><meta charset="UTF-8">' .
      '<meta http-equiv="refresh" content="0;url=' . $safe . '">' .
      '<script>location.replace(' . $js . ');</script></head><body></body></html>'
    );
  }

}
