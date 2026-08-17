<?php

declare(strict_types=1);

namespace Drupal\dx_auth\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;

/**
 * WeChat Official Account access token + QR + OAuth (Topstar HTTP flow).
 */
class WechatAuthService {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected ClientInterface $httpClient,
    protected StateInterface $state,
    protected LoggerChannelInterface $logger,
  ) {}

  /**
   * Whether WeChat login is configured and enabled.
   */
  public function isEnabled(): bool {
    $c = $this->configFactory->get('dx_auth.settings');
    return (bool) $c->get('wechat_enabled')
      && trim((string) $c->get('wechat_app_id')) !== ''
      && trim((string) $c->get('wechat_secret')) !== '';
  }

  /**
   * Creates a temporary login QR (QR_STR_SCENE).
   *
   * @return array{scene_id: string, url: string}|null
   */
  public function createLoginQr(string $sceneId): ?array {
    $token = $this->getAccessToken();
    if ($token === '') {
      return NULL;
    }
    try {
      $response = $this->httpClient->request('POST', 'https://api.weixin.qq.com/cgi-bin/qrcode/create?access_token=' . urlencode($token), [
        'json' => [
          'expire_seconds' => 7200,
          'action_name' => 'QR_STR_SCENE',
          'action_info' => ['scene' => ['scene_str' => $sceneId]],
        ],
        'http_errors' => FALSE,
        'timeout' => 20,
      ]);
      $data = json_decode((string) $response->getBody(), TRUE) ?: [];
      if (empty($data['ticket'])) {
        $this->logger->error('WeChat QR create failed: @b', ['@b' => (string) $response->getBody()]);
        return NULL;
      }
      return [
        'scene_id' => $sceneId,
        'url' => 'https://mp.weixin.qq.com/cgi-bin/showqrcode?ticket=' . urlencode((string) $data['ticket']),
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error('WeChat QR exception: @m', ['@m' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Builds MP OAuth authorize URL (snsapi_userinfo).
   */
  public function buildOauthAuthorizeUrl(string $redirectUri, string $state): string {
    $appId = trim((string) $this->configFactory->get('dx_auth.settings')->get('wechat_app_id'));
    $params = [
      'appid' => $appId,
      'redirect_uri' => $redirectUri,
      'response_type' => 'code',
      'scope' => 'snsapi_userinfo',
      'state' => $state,
    ];
    return 'https://open.weixin.qq.com/connect/oauth2/authorize?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986) . '#wechat_redirect';
  }

  /**
   * Exchanges OAuth code for openid.
   */
  public function openidFromCode(string $code): string {
    $c = $this->configFactory->get('dx_auth.settings');
    $appId = trim((string) $c->get('wechat_app_id'));
    $secret = trim((string) $c->get('wechat_secret'));
    $response = $this->httpClient->request('GET', 'https://api.weixin.qq.com/sns/oauth2/access_token', [
      'query' => [
        'appid' => $appId,
        'secret' => $secret,
        'code' => $code,
        'grant_type' => 'authorization_code',
      ],
      'http_errors' => FALSE,
      'timeout' => 20,
    ]);
    $data = json_decode((string) $response->getBody(), TRUE) ?: [];
    return (string) ($data['openid'] ?? '');
  }

  /**
   * Validates MP server signature.
   */
  public function checkSignature(string $signature, string $timestamp, string $nonce): bool {
    $token = (string) $this->configFactory->get('dx_auth.settings')->get('wechat_token');
    $tmp = [$token, $timestamp, $nonce];
    sort($tmp, SORT_STRING);
    return hash_equals(sha1(implode($tmp)), $signature);
  }

  /**
   * Cached client-credential access token.
   */
  public function getAccessToken(): string {
    $cached = $this->state->get('dx_auth.wechat_access_token');
    if (is_array($cached) && !empty($cached['token']) && ($cached['expire'] ?? 0) > time() + 60) {
      return (string) $cached['token'];
    }
    $c = $this->configFactory->get('dx_auth.settings');
    $appId = trim((string) $c->get('wechat_app_id'));
    $secret = trim((string) $c->get('wechat_secret'));
    if ($appId === '' || $secret === '') {
      return '';
    }
    try {
      $response = $this->httpClient->request('GET', 'https://api.weixin.qq.com/cgi-bin/token', [
        'query' => [
          'grant_type' => 'client_credential',
          'appid' => $appId,
          'secret' => $secret,
        ],
        'http_errors' => FALSE,
        'timeout' => 20,
      ]);
      $data = json_decode((string) $response->getBody(), TRUE) ?: [];
      if (empty($data['access_token'])) {
        $this->logger->error('WeChat token failed: @b', ['@b' => (string) $response->getBody()]);
        return '';
      }
      $this->state->set('dx_auth.wechat_access_token', [
        'token' => $data['access_token'],
        'expire' => time() + (int) ($data['expires_in'] ?? 7200),
      ]);
      return (string) $data['access_token'];
    }
    catch (\Throwable $e) {
      $this->logger->error('WeChat token exception: @m', ['@m' => $e->getMessage()]);
      return '';
    }
  }

}
