<?php

declare(strict_types=1);

namespace Drupal\dx_ecosystem\Service\Composer;

/**
 * Turns the L2 repository settings into a switchable host plan (pure).
 *
 * The plan answers three questions for every caller (credential form, Drush,
 * the repository controller, the CI lint command) without touching config or
 * the container:
 *
 *  - where does a partner point Composer / Git (base url, token header, driver),
 *  - is that endpoint still the OE2 placeholder or a real Satis / Artifactory,
 *  - where do the artifacts physically live (local repository root, so the
 *    whole layer can be exercised offline through file:// or a directory).
 *
 * @see \Drupal\dx_ecosystem\Service\L2ComposerRepository
 */
final class ComposerHostPlan {

  public const DRIVER_SATIS = 'satis';
  public const DRIVER_ARTIFACTORY = 'artifactory';
  public const DRIVER_LOOPBACK = 'loopback';

  /** Hosts shipped in config/install; they resolve to nothing on the network. */
  public const PLACEHOLDER_COMPOSER_HOST = 'packages.drupalx.local';
  public const PLACEHOLDER_GIT_HOST = 'git.drupalx.local';

  /** Header names we accept a token in. Anything else is refused, not guessed. */
  public const TOKEN_HEADERS = ['authorization', 'x-drupalx-token'];

  public const AUTH_BASIC = 'http-basic';
  public const AUTH_BEARER = 'bearer';
  public const AUTH_HEADER = 'header';
  public const AUTH_NONE = 'none';

  /** Path prefix this module serves its own metadata under. */
  public const SERVE_PREFIX = '/dx/ecosystem/l2';

  public const WARN_HOST_PLACEHOLDER = 'DX.L2.HOST_PLACEHOLDER';
  public const WARN_GIT_HOST_PLACEHOLDER = 'DX.L2.GIT_HOST_PLACEHOLDER';
  public const WARN_ROOT_MISSING = 'DX.L2.ROOT_MISSING';
  public const WARN_HEADER_UNKNOWN = 'DX.L2.TOKEN_HEADER_UNKNOWN';
  public const WARN_SIGN_KEY_WEAK = 'DX.L2.SIGN_KEY_WEAK';

