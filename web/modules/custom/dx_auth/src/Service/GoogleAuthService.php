<?php

declare(strict_types=1);

namespace Drupal\dx_auth\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use GuzzleHttp\ClientInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Google OAuth2 web login (Topstar wechatquery google_jump port).
 */
class GoogleAuthService {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected ClientInterface $httpClient,
    protected LoggerChannelInterface $logger,
  ) {}

  /**
   * Configured + geo-allowed (Topstar wechatquery_google_login_available).
   */
  public function isAvailable(Request $request): bool {
    $c = $this->configFactory->get('dx_auth.settings');
    if (!(bool) $c->get('google_enabled')) {
      return FALSE;
    }
    if (trim((string) $c->get('google_client_id')) === '' || trim((string) $c->get('google_client_secret')) === '') {
      return FALSE;
    }
    if ((bool) $c->get('google_ignore_geo')) {
      return TRUE;
    }
    return !$this->isMainlandChina($request);
  }

  /**
   * Builds Google authorize URL.
   */
  public function buildAuthorizeUrl(string $redirectUri, string $state): string {
    $clientId = trim((string) $this->configFactory->get('dx_auth.settings')->get('google_client_id'));
    $params = [
      'client_id' => $clientId,
      'redirect_uri' => $redirectUri,
      'response_type' => 'code',
      'scope' => 'openid email profile',
      'access_type' => 'online',
      'prompt' => 'select_account',
      'state' => $state,
    ];
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
  }

  /**
   * Canonical redirect URI (must match Google Console).
   */
  public function redirectUri(Request $request): string {
    $override = trim((string) $this->configFactory->get('dx_auth.settings')->get('google_redirect_uri'));
    if ($override !== '') {
      return rtrim($override, '/');
    }
    return $request->getSchemeAndHttpHost() . '/dx/auth/google_jump';
  }

  /**
   * Exchange code → profile.
   *
   * @return array{sub: string, email: string, email_verified: bool, name: string}
   */
  public function profileFromCode(string $code, Request $request): array {
    $c = $this->configFactory->get('dx_auth.settings');
    $clientId = trim((string) $c->get('google_client_id'));
    $clientSecret = trim((string) $c->get('google_client_secret'));
    $redirectUri = $this->redirectUri($request);

    $tokenResponse = $this->httpClient->request('POST', 'https://oauth2.googleapis.com/token', [
      'form_params' => [
        'code' => $code,
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri' => $redirectUri,
        'grant_type' => 'authorization_code',
      ],
      'headers' => ['Accept' => 'application/json'],
      'http_errors' => FALSE,
      'timeout' => 20,
    ]);
    $token = json_decode((string) $tokenResponse->getBody(), TRUE) ?: [];
    if (empty($token['access_token'])) {
      throw new \RuntimeException($token['error_description'] ?? ($token['error'] ?? 'token_exchange_failed'));
    }

    $userResponse = $this->httpClient->request('GET', 'https://openidconnect.googleapis.com/v1/userinfo', [
      'headers' => [
        'Authorization' => 'Bearer ' . $token['access_token'],
        'Accept' => 'application/json',
      ],
      'http_errors' => FALSE,
      'timeout' => 20,
    ]);
    $profile = json_decode((string) $userResponse->getBody(), TRUE) ?: [];
    if (empty($profile['sub'])) {
      throw new \RuntimeException('userinfo_failed');
    }
    return [
      'sub' => (string) $profile['sub'],
      'email' => mb_strtolower(trim((string) ($profile['email'] ?? ''))),
      'email_verified' => !empty($profile['email_verified']),
      'name' => trim((string) ($profile['name'] ?? '')),
    ];
  }

  /**
   * Mainland CN gate (HK/MO/TW allowed). Prefer CF headers; unknown → hide.
   */
  public function isMainlandChina(Request $request): bool {
    $cf = strtoupper(trim((string) $request->headers->get('CF-IPCountry', '')));
    if (preg_match('/^[A-Z]{2}$/', $cf) && $cf !== 'XX' && $cf !== 'T1') {
      return $cf === 'CN';
    }
    foreach (['CloudFront-Viewer-Country', 'X-AppEngine-Country'] as $key) {
      $val = strtoupper(trim((string) $request->headers->get($key, '')));
      if (preg_match('/^[A-Z]{2}$/', $val) && $val !== 'XX' && $val !== 'T1') {
        return $val === 'CN';
      }
    }
    return TRUE;
  }

}
