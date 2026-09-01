<?php

declare(strict_types=1);

namespace Drupal\dx_ecosystem\Service\Composer;

/**
 * Reads an L2 token out of a request and answers "may this path be served".
 *
 * Everything here is header/string work: no database, no Drupal state. That is
 * what lets the same code decide in the kernel.request subscriber, in a Drush
 * dry-run, and in tests/pure-assertions.php.
 *
 * Supported client transports (composer's auth.json offers the first two):
 *  - `Authorization: Basic base64(dx-uid-<uid>:dxl2_...)`  → http-basic
 *  - `Authorization: Bearer dxl2_...`                      → bearer
 *  - `X-DrupalX-Token: dxl2_...`                           → custom header
 *  - `?dx_token=dxl2_...`                                  → opt-in, tooling only
 */
final class RepositoryRequestAuth {

  public const TOKEN_PREFIX = 'dxl2_';

  public const BASIC_USER_PREFIX = 'dx-uid-';

  public const QUERY_TOKEN = 'dx_token';

  public const CHALLENGE_REALM = 'DrupalX L2';

  public const SOURCE_BASIC = 'basic';
  public const SOURCE_BEARER = 'bearer';
  public const SOURCE_HEADER = 'header';
  public const SOURCE_QUERY = 'query';

  public const CODE_OK = 'DX.L2.TOKEN_OK';
  public const CODE_MISSING = 'DX.L2.TOKEN_MISSING';
  public const CODE_MALFORMED = 'DX.L2.TOKEN_MALFORMED';
  public const CODE_UNKNOWN = 'DX.L2.TOKEN_UNKNOWN';
  public const CODE_UID_MISMATCH = 'DX.L2.TOKEN_UID_MISMATCH';
  public const CODE_REVOKED = 'DX.L2.CREDENTIAL_REVOKED';
  public const CODE_ROTATED = 'DX.L2.CREDENTIAL_ROTATED';
  public const CODE_CERT_REVOKED = 'DX.L2.CERT_REVOKED';
  public const CODE_CERT_STALE = 'DX.L2.CERT_STALE';
  public const CODE_DPA_STALE = 'DX.L2.DPA_STALE';
  public const CODE_PATH_FOREIGN = 'DX.L2.PATH_FOREIGN';

  /**
   * Whether a request path belongs to the L2 repository surface we guard.
   */
  public static function shouldGuard(string $pathInfo): bool {
    $path = '/' . ltrim(rawurldecode($pathInfo), '/');
    if ($path === ComposerHostPlan::SERVE_PREFIX) {
      return TRUE;
    }
    return str_starts_with($path, ComposerHostPlan::SERVE_PREFIX . '/');
  }

  /**
   * The metadata / download paths inside the guarded prefix.
   *
   * @return array{kind: string, provider: string, dist: string}
   */
  public static function classifyPath(string $pathInfo): array {
    $path = '/' . ltrim(rawurldecode($pathInfo), '/');
    $prefix = ComposerHostPlan::SERVE_PREFIX . '/';
    $rest = str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : '';
    $rest = trim((string) $rest, '/');
    if ($rest === '' || $rest === SatisMetadataBuilder::ROOT_FILE) {
      return ['kind' => 'root', 'provider' => '', 'dist' => ''];
    }
    if (str_starts_with($rest, 'providers/')) {
      return [
        'kind' => 'provider',
        'provider' => SatisMetadataBuilder::nameFromProviderKey(substr($rest, strlen('providers/'))),
        'dist' => '',
      ];
    }
    if (str_starts_with($rest, 'dist/')) {
      return ['kind' => 'dist', 'provider' => '', 'dist' => self::sanitizeArtifactPath(substr($rest, strlen('dist/')))];
    }
    if (str_starts_with($rest, 'plan')) {
      return ['kind' => 'plan', 'provider' => '', 'dist' => ''];
    }
    return ['kind' => 'unknown', 'provider' => '', 'dist' => ''];
  }

  /**
   * Kills traversal and absolute paths in a dist sub-path before we touch disk.
   */
  public static function sanitizeArtifactPath(string $relative): string {
    $relative = str_replace('\\', '/', trim($relative));
    $relative = (string) preg_replace('#/+#', '/', $relative);
    $relative = ltrim($relative, '/');
    $kept = [];
    foreach (explode('/', $relative) as $segment) {
      if ($segment === '' || $segment === '.') {
        continue;
      }
      if ($segment === '..') {
        // Never resolve a traversal segment: drop everything collected so far so
        // the answer can only point further *down* the dist root, and an
        // attacker cannot smuggle a sibling tree in front of the real one.
        $kept = [];
        continue;
      }
      if (!preg_match('#^[A-Za-z0-9._@+-]+$#', $segment)) {
        // Anything else (%3C, NUL, spaces) is not a path we will resolve.
        return '';
      }
      $kept[] = $segment;
    }
    return implode('/', $kept);
  }