  /**
   * Builds the plan from a dx_ecosystem.settings array.
   *
   * Unknown or missing keys fall back to the OE2 defaults, so an installed site
   * that never ran the config import behaves exactly like before this class
   * existed (placeholder host, Basic auth, no local root).
   *
   * @param array<string, mixed> $settings
   *   Raw config values, e.g. Config::get('dx_ecosystem.settings')->raw().
   * @param string $origin
   *   Absolute scheme+host used to expand the Drupal-served metadata url.
   *
   * @return array<string, mixed>
   */
  public static function fromSettings(array $settings, string $origin = ''): array {
    $host = self::normalizeHost((string) ($settings['l2_composer_host'] ?? ''));
    if ($host === '') {
      $host = self::PLACEHOLDER_COMPOSER_HOST;
    }
    $gitHost = self::normalizeHost((string) ($settings['l2_git_host'] ?? ''));
    if ($gitHost === '') {
      $gitHost = self::PLACEHOLDER_GIT_HOST;
    }

    $root = trim((string) ($settings['l2_repository_root'] ?? ''));
    $baseUrl = trim((string) ($settings['l2_composer_base_url'] ?? ''));
    if ($baseUrl === '' && $root !== '') {
      // A local repository root without an explicit base url *is* the base url.
      $baseUrl = self::fileUrl($root);
    }
    if ($baseUrl === '') {
      $baseUrl = self::placeholderScheme($host) . '://' . $host;
    }
    $parts = self::parseBaseUrl($baseUrl);
    if ($parts['host'] !== '') {
      // A configured base url wins over the bare host knob: the auth block and
      // the repository url must name the same server.
      $host = $parts['host'];
    }
    $loopback = $parts['scheme'] === 'file';

    $requested = strtolower(trim((string) ($settings['l2_composer_driver'] ?? 'auto')));
    $driver = self::driverFor($parts, $requested, $loopback);

    $header = strtolower(trim((string) ($settings['l2_token_header'] ?? 'authorization')));
    if ($header === '') {
      $header = 'authorization';
    }

    $warnings = [];
    $placeholder = FALSE;
    if ($host === self::PLACEHOLDER_COMPOSER_HOST && !$loopback) {
      $placeholder = TRUE;
      $warnings[self::WARN_HOST_PLACEHOLDER] = 'L2 Composer 主机仍是占位 ' . self::PLACEHOLDER_COMPOSER_HOST . '，接入真实 Satis/Artifactory 后改 l2_composer_base_url。';
    }
    if ($gitHost === self::PLACEHOLDER_GIT_HOST) {
      // Own key, otherwise the Git warning would silently replace the Composer
      // one and the admin page would only ever show a single placeholder hint.
      $warnings[self::WARN_GIT_HOST_PLACEHOLDER] = 'L2 Git 主机仍是占位 ' . self::PLACEHOLDER_GIT_HOST . '。';
    }
    if ($root === '') {
      $warnings[self::WARN_ROOT_MISSING] = '未配置 l2_repository_root：只能生成元数据，不能本机下发 dist 包。';
    }
    if (!in_array($header, self::TOKEN_HEADERS, TRUE)) {
      $warnings[self::WARN_HEADER_UNKNOWN] = '未知的 l2_token_header ' . $header . '，只支持 ' . implode('/', self::TOKEN_HEADERS) . '。';
    }
    $keyState = self::signingKeyState((string) ($settings['l2_signing_key'] ?? ''));
    if ($keyState !== 'ok') {
      $warnings[self::WARN_SIGN_KEY_WEAK] = 'l2_signing_key 未配置或过短，下载 URL 签名将使用站点随机密钥（重装后旧链接失效）。';
    }

    // Both drivers publish the root document under the same name; the scheme
    // only decides whether a client fetches it from disk or over HTTP.
    $rootUrl = rtrim($parts['base'], '/') . '/' . SatisMetadataBuilder::ROOT_FILE;

    return [
      'configured' => !$placeholder,
      'placeholder' => $placeholder,
      'driver' => $driver,
      'loopback' => $loopback,
      'scheme' => $parts['scheme'],
      'host' => $host,
      'composer_host' => $host,
      'git_host' => $gitHost,
      'base_url' => $parts['base'],
      'root_url' => $rootUrl,
      'served_root_url' => ($origin !== '' ? rtrim($origin, '/') : '') . self::SERVE_PREFIX . '/' . SatisMetadataBuilder::ROOT_FILE,
      'token_header' => $header,
      'auth_mode' => self::authModeForHeader($header),
      'repository_root' => $root,
      'download_ttl' => DownloadUrlSigner::ttl((int) ($settings['l2_download_ttl'] ?? DownloadUrlSigner::DEFAULT_TTL)),
      'signing_key_configured' => $keyState === 'ok',
      'warnings' => $warnings,
    ];
  }

  /**
   * Picks the adapter flavour: Satis, Artifactory, or an offline loopback root.
   *
   * @param array{scheme:string, host:string, path:string, base:string} $parts
   */
  public static function driverFor(array $parts, string $requested = 'auto', bool $loopback = FALSE): string {
    if (in_array($requested, [self::DRIVER_SATIS, self::DRIVER_ARTIFACTORY, self::DRIVER_LOOPBACK], TRUE)) {
      return $requested;
    }
    if ($loopback || $parts['scheme'] === 'file') {
      return self::DRIVER_LOOPBACK;
    }
    $needle = strtolower($parts['host'] . '/' . $parts['path']);
    if (str_contains($needle, 'artifactory') || str_contains($needle, '/api/storage/')) {
      return self::DRIVER_ARTIFACTORY;
    }
    return self::DRIVER_SATIS;
  }

  /**
   * Which transport a configured token header implies for the client.
   */
  public static function authModeForHeader(string $header): string {
    return match (strtolower(trim($header))) {
      'authorization' => self::AUTH_BASIC,
      'x-drupalx-token' => self::AUTH_HEADER,
      default => self::AUTH_NONE,
    };
  }

