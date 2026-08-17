<?php

declare(strict_types=1);

namespace Drupal\dx_auth\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\dx_auth\Service\SmsAuthService;
use Drupal\dx_auth\Service\WeChatAuthService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * WeChat OAuth and SMS login endpoints.
 */
class SocialAuthController extends ControllerBase {

  public function __construct(
    protected WeChatAuthService $wechat,
    protected SmsAuthService $sms,
    protected FloodInterface $flood,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('dx_auth.wechat'),
      $container->get('dx_auth.sms'),
      $container->get('flood'),
    );
  }

  /**
   * GET /dx/auth/wechat/start
   */
  public function wechatStart(Request $request): JsonResponse {
    if (!$this->wechat->isEnabled()) {
      return $this->json(0, '微信登录暂未开通');
    }
    $ip = $request->getClientIp() ?: '0.0.0.0';
    if (!$this->flood->isAllowed('dx_auth.wechat_start', 40, 3600, $ip)) {
      return $this->json(0, '尝试过多，请稍后再试');
    }
    $this->flood->register('dx_auth.wechat_start', 3600, $ip);

    $inWechat = (string) $request->query->get('in_wechat', '') === '1';
    $channel = $inWechat ? 'mp' : 'qr';
    $destination = $this->safeDestination((string) $request->query->get('destination', '/'));
    $redirectUri = $request->getSchemeAndHttpHost() . $request->getBasePath() . '/dx/auth/wechat/callback';

    $started = $this->wechat->start($redirectUri, $destination, $channel);
    return $this->json(1, 'ok', $started);
  }

  /**
   * GET /dx/auth/wechat/callback
   */
  public function wechatCallback(Request $request): RedirectResponse {
    $fail = '/user/login#qrcode';
    if (!$this->wechat->isEnabled()) {
      return new RedirectResponse($fail);
    }
    if ($request->query->get('error')) {
      return new RedirectResponse($fail);
    }
    $result = $this->wechat->complete(
      (string) $request->query->get('code', ''),
      (string) $request->query->get('state', ''),
    );
    if (empty($result['ok']) || empty($result['user'])) {
      return new RedirectResponse($fail);
    }
    user_login_finalize($result['user']);
    \Drupal::service('session')->save();
    $destination = $this->safeDestination((string) ($result['destination'] ?? '/'));
    return new RedirectResponse($destination);
  }

  /**
   * POST /dx/auth/sms/send
   */
  public function smsSend(Request $request): JsonResponse {
    $ip = $request->getClientIp() ?: '0.0.0.0';
    if (!$this->flood->isAllowed('dx_auth.sms_send_ip', 8, 3600, $ip)) {
      return $this->json(0, '发送次数过多，请稍后再试');
    }
    $mobile = (string) ($request->request->get('mobile') ?? '');
    $normalized = SmsAuthService::normalizeMobile($mobile);
    if ($normalized !== '' && !$this->flood->isAllowed('dx_auth.sms_send_mobile', 5, 3600, $normalized)) {
      return $this->json(0, '该号码发送次数过多，请稍后再试');
    }

    $result = $this->sms->send($mobile);
    if (empty($result['ok'])) {
      $messages = [
        'sms_unavailable' => '手机登录暂未开通',
        'invalid_mobile' => '请输入有效的大陆手机号码',
        'sms_rate_limited' => '验证码发送过于频繁，请稍后再试',
        'sms_send_failed' => '验证码发送失败，请稍后重试',
      ];
      $key = $result['msg'] ?? 'sms_send_failed';
      if ($key !== 'invalid_mobile' && $key !== 'sms_unavailable') {
        $this->flood->register('dx_auth.sms_send_ip', 3600, $ip);
      }
      return $this->json(0, $messages[$key] ?? $key, [
        'retry_after' => $result['retry_after'] ?? NULL,
      ]);
    }

    $this->flood->register('dx_auth.sms_send_ip', 3600, $ip);
    if ($normalized !== '') {
      $this->flood->register('dx_auth.sms_send_mobile', 3600, $normalized);
    }
    $data = [
      'ttl' => $result['ttl'] ?? SmsAuthService::CODE_TTL,
      'retry_after' => $result['retry_after'] ?? SmsAuthService::RESEND_SECONDS,
    ];
    if (!empty($result['debug_code'])) {
      $data['debug_code'] = $result['debug_code'];
    }
    return $this->json(1, '验证码已发送', $data);
  }

  /**
   * POST /dx/auth/sms/login
   */
  public function smsLogin(Request $request): JsonResponse {
    $ip = $request->getClientIp() ?: '0.0.0.0';
    $mobile = (string) ($request->request->get('mobile') ?? '');
    $code = (string) ($request->request->get('code') ?? '');
    $normalized = SmsAuthService::normalizeMobile($mobile) ?: 'unknown';
    if (!$this->flood->isAllowed('dx_auth.sms_login_ip', 20, 3600, $ip)
      || !$this->flood->isAllowed('dx_auth.sms_login_mobile', 8, 3600, $normalized)) {
      return $this->json(0, '尝试次数过多，请稍后再试');
    }

    $result = $this->sms->login($mobile, $code);
    if (empty($result['ok']) || empty($result['user'])) {
      $this->flood->register('dx_auth.sms_login_ip', 3600, $ip);
      $this->flood->register('dx_auth.sms_login_mobile', 3600, $normalized);
      $messages = [
        'sms_unavailable' => '手机登录暂未开通',
        'invalid_code' => '验证码不正确',
        'code_expired' => '验证码已过期，请重新获取',
        'code_locked' => '验证码错误次数过多，请重新获取',
        'account_unavailable' => '账号不可用',
      ];
      $key = $result['msg'] ?? 'invalid_code';
      return $this->json(0, $messages[$key] ?? $key);
    }

    user_login_finalize($result['user']);
    \Drupal::service('session')->save();
    $destination = $this->safeDestination((string) ($request->request->get('destination') ?? '/'));
    return $this->json(1, 'ok', [
      'uid' => (int) $result['user']->id(),
      'redirect' => $destination,
    ], $destination);
  }

  /**
   * Restricts post-login redirects to same-site relative paths.
   */
  protected function safeDestination(string $destination): string {
    if ($destination === '' || !str_starts_with($destination, '/') || str_starts_with($destination, '//') || str_contains($destination, '/user/login')) {
      return '/';
    }
    return $destination;
  }

  /**
   * @param array<string, mixed> $data
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