  /**
   * Extracts the candidate token from already-lowercased header names.
   *
   * @param array<string, string> $headers
   *   Header name (lowercase) => raw value.
   * @param array<string, string> $query
   *   Query parameters; only consulted when $allowQuery is TRUE.
   *
   * @return array{token: string, source: string, uid_hint: int, code: string}
   */
  public static function extract(array $headers, array $query = [], bool $allowQuery = FALSE): array {
    $authorization = trim((string) ($headers['authorization'] ?? ''));
    if ($authorization !== '') {
      if (preg_match('/^basic\s+(.+)$/i', $authorization, $m)) {
        $decoded = base64_decode(trim($m[1]), TRUE);
        if (is_string($decoded) && str_contains($decoded, ':')) {
          [$user, $pass] = explode(':', $decoded, 2);
          $token = trim($pass);
          if ($token !== '') {
            return [
              'token' => $token,
              'source' => self::SOURCE_BASIC,
              'uid_hint' => self::uidFromBasicUser($user),
              'code' => self::shapeCode($token),
            ];
          }
        }
        return ['token' => '', 'source' => self::SOURCE_BASIC, 'uid_hint' => 0, 'code' => self::CODE_MISSING];
      }
      if (preg_match('/^bearer\s+(.+)$/i', $authorization, $m)) {
        $token = trim($m[1]);
        return [
          'token' => $token,
          'source' => self::SOURCE_BEARER,
          'uid_hint' => 0,
          'code' => $token === '' ? self::CODE_MISSING : self::shapeCode($token),
        ];
      }
    }
    $custom = trim((string) ($headers[ComposerHostPlan::TOKEN_HEADERS[1]] ?? ''));
    if ($custom !== '') {
      return [
        'token' => $custom,
        'source' => self::SOURCE_HEADER,
        'uid_hint' => 0,
        'code' => self::shapeCode($custom),
      ];
    }
    if ($allowQuery) {
      $fromQuery = trim((string) ($query[self::QUERY_TOKEN] ?? ''));
      if ($fromQuery !== '') {
        return [
          'token' => $fromQuery,
          'source' => self::SOURCE_QUERY,
          'uid_hint' => 0,
          'code' => self::shapeCode($fromQuery),
        ];
      }
    }
    return ['token' => '', 'source' => '', 'uid_hint' => 0, 'code' => self::CODE_MISSING];
  }

  /**
   * Cheap shape check, done before any storage lookup.
   */
  public static function shapeCode(string $token): string {
    $token = trim($token);
    if ($token === '') {
      return self::CODE_MISSING;
    }
    if (!str_starts_with($token, self::TOKEN_PREFIX)) {
      return self::CODE_MALFORMED;
    }
    // dxl2_ + 48 hex, exactly what PartnerCredentialStore::issue() mints.
    return preg_match('/^dxl2_[0-9a-f]{48}$/', strtolower($token)) ? self::CODE_OK : self::CODE_MALFORMED;
  }

  public static function looksLikeCredential(string $token): bool {
    return self::shapeCode($token) === self::CODE_OK;
  }

  /**
   * uid carried in the http-basic username, 0 when absent or bogus.
   */
  public static function uidFromBasicUser(string $user): int {
    $user = strtolower(trim($user));
    if (!str_starts_with($user, self::BASIC_USER_PREFIX)) {
      return 0;
    }
    $suffix = substr($user, strlen(self::BASIC_USER_PREFIX));
    return ctype_digit($suffix) ? (int) $suffix : 0;
  }

  /**
   * Verifies the uid hint against the uid the token really belongs to.
   */
  public static function uidMatches(array $extract, ?int $verifiedUid): array {
    if ($verifiedUid === NULL) {
      return ['ok' => FALSE, 'code' => self::CODE_UNKNOWN];
    }
    $hint = (int) ($extract['uid_hint'] ?? 0);
    if ($hint > 0 && $hint !== $verifiedUid) {
      return ['ok' => FALSE, 'code' => self::CODE_UID_MISMATCH];
    }
    return ['ok' => TRUE, 'code' => self::CODE_OK];
  }

  /**
   * 401 response headers, so `composer` prints a readable auth error.
   *
   * @return array<string, string>
   */
  public static function challenge(string $code = self::CODE_MISSING, string $realm = self::CHALLENGE_REALM): array {
    return [
      'WWW-Authenticate' => sprintf('Basic realm="%s", error="invalid_token", error_description="%s"', $realm, $code),
      'X-DrupalX-Error' => $code,
      'Cache-Control' => 'no-store',
    ];
  }

  /**
   * Human-readable (中文) message per code, shared by 401 body and Drush.
   *
   * @return array<string, string>
   */
  public static function messages(): array {
    return [
      self::CODE_OK => '凭证有效。',
      self::CODE_MISSING => '缺少 L2 凭证：请在 composer auth.json 中配置 http-basic 或 X-DrupalX-Token 头。',
      self::CODE_MALFORMED => '凭证格式不正确：应为 dxl2_ 前缀 + 48 位十六进制。',
      self::CODE_UNKNOWN => '凭证不存在或已轮换。',
      self::CODE_UID_MISMATCH => '凭证与 auth.json 中的用户名（dx-uid-N）不匹配。',
      self::CODE_REVOKED => '凭证已被吊销。',
      self::CODE_ROTATED => '凭证已被新签发的 token 取代。',
      self::CODE_CERT_REVOKED => '开发者认证已作废，token 同步失效。',
      self::CODE_CERT_STALE => '开发者认证未通过（pending/none），不能访问 L2 仓库。',
      self::CODE_DPA_STALE => 'DPA 版本已过期，请重新签署后再访问 L2 仓库。',
      self::CODE_PATH_FOREIGN => '请求的资源不属于 L2 仓库。',
    ];
  }

  /**
   * Message for a code, falling back to the code itself.
   */
  public static function messageFor(string $code): string {
    $messages = self::messages();
    return $messages[$code] ?? $code;
  }

  /**
   * Every stable code this layer can emit (docs + smoke assertions).
   *
   * @return list<string>
   */
  public static function codes(): array {
    return array_keys(self::messages());
  }

}