  /**
   * Splits a base url into the pieces the plan needs. Never throws.
   *
   * @return array{scheme:string, host:string, path:string, base:string}
   */
  public static function parseBaseUrl(string $url): array {
    $url = trim($url);
    if (self::looksLikePath($url)) {
      $path = self::pathFromUrl($url);
      return [
        'scheme' => 'file',
        'host' => '',
        'path' => $path,
        'base' => 'file://' . $path,
      ];
    }
    if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
      $url = 'https://' . ltrim($url, '/');
    }
    $parts = parse_url($url);
    $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
    $host = (string) ($parts['host'] ?? '');
    $port = isset($parts['port']) ? (int) $parts['port'] : 0;
    $path = rtrim((string) ($parts['path'] ?? ''), '/');
    if ($port > 0) {
      $host .= ':' . $port;
    }
    return [
      'scheme' => $scheme,
      'host' => $host,
      'path' => $path,
      'base' => $host === '' ? $scheme . '://' . ltrim($path, '/') : $scheme . '://' . $host . $path,
    ];
  }

  /**
   * Turns a local directory into a file:// url (composer accepts both).
   *
   * An absolute path keeps its leading slash, so "/srv/l2" becomes
   * "file:///srv/l2" - the form Symfony and Composer both resolve to /srv/l2.
   */
  public static function fileUrl(string $path): string {
    return 'file://' . self::pathFromUrl($path);
  }

  /**
   * Strips a file:// scheme and any query/fragment from a path-ish value.
   */
  public static function pathFromUrl(string $value): string {
    $value = trim($value);
    if (str_starts_with($value, 'file://')) {
      $value = substr($value, 7);
    }
    $value = (string) preg_replace('/[?#].*$/', '', $value);
    return rtrim(str_replace('\\', '/', $value), '/');
  }

  /**
   * True for "/abs/dir", "./rel/dir" and "file:///abs/dir" style values.
   */
  public static function looksLikePath(string $value): bool {
    $value = trim($value);
    if ($value === '') {
      return FALSE;
    }
    if (str_starts_with($value, 'file://')) {
      return TRUE;
    }
    return !preg_match('#^[a-z][a-z0-9+.-]*://#i', $value) && !preg_match('#^[a-z0-9.:-]+$#i', $value);
  }

  /**
   * Removes credentials from a url so it can be logged or shown in a report.
   */
  public static function redact(string $url): string {
    $url = (string) preg_replace('#(://)[^/@]*@#', '$1***@', $url);
    return (string) preg_replace('#([?&](?:dx_token|token|signature|expires)=)[^&#]*#', '$1***', $url);
  }

  /**
   * The "repositories" block a partner pastes into their composer.json.
   *
   * @param array<string, mixed> $plan
   *
   * @return array<string, mixed>
   */
  public static function repositoriesSnippet(array $plan, string $rootUrl = ''): array {
    return [
      'repositories' => [
        'drupalx-l2' => [
          'type' => 'composer',
          'url' => $rootUrl !== '' ? $rootUrl : (string) $plan['root_url'],
        ],
      ],
    ];
  }

  /**
   * The auth.json block a partner needs for this plan.
   *
   * Structurally identical to PartnerCredentialStore::composerSnippet() for the
   * default Basic plan, so partner instructions written during OE2 stay valid.
   *
   * @param array<string, mixed> $plan
   *
   * @return array<string, mixed>
   */
  public static function authSnippet(array $plan, int $uid, string $token): array {
    if ((string) $plan['auth_mode'] === self::AUTH_HEADER) {
      return [
        'drupalx' => [
          'token_header' => (string) $plan['token_header'],
          'token' => $token,
        ],
      ];
    }
    if (!empty($plan['loopback']) || (string) $plan['auth_mode'] === self::AUTH_NONE) {
      return [];
    }
    return [
      'http-basic' => [
        (string) $plan['composer_host'] => [
          'username' => 'dx-uid-' . $uid,
          'password' => $token,
        ],
      ],
    ];
  }

  /**
   * "ok" when the configured signing secret is long enough to be usable.
   */
  public static function signingKeyState(string $secret): string {
    return strlen(trim($secret)) >= 32 ? 'ok' : 'weak';
  }

  /**
   * Lowercases and strips scheme/path noise from a configured host value.
   */
  private static function normalizeHost(string $host): string {
    $host = strtolower(trim($host));
    $host = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $host);
    return trim((string) preg_replace('#[/?].*$#', '', $host));
  }

  /**
   * Placeholder hosts stay http so a local loopback needs no TLS termination.
   */
  private static function placeholderScheme(string $host): string {
    return in_array($host, [self::PLACEHOLDER_COMPOSER_HOST, self::PLACEHOLDER_GIT_HOST], TRUE) ? 'http' : 'https';
  }

}
