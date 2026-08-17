<?php

declare(strict_types=1);

namespace Drupal\dx_auth\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\user\UserInterface;
use GuzzleHttp\ClientInterface;

/**
 * WeChat Open Platform / Official Account login.
 */
class WeChatAuthService {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected Connection $database,
    protected CacheBackendInterface $cache,
    protected ClientInterface $httpClient,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LoggerChannelInterface $logger,
  ) {}

  /**
   * Whether WeChat login is switched on and at least one app is configured.
   */
  public function isEnabled(): bool {
    $cfg = $this->wechatConfig();
    return !empty($cfg['enabled']) && ($this->hasWebsiteApp() || $this->hasOfficialAccount());
  }

  /**
   * Website application (QR Connect / WxLogin) is configured.
   */
  public function hasWebsiteApp(): bool {
    $cfg = $this->wechatConfig();
    return ($cfg['app_id'] ?? '') !== '' && ($cfg['app_secret'] ?? '') !== '';
  }

  /**
   * Official Account (in-WeChat OAuth) is configured.
   */
  public function hasOfficialAccount(): bool {
    $cfg = $this->wechatConfig();
    return ($cfg['mp_app_id'] ?? '') !== '' && ($cfg['mp_app_secret'] ?? '') !== '';
  }

  /**
   * Builds the Open Platform QR Connect URL.
   */
  public static function buildQrConnectUrl(string $appId, string $redirectUri, string $state): string {
    return 'https://open.weixin.qq.com/connect/qrconnect?' . http_build_query([
      'appid' => $appId,
      'redirect_uri' => $redirectUri,
      'response_type' => 'code',
      'scope' => 'snsapi_login',
      'state' => $state,
    ], '', '&', PHP_QUERY_RFC3986) . '#wechat_redirect';
  }

  /**
   * Builds Official Account webpage authorize URL (in-WeChat).
   */
  public static function buildMpAuthorizeUrl(string $appId, string $redirectUri, string $state): string {
    return 'https://open.weixin.qq.com/connect/oauth2/authorize?' . http_build_query([
      'appid' => $appId,
      'redirect_uri' => $redirectUri,
      'response_type' => 'code',
      'scope' => 'snsapi_userinfo',
      'state' => $state,
    ], '', '&', PHP_QUERY_RFC3986) . '#wechat_redirect';
  }

  /**
   * Creates a one-time state token bound to destination + channel.
   *
   * @return array{state: string, app_id: string, authorize_url: string, wx_script: string, redirect_uri: string, channel: string}
   */
  public function start(string $redirectUri, string $destination = '/', string $channel = 'qr'): array {
    $cfg = $this->wechatConfig();
    $channel = $channel === 'mp' ? 'mp' : 'qr';
    if ($channel === 'mp' && !$this->hasOfficialAccount()) {
      $channel = 'qr';
    }
    if ($channel === 'qr' && !$this->hasWebsiteApp()) {
      $channel = 'mp';
    }

    $appId = $channel === 'mp'
      ? (string) $cfg['mp_app_id']
      : (string) $cfg['app_id'];

    $state = bin2hex(random_bytes(16));
    $this->cache->set('dx_auth:wxstate:' . $state, [
      'destination' => $destination,
      'channel' => $channel,
      'created' => time(),
    ], time() + 600);

    $authorize = $channel === 'mp'
      ? self::buildMpAuthorizeUrl($appId, $redirectUri, $state)
      : self::buildQrConnectUrl($appId, $redirectUri, $state);

    return [
      'state' => $state,
      'app_id' => $appId,
      'authorize_url' => $authorize,
      'wx_script' => 'https://res.wx.qq.com/connect/zh_CN/htmledition/js/wxLogin.js',
      'redirect_uri' => $redirectUri,
      'channel' => $channel,
      'scope' => $channel === 'mp' ? 'snsapi_userinfo' : 'snsapi_login',
    ];
  }

  /**
   * Completes OAuth: validates state, exchanges code, finds or creates a user.
   *
   * @return array{ok: bool, msg: string, user?: \Drupal\user\UserInterface, destination?: string}
   */
  public function complete(string $code, string $state): array {
    if ($code === '' || $state === '') {
      return ['ok' => FALSE, 'msg' => 'missing_code'];
    }
    $cached = $this->cache->get('dx_auth:wxstate:' . $state);
    $this->cache->delete('dx_auth:wxstate:' . $state);
    if (!$cached || empty($cached->data) || !is_array($cached->data)) {
      return ['ok' => FALSE, 'msg' => 'invalid_state'];
    }
    $payload = $cached->data;
    $channel = (($payload['channel'] ?? 'qr') === 'mp') ? 'mp' : 'qr';
    $destination = (string) ($payload['destination'] ?? '/');

    $token = $this->exchangeCode($code, $channel);
    if (empty($token['openid'])) {
      return ['ok' => FALSE, 'msg' => 'wechat_token_failed'];
    }

    $profile = $this->fetchUserInfo((string) $token['access_token'], (string) $token['openid']);
    $openid = (string) $token['openid'];
    $unionid = (string) ($token['unionid'] ?? $profile['unionid'] ?? '');
    $nickname = (string) ($profile['nickname'] ?? '');

    $user = $this->findOrCreateUser($openid, $unionid, $nickname);
    if (!$user instanceof UserInterface || !$user->isActive()) {
      return ['ok' => FALSE, 'msg' => 'account_unavailable'];
    }

    return [
      'ok' => TRUE,
      'msg' => 'ok',
      'user' => $user,
      'destination' => $destination,
    ];
  }

  /**
   * @return array<string, string>
   */
  protected function wechatConfig(): array {
    $raw = $this->configFactory->get('dx_auth.settings')->get('wechat') ?: [];
    return [
      'enabled' => !empty($raw['enabled']),
      'app_id' => trim((string) ($raw['app_id'] ?? '')),
      'app_secret' => trim((string) ($raw['app_secret'] ?? '')),
      'mp_app_id' => trim((string) ($raw['mp_app_id'] ?? '')),
      'mp_app_secret' => trim((string) ($raw['mp_app_secret'] ?? '')),
    ];
  }

  /**
   * @return array{access_token?: string, openid?: string, unionid?: string}
   */
  protected function exchangeCode(string $code, string $channel): array {
    $cfg = $this->wechatConfig();
    $appId = $channel === 'mp' ? $cfg['mp_app_id'] : $cfg['app_id'];
    $secret = $channel === 'mp' ? $cfg['mp_app_secret'] : $cfg['app_secret'];
    try {
      $response = $this->httpClient->request('GET', 'https://api.weixin.qq.com/sns/oauth2/access_token', [
        'query' => [
          'appid' => $appId,
          'secret' => $secret,
          'code' => $code,
          'grant_type' => 'authorization_code',
        ],
        'timeout' => 12,
      ]);
      $data = json_decode((string) $response->getBody(), TRUE);
      return is_array($data) ? $data : [];
    }
    catch (\Throwable $e) {
      $this->logger->error('WeChat token exchange failed: @m', ['@m' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * @return array<string, mixed>
   */
  protected function fetchUserInfo(string $accessToken, string $openid): array {
    if ($accessToken === '' || $openid === '') {
      return [];
    }
    try {
      $response = $this->httpClient->request('GET', 'https://api.weixin.qq.com/sns/userinfo', [
        'query' => [
          'access_token' => $accessToken,
          'openid' => $openid,
          'lang' => 'zh_CN',
        ],
        'timeout' => 12,
      ]);
      $data = json_decode((string) $response->getBody(), TRUE);
      return is_array($data) ? $data : [];
    }
    catch (\Throwable $e) {
      $this->logger->warning('WeChat userinfo skipped: @m', ['@m' => $e->getMessage()]);
      return [];
    }
  }

  protected function findOrCreateUser(string $openid, string $unionid, string $nickname): ?UserInterface {
    $uid = 0;
    if ($unionid !== '') {
      $uid = (int) $this->database->select('dx_auth_wechat', 'w')
        ->fields('w', ['uid'])
        ->condition('unionid', $unionid)
        ->range(0, 1)
        ->execute()
        ->fetchField();
    }
    if ($uid <= 0) {
      $uid = (int) $this->database->select('dx_auth_wechat', 'w')
        ->fields('w', ['uid'])
        ->condition('openid', $openid)
        ->range(0, 1)
        ->execute()
        ->fetchField();
    }

    $storage = $this->entityTypeManager->getStorage('user');
    if ($uid > 0) {
      $user = $storage->load($uid);
      if ($user instanceof UserInterface) {
        $this->bind($uid, $openid, $unionid, $nickname);
        return $user;
      }
    }

    $base = 'wx_' . substr(preg_replace('/[^a-zA-Z0-9]/', '', $openid) ?: bin2hex(random_bytes(6)), -12);
    $name = $base;
    $i = 0;
    while ($storage->loadByProperties(['name' => $name])) {
      $i++;
      $name = $base . $i;
      if ($i > 50) {
        $name = $base . bin2hex(random_bytes(2));
        break;
      }
    }

    /** @var \Drupal\user\UserInterface $user */
    $user = $storage->create([
      'name' => $name,
      'status' => 1,
    ]);
    if ($nickname !== '') {
      try {
        if ($user->hasField('field_display_name')) {
          $user->set('field_display_name', mb_substr($nickname, 0, 128));
        }
      }
      catch (\Throwable $e) {
        // Optional field.
      }
    }
    $user->save();
    $this->bind((int) $user->id(), $openid, $unionid, $nickname);
    return $user;
  }

  protected function bind(int $uid, string $openid, string $unionid, string $nickname): void {
    $now = time();
    $existing = $this->database->select('dx_auth_wechat', 'w')
      ->fields('w', ['id'])
      ->condition('openid', $openid)
      ->range(0, 1)
      ->execute()
      ->fetchField();
    $fields = [
      'uid' => $uid,
      'unionid' => mb_substr($unionid, 0, 64),
      'nickname' => mb_substr($nickname, 0, 255),
      'changed' => $now,
    ];
    if ($existing) {
      $this->database->update('dx_auth_wechat')
        ->fields($fields)
        ->condition('id', (int) $existing)
        ->execute();
      return;
    }
    $fields['openid'] = mb_substr($openid, 0, 64);
    $fields['created'] = $now;
    $this->database->insert('dx_auth_wechat')->fields($fields)->execute();
  }

}
