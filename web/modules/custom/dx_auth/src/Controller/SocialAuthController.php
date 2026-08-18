<?php

declare(strict_types=1);

namespace Drupal\dx_auth\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\TrustedRedirectResponse;
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
    $mode = (string) $request->query->get('mode', '');
    $sceneId = uniqid($mode === 'bind' ? 'dxb' : 'dx', TRUE);
    $store = $this->tempStoreFactory->get('dx_auth_wechat');
    $row = ['redirect_url' => $redirect, 'status' => 0];
    if ($mode === 'bind') {
      $uid = (int) $this->currentUser()->id();
      if ($uid < 1) {
        return new JsonResponse(['code' => 0, 'msg' => '请先登录']);
      }
      $row['mode'] = 'bind';
      $row['bind_uid'] = $uid;
    }
    $store->set($sceneId, $row);
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
    if (!empty($row['status']) && (int) $row['status'] === 2) {
      return new JsonResponse(['code' => 2, 'msg' => $this->linker->messageFor((string) ($row['bind_error'] ?? 'failed'))]);
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
    if (!empty($row['mode']) && $row['mode'] === 'bind') {
      $store->delete($sceneId);
      return $this->forceRedirect('/dx/auth/bindings');
    }
    $user = $this->entityTypeManager()->getStorage('user')->load((int) $row['uid']);
    if ($user) {
      user_login_finalize($user);
      \Drupal::service('session')->save();
    }
    $redirect = $this->normalizePath((string) ($row['redirect_url'] ?? '/'));
    $redirect = $this->withNewFlag($redirect, !empty($row['created']));
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
        $store = $this->tempStoreFactory->get('dx_auth_wechat');
        $existing = $store->get($sceneId);
        if (!is_array($existing)) {
          $existing = [];
        }
        if (!empty($existing['mode']) && $existing['mode'] === 'bind' && !empty($existing['bind_uid'])) {
          $link = $this->linker->linkOpenidToUser((int) $existing['bind_uid'], $openid);
          $existing['status'] = !empty($link['ok']) ? 1 : 2;
          $existing['uid'] = (int) $existing['bind_uid'];
          $existing['openid'] = $openid;
          $existing['bind_error'] = (string) ($link['msg'] ?? '');
          $store->set($sceneId, $existing);
        }
        else {
          $login = $this->linker->loginOrCreateByWechat($openid);
          $existing['status'] = 1;
          $existing['uid'] = (int) $login['user']->id();
          $existing['openid'] = $openid;
          $existing['created'] = !empty($login['created']);
          $store->set($sceneId, $existing);
        }
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
    if (!empty($login['conflict'])) {
      $this->messenger()->addError($this->linker->messageFor((string) ($login['msg'] ?? 'conflict')));
      return $this->forceRedirect('/dx/auth/bindings');
    }
    if ((int) $this->currentUser()->id() !== (int) $login['user']->id()) {
      user_login_finalize($login['user']);
      \Drupal::service('session')->save();
    }
    elseif (!empty($login['bound'])) {
      $this->messenger()->addStatus($this->t('已将微信绑定到当前账号。'));
    }

    $state = (string) $request->query->get('state', '');
    if ($state !== '') {
      $decoded = json_decode((string) base64_decode($state, TRUE), TRUE) ?: [];
      if (!empty($decoded['destination'])) {
        $destination = $this->normalizePath((string) $decoded['destination']);
      }
    }
    \Drupal::service('session')->remove('dx_wechat_oauth_destination');
    return $this->forceRedirect($this->withNewFlag($destination, !empty($login['created'])));
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
    if (!empty($login['conflict'])) {
      return new JsonResponse(['code' => 0, 'msg' => $this->linker->messageFor((string) ($login['msg'] ?? 'mobile_conflict'))]);
    }
    if ((int) $this->currentUser()->id() !== (int) $login['user']->id()) {
      user_login_finalize($login['user']);
      \Drupal::service('session')->save();
    }
    $created = !empty($login['created']);
    $destination = $this->withNewFlag($destination, $created);
    return new JsonResponse([
      'code' => 1,
      'msg' => $created ? '未检测到账号，已为您自动注册并登录' : 'ok',
      'redirect' => $destination,
      'data' => [
        'uid' => (int) $login['user']->id(),
        'redirect' => $destination,
        'created' => $created,
        'bound' => !empty($login['bound']),
      ],
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
    if (!empty($login['conflict'])) {
      $this->messenger()->addError($this->linker->messageFor((string) ($login['msg'] ?? 'conflict')));
      return $this->forceRedirect('/dx/auth/bindings');
    }
    if ((int) $this->currentUser()->id() !== (int) $login['user']->id()) {
      user_login_finalize($login['user']);
      \Drupal::service('session')->save();
    }
    elseif (!empty($login['bound'])) {
      $this->messenger()->addStatus($this->t('已将 Google 绑定到当前账号。'));
    }
    \Drupal::service('session')->remove('dx_google_oauth_destination');
    \Drupal::service('session')->remove('dx_google_oauth_state');
    return $this->forceRedirect($this->withNewFlag($destination, !empty($login['created'])));
  }

  /**
   * Appends first-login notice query when an account was just created.
   */
  protected function withNewFlag(string $url, bool $created): string {
    $url = $this->normalizePath($url);
    if (!$created) {
      return $url;
    }
    return $url . (str_contains($url, '?') ? '&' : '?') . 'dx_new=1';
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
