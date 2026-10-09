<?php

declare(strict_types=1);

namespace Drupal\dx_auth\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;

/**
 * 微信网站应用 OAuth 与公众号 OAuth、接口凭据及临时二维码。
 */
class WechatAuthService {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected ClientInterface $httpClient,
    protected StateInterface $state,
    protected LoggerChannelInterface $logger,
  ) {}

  /**
   * 返回应用类型；仅缺省配置兼容公众号，未知值交由入口拒绝。
   */
  public function appType(): string {
    return (string) ($this->configFactory->get('dx_auth.settings')->get('wechat_app_type') ?? 'mp');
  }

  /**
   * 判断是否为公众号，不代表该账号已取得微信接口权限。
   */
  public function isOfficialAccount(): bool {
    return $this->appType() === 'mp';
  }

  /**
   * 检查类型、开关和凭据；未知类型不得静默回退到公众号。
   */
  public function isEnabled(): bool {
    $c = $this->configFactory->get('dx_auth.settings');
    return in_array($this->appType(), ['mp', 'open_platform'], TRUE)
      && (bool) $c->get('wechat_enabled')
      && trim((string) $c->get('wechat_app_id')) !== ''
      && trim((string) $c->get('wechat_secret')) !== '';
  }

  /**
   * 检查服务号MP OAuth是否启用（独立凭据或旧主mp模式）。
   */
  public function mpOauthEnabled(): bool {
    $c = $this->configFactory->get('dx_auth.settings');
    // 优先独立MP凭据
    if ((bool) $c->get('wechat_mp_enabled')
      && trim((string) $c->get('wechat_mp_app_id')) !== ''
      && trim((string) $c->get('wechat_mp_secret')) !== '') {
      return TRUE;
    }
    // 兼容旧主mp模式
    return $this->isOfficialAccount() && $this->isEnabled();
  }

  /**
   * 返回指定通道的AppID。
   */
  public function oauthAppId(string $channel = 'website'): string {
    $c = $this->configFactory->get('dx_auth.settings');
    if ($channel === 'mp') {
      $mpId = trim((string) $c->get('wechat_mp_app_id'));
      return $mpId !== '' ? $mpId : trim((string) $c->get('wechat_app_id'));
    }
    return trim((string) $c->get('wechat_app_id'));
  }

  /**
   * 检查指定通道是否启用。
   */
  public function channelEnabled(string $channel): bool {
    if ($channel === 'mp') {
      return $this->mpOauthEnabled();
    }
    return $this->isEnabled();
  }

  /**
   * 成对选择凭据；独立 MP 仅用于 H5 OAuth，不影响主公众号接口或回调。
   *
   * @return array{appid: string, secret: string}|array{}
   */
  private function oauthCredentials(string $channel): array {
    $c = $this->configFactory->get('dx_auth.settings');
    if ($channel === 'mp') {
      $appId = trim((string) $c->get('wechat_mp_app_id'));
      $secret = trim((string) $c->get('wechat_mp_secret'));
      if ((bool) $c->get('wechat_mp_enabled') && $appId !== '' && $secret !== '') {
        return ['appid' => $appId, 'secret' => $secret];
      }
      if (!$this->isOfficialAccount()) {
        return [];
      }
    }
    elseif ($channel !== 'website') {
      return [];
    }
    if (!$this->isEnabled()) {
      return [];
    }
    return [
      'appid' => trim((string) $c->get('wechat_app_id')),
      'secret' => trim((string) $c->get('wechat_secret')),
    ];
  }

  /**
   * 仅为已启用的公众号创建临时登录二维码（QR_STR_SCENE）。
   *
   * @return array{scene_id: string, url: string}|null
   */
  public function createLoginQr(string $sceneId): ?array {
    if (!$this->isOfficialAccount() || !$this->isEnabled()) {
      return NULL;
    }
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
        'allow_redirects' => FALSE,
        'timeout' => 20,
      ]);
      $data = json_decode((string) $response->getBody(), TRUE);
      $data = is_array($data) ? $data : [];
      if ($response->getStatusCode() !== 200 || !empty($data['errcode']) || !is_string($data['ticket'] ?? NULL) || trim($data['ticket']) === '') {
        $this->logApiError('微信二维码创建失败：errcode=@errcode。', $data);
        return NULL;
      }
      return [
        'scene_id' => $sceneId,
        'url' => 'https://mp.weixin.qq.com/cgi-bin/showqrcode?ticket=' . urlencode((string) $data['ticket']),
      ];
    }
    catch (\Throwable) {
      $this->logApiError('微信二维码请求失败：errcode=@errcode。');
      return NULL;
    }
  }

  /**
   * 网站应用使用扫码授权；公众号保留既有 snsapi_userinfo 权限范围。
   */
  public function buildOauthAuthorizeUrl(string $redirectUri, string $state, string $channel = 'website'): string {
    $credentials = $this->oauthCredentials($channel);
    if ($credentials === [] || trim($redirectUri) === '' || trim($state) === '') {
      return '';
    }
    $officialAccount = $channel === 'mp' || $this->isOfficialAccount();
    $params = [
      'appid' => $credentials['appid'],
      'redirect_uri' => $redirectUri,
      'response_type' => 'code',
      'scope' => $officialAccount ? 'snsapi_userinfo' : 'snsapi_login',
      'state' => $state,
    ];
    $endpoint = $officialAccount ? 'oauth2/authorize' : 'qrconnect';
    return 'https://open.weixin.qq.com/connect/' . $endpoint . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986) . '#wechat_redirect';
  }

  /**
   * 使用当前应用凭据交换 openid，不迁移或合并其他 AppID 的身份。
   */
  public function openidFromCode(string $code, string $channel = 'website'): string {
    return $this->identityFromCode($code, $channel)['openid'] ?? '';
  }

  /**
   * 仅从成功的 code 响应读取身份，不请求 userinfo 或公众号接口 token。
   *
   * UnionID 关联要求应用绑定同一微信开放平台；缺失时不推测或补造。
   *
   * @return array{appid: string, openid: string, unionid: string}|array{}
   */
  public function identityFromCode(string $code, string $channel = 'website'): array {
    $credentials = $this->oauthCredentials($channel);
    if ($credentials === [] || trim($code) === '') {
      return [];
    }
    try {
      $response = $this->httpClient->request('GET', 'https://api.weixin.qq.com/sns/oauth2/access_token', [
        'query' => [
          'appid' => $credentials['appid'],
          'secret' => $credentials['secret'],
          'code' => $code,
          'grant_type' => 'authorization_code',
        ],
        'http_errors' => FALSE,
        'allow_redirects' => FALSE,
        'timeout' => 20,
      ]);
      $data = json_decode((string) $response->getBody(), TRUE);
      $data = is_array($data) ? $data : [];
      $hasError = array_key_exists('errcode', $data) && !in_array($data['errcode'], [0, '0'], TRUE);
      if ($response->getStatusCode() !== 200 || $hasError || !is_string($data['openid'] ?? NULL) || trim($data['openid']) === '') {
        $this->logApiError('微信 OAuth code 交换失败：errcode=@errcode。', $data);
        return [];
      }
      return [
        'appid' => $credentials['appid'],
        'openid' => $data['openid'],
        'unionid' => is_string($data['unionid'] ?? NULL) && trim($data['unionid']) !== '' ? $data['unionid'] : '',
      ];
    }
    catch (\Throwable) {
      // 异常消息可能带有包含 AppSecret 和 code 的完整请求地址。
      $this->logApiError('微信 OAuth code 请求失败：errcode=@errcode。');
      return [];
    }
  }

  /**
   * 校验公众号服务器签名，空 Token 或空参数一律拒绝。
   */
  public function checkSignature(string $signature, string $timestamp, string $nonce): bool {
    if (!$this->isOfficialAccount()) {
      return FALSE;
    }
    $token = (string) $this->configFactory->get('dx_auth.settings')->get('wechat_token');
    if (trim($token) === '' || trim($signature) === '' || trim($timestamp) === '' || trim($nonce) === '') {
      return FALSE;
    }
    $tmp = [$token, $timestamp, $nonce];
    sort($tmp, SORT_STRING);
    return hash_equals(sha1(implode($tmp)), $signature);
  }

  /**
   * 仅公众号使用接口 access_token；缓存按 AppID 和密钥指纹隔离。
   */
  public function getAccessToken(): string {
    if (!$this->isOfficialAccount() || !$this->isEnabled()) {
      return '';
    }
    $c = $this->configFactory->get('dx_auth.settings');
    $appId = trim((string) $c->get('wechat_app_id'));
    $secret = trim((string) $c->get('wechat_secret'));
    // 不读取旧的全局缓存；缓存键只包含指纹，不包含明文凭据。
    $cacheKey = 'dx_auth.wechat_access_token.' . hash('sha256', $appId . "\0" . $secret);
    $cached = $this->state->get($cacheKey);
    if (is_array($cached) && is_string($cached['token'] ?? NULL) && trim($cached['token']) !== '' && ($cached['expire'] ?? 0) > time() + 60) {
      return $cached['token'];
    }
    try {
      $response = $this->httpClient->request('GET', 'https://api.weixin.qq.com/cgi-bin/token', [
        'query' => [
          'grant_type' => 'client_credential',
          'appid' => $appId,
          'secret' => $secret,
        ],
        'http_errors' => FALSE,
        'allow_redirects' => FALSE,
        'timeout' => 20,
      ]);
      $data = json_decode((string) $response->getBody(), TRUE);
      $data = is_array($data) ? $data : [];
      if ($response->getStatusCode() !== 200 || !empty($data['errcode']) || !is_string($data['access_token'] ?? NULL) || trim($data['access_token']) === '') {
        $this->logApiError('微信接口凭据获取失败：errcode=@errcode。', $data);
        return '';
      }
      $this->state->set($cacheKey, [
        'token' => $data['access_token'],
        'expire' => time() + (int) ($data['expires_in'] ?? 7200),
      ]);
      return (string) $data['access_token'];
    }
    catch (\Throwable) {
      $this->logApiError('微信接口凭据请求失败：errcode=@errcode。');
      return '';
    }
  }

  /**
   * 只记录规范化的错误码，避免响应正文、异常或错误码夹带敏感值。
   */
  private function logApiError(string $message, array $data = []): void {
    $errcode = $data['errcode'] ?? NULL;
    $safeCode = (is_int($errcode) || (is_string($errcode) && preg_match('/^-?\d{1,10}$/D', $errcode)))
      ? (string) $errcode
      : 'unknown';
    $this->logger->error($message, ['@errcode' => $safeCode]);
  }

}
