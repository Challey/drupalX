<?php

declare(strict_types=1);

namespace Drupal\dx_ecosystem\Service\Composer;

/**
 * HMAC signing of L2 dist download urls (pure, no config, no container).
 *
 * A Satis tree hands out `dist.url` links that anyone holding the file could
 * forward. Signing binds every link to the credential that asked for it, to the
 * artifact path, and to a short expiry window, which is what makes the loopback
 * repository safe enough to serve real zips. Artifactory has its own expiring
 * URI signing; the parameters below are the pieces a real adapter needs too.
 */
final class DownloadUrlSigner {

  public const ALGORITHM = 'sha256';

  public const DEFAULT_TTL = 900;

  /** Two hours: long enough for a slow CI pull, short enough to be useless later. */
  public const MAX_TTL = 7200;

  /** One minute: below that a download race turns into mysterious 401s. */
  public const MIN_TTL = 60;

  public const MIN_SECRET_LENGTH = 32;

  public const QUERY_EXPIRES = 'expires';

  public const QUERY_SIGNATURE = 'signature';

  public const QUERY_BUNDLE = 'bundle';

  public const CODE_OK = 'DX.L2.OK';
  public const CODE_MISSING = 'DX.L2.SIGN_MISSING';
  public const CODE_INVALID = 'DX.L2.SIGN_INVALID';
  public const CODE_EXPIRED = 'DX.L2.SIGN_EXPIRED';
  public const CODE_UID_MISMATCH = 'DX.L2.SIGN_UID_MISMATCH';
  public const CODE_SECRET_WEAK = 'DX.L2.SIGN_SECRET_WEAK';

  /**
   * Clamps a requested lifetime into the supported range.
   */
  public static function ttl(int $requested): int {
    if ($requested <= 0) {
      return self::DEFAULT_TTL;
    }
    return max(self::MIN_TTL, min($requested, self::MAX_TTL));
  }

  /**
   * The exact string a signature is computed over. Versioned for rotation.
   */
  public static function canonicalString(string $path, int $expires, string $boundUid = ''): string {
    return implode("\n", [
      'v1',
      strtolower(trim($path, '/')),
      (string) $expires,
      $boundUid === '' ? '' : $boundUid,
    ]);
  }

  /**
   * True when the secret can be trusted to sign anything with.
   */
  public static function strongSecret(string $secret): bool {
    return strlen(trim($secret)) >= self::MIN_SECRET_LENGTH;
  }

  /**
   * Returns the query parameters that make a dist url usable.
   *
   * @return array<string, string>
   */
  public static function sign(string $secret, string $path, int $now, int $ttl = self::DEFAULT_TTL, string $boundUid = ''): array {
    $expires = $now + self::ttl($ttl);
    return [
      self::QUERY_EXPIRES => (string) $expires,
      self::QUERY_SIGNATURE => hash_hmac(self::ALGORITHM, self::canonicalString($path, $expires, $boundUid), $secret),
      self::QUERY_BUNDLE => $boundUid === '' ? 'anon' : (string) $boundUid,
    ];
  }

  /**
   * Verifies a signature in constant time and reports a stable code.
   *
   * @param array<string, string|int> $params
   *   Typically the request query bag as an array.
   *
   * @return array{ok: bool, code: string, expires: int}
   */
  public static function verify(string $secret, string $path, array $params, ?int $now = NULL, string $boundUid = ''): array {
    $now ??= time();
    $expires = (int) ($params[self::QUERY_EXPIRES] ?? 0);
    $signature = strtolower((string) ($params[self::QUERY_SIGNATURE] ?? ''));
    $bundle = (string) ($params[self::QUERY_BUNDLE] ?? '');

    if (!self::strongSecret($secret)) {
      return ['ok' => FALSE, 'code' => self::CODE_SECRET_WEAK, 'expires' => $expires];
    }
    if ($signature === '' || $expires <= 0) {
      return ['ok' => FALSE, 'code' => self::CODE_MISSING, 'expires' => $expires];
    }
    if ($expires < $now) {
      return ['ok' => FALSE, 'code' => self::CODE_EXPIRED, 'expires' => $expires];
    }
    if ($boundUid !== '' && $bundle !== '' && $bundle !== $boundUid) {
      return ['ok' => FALSE, 'code' => self::CODE_UID_MISMATCH, 'expires' => $expires];
    }
    $expected = hash_hmac(self::ALGORITHM, self::canonicalString($path, $expires, $boundUid), $secret);
    if (!hash_equals($expected, $signature)) {
      return ['ok' => FALSE, 'code' => self::CODE_INVALID, 'expires' => $expires];
    }
    return ['ok' => TRUE, 'code' => self::CODE_OK, 'expires' => $expires];
  }

  /**
   * Appends signature parameters to a url without clobbering an existing query.
   *
   * @param array<string, string> $params
   */
  public static function appendQuery(string $url, array $params): string {
    if ($params === []) {
      return $url;
    }
    $fragment = '';
    if (str_contains($url, '#')) {
      [$url, $fragment] = explode('#', $url, 2);
      $fragment = '#' . $fragment;
    }
    $pairs = [];
    foreach ($params as $key => $value) {
      $pairs[] = rawurlencode((string) $key) . '=' . rawurlencode($value);
    }
    return $url . (str_contains($url, '?') ? '&' : '?') . implode('&', $pairs) . $fragment;
  }

  /**
   * Codes a client can branch on, used by the docs and the smoke scripts.
   *
   * @return list<string>
   */
  public static function codes(): array {
    return [
      self::CODE_OK,
      self::CODE_MISSING,
      self::CODE_INVALID,
      self::CODE_EXPIRED,
      self::CODE_UID_MISMATCH,
      self::CODE_SECRET_WEAK,
    ];
  }

}
