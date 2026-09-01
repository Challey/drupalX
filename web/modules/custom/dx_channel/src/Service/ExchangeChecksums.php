<?php

declare(strict_types=1);

namespace Drupal\dx_channel\Service;

/**
 * SHA-256 integrity for DXEP offline packages (roadmap G3).
 *
 * Every ZIP produced by dx_channel carries a `checksums.sha256` ledger in the
 * `sha256sum --text-mode` format, and registration refuses a ZIP whose ledger
 * is missing or does not line up with the archived bytes. The digest of the
 * canonical package document is stored next to the payload so that `apply`
 * re-verifies the *stored* resources too — a tampered `key_value` state can no
 * longer be pushed into Ingest unnoticed.
 *
 * All functions here are pure (bytes in, arrays out) and are covered by
 * `web/modules/custom/dx_channel/tests/pure-assertions.php`.
 */
final class ExchangeChecksums {

  public const ALGORITHM = 'sha256';

  /** File names inside an offline ZIP archive. */
  public const LEDGER_NAME = 'checksums.sha256';
  public const PACKAGE_NAME = 'package.json';

  /** Stable error codes (docs/data-exchange.md §12). */
  public const ERR_MISSING = 'DX.EXCHANGE.CHECKSUM_MISSING';
  public const ERR_MISMATCH = 'DX.EXCHANGE.CHECKSUM_MISMATCH';
  public const ERR_INVALID = 'DX.EXCHANGE.CHECKSUM_INVALID';

  public const STATUS_VERIFIED = 'verified';
  public const STATUS_INLINE = 'inline';
  public const STATUS_UNSIGNED = 'unsigned';
  public const STATUS_MISSING = 'missing';
  public const STATUS_MISMATCHED = 'mismatched';
  public const STATUS_LEGACY = 'legacy';

  /**
   * Hash every archived file the way `sha256sum` would.
   *
   * @param array<string, string> $files name => raw bytes
   *
   * @return array<string, string> name => 64-char lower-case hex digest
   */
  public static function ledger(array $files): array {
    $ledger = [];
    foreach ($files as $name => $bytes) {
      if (!is_string($name) || !is_string($bytes)) {
        continue;
      }
      $ledger[self::normalizeName($name)] = hash(self::ALGORITHM, $bytes);
    }
    ksort($ledger);
    return $ledger;
  }

  /**
   * Render a ledger in `sha256sum` text format (hash, two spaces, name).
   *
   * A leading comment records the algorithm and the package it belongs to so a
   * human receiving a USB stick can sanity-check it without tooling.
   *
   * @param array<string, string> $ledger
   */
  public static function renderLedger(array $ledger, string $packageId = '', bool $trailingNewline = TRUE): string {
    ksort($ledger);
    $lines = ['# DXEP ' . self::ALGORITHM . ' ledger'];
    if ($packageId !== '') {
      $lines[] = '# package_id=' . $packageId;
    }
    foreach ($ledger as $name => $digest) {
      $lines[] = $digest . '  ' . $name;
    }
    $text = implode("\n", $lines);
    return $trailingNewline ? $text . "\n" : $text;
  }

  /**
   * Parse a ledger document.
   *
   * Tolerates `#` comments, blank lines, the GNU binary-mode marker (`*name`)
   * and one-or-more spaces between digest and name. A syntactically impossible
   * digest (wrong length / non hex) is reported through $malformed instead of
   * being dropped, so a corrupt ledger never silently verifies.
   *
   * @return array{entries: array<string, string>, malformed: list<array{line: int, value: string, reason: string}>}
   */
  public static function parseLedger(string $text): array {
    $entries = [];
    $malformed = [];
    foreach (preg_split('/\R/', $text) ?: [] as $index => $line) {
      $line = trim((string) $line);
      if ($line === '' || str_starts_with($line, '#')) {
        continue;
      }
      if (preg_match('/^([0-9a-fA-F]+)\s+\*?(.+)$/', $line, $m) !== 1) {
        $malformed[] = ['line' => $index + 1, 'value' => $line, 'reason' => 'not a <digest>  <name> row'];
        continue;
      }
      $digest = strtolower($m[1]);
      $name = self::normalizeName(trim($m[2]));
      if (strlen($digest) !== 64 || preg_match('/^[0-9a-f]{64}$/', $digest) !== 1) {
        $malformed[] = [
          'line' => $index + 1,
          'value' => $name,
          'reason' => 'digest is ' . strlen($digest) . ' chars, expected 64 hex',
        ];
        continue;
      }
      if ($name === '') {
        $malformed[] = ['line' => $index + 1, 'value' => $digest, 'reason' => 'missing file name'];
        continue;
      }
      if (in_array('..', explode('/', $name), TRUE)) {
        // A ledger must never reach outside the archive root. Refusing the row
        // (instead of silently trimming it) keeps the ledger honest and makes
        // the whole package fail verification.
        $malformed[] = ['line' => $index + 1, 'value' => $name, 'reason' => 'ledger path escapes the archive root'];
        continue;
      }
      $entries[$name] = $digest;
    }
    ksort($entries);
    return ['entries' => $entries, 'malformed' => $malformed];
  }

  /**
   * Compare a declared ledger against the digests actually observed.
   *
   * @param array<string, string> $declared from the archive ledger
   * @param array<string, string> $actual   computed over the archive members
   *
   * @return array{ok: bool, matched: list<string>, mismatched: list<array{name: string, expected: string, actual: string}>, missing: list<string>, undeclared: list<string>, malformed: list<array{line: int, value: string, reason: string}>}
   */
  public static function compare(array $declared, array $actual, array $malformed = []): array {
    $matched = [];
    $mismatched = [];
    $missing = [];
    foreach ($declared as $name => $expected) {
      if (!isset($actual[$name])) {
        $missing[] = (string) $name;
        continue;
      }
      if (!hash_equals((string) $expected, (string) $actual[$name])) {
        $mismatched[] = ['name' => (string) $name, 'expected' => (string) $expected, 'actual' => (string) $actual[$name]];
        continue;
      }
      $matched[] = (string) $name;
    }
    $undeclared = array_values(array_diff(array_keys($actual), array_keys($declared), [self::LEDGER_NAME]));
    sort($matched);
    sort($undeclared);
    return [
      'ok' => $mismatched === [] && $missing === [] && $undeclared === [] && $malformed === [],
      'matched' => $matched,
      'mismatched' => $mismatched,
      'missing' => $missing,
      'undeclared' => $undeclared,
      'malformed' => $malformed,
    ];
  }

  /**
   * Turn a comparison into the persisted integrity record.
   *
   * @param array<string, mixed> $comparison
   * @param array<string, string> $declared
   *
   * @return array<string, mixed>
   */
  public static function integrityFromComparison(array $comparison, array $declared, string $archiveDigest = ''): array {
    $issues = [];
    foreach ($comparison['mismatched'] ?? [] as $row) {
      $issues[] = ['field' => 'checksums.' . ($row['name'] ?? '?'), 'issue' => 'digest mismatch'];
    }
    foreach ($comparison['missing'] ?? [] as $name) {
      $issues[] = ['field' => 'checksums.' . $name, 'issue' => 'declared in ledger but absent from archive'];
    }
    foreach ($comparison['undeclared'] ?? [] as $name) {
      $issues[] = ['field' => 'checksums.' . $name, 'issue' => 'present in archive but not declared in ledger'];
    }
    foreach ($comparison['malformed'] ?? [] as $row) {
      $issues[] = ['field' => 'checksums.sha256[' . ($row['line'] ?? '?') . ']', 'issue' => (string) ($row['reason'] ?? 'malformed')];
    }
    $status = !empty($comparison['ok']) ? self::STATUS_VERIFIED : self::STATUS_MISMATCHED;
    $errorCode = '';
    if ($status !== self::STATUS_VERIFIED) {
      // Distinguish "the ledger itself is unreadable or smuggles a path out of
      // the archive" from "a byte changed" - a partner fixing a bad export
      // needs a different signal than one auditing a tampered package.
      $errorCode = ($comparison['malformed'] ?? []) !== [] ? self::ERR_INVALID : self::ERR_MISMATCH;
    }
    return [
      'status' => $status,
      'algorithm' => self::ALGORITHM,
      'transport' => 'zip',
      'ledger' => $declared,
      'archive_sha256' => $archiveDigest,
      'issues' => $issues,
      'error_code' => $errorCode,
      'verified_at' => gmdate('c'),
    ];
  }

  /**
   * Digest of the canonical form of a package document.
   *
   * Key order and whitespace are normalised away so the digest only changes
   * when the exchange content changes — which is exactly what `apply` re-checks.
   *
   * @param array<string, mixed> $package
   */
  public static function canonicalDigest(array $package): string {
    return hash(self::ALGORITHM, self::canonicalJson($package));
  }

  /**
   * The authoritative content digest of a registered package.
   *
   * Registration stores resources in envelope shape (`type` / `external_id` /
   * `payload`), which is what apply later replays, so the digest is taken over
   * exactly that shape and can be recomputed from state at any time.
   *
   * @param array<string, mixed> $manifest
   * @param list<array<string, mixed>> $resources
   */
  public static function contentDigest(array $manifest, array $resources): string {
    // The digest embedded by exportZip must never feed back into its own input,
    // otherwise re-registering an exported archive would change its identity.
    unset($manifest['content_sha256']);
    return self::canonicalDigest(['manifest' => $manifest, 'resources' => $resources]);
  }

  /**
   * Digest of the archive bytes as delivered (transport integrity).
   */
  public static function archiveDigest(string $bytes): string {
    return hash(self::ALGORITHM, $bytes);
  }

  /**
   * @param array<string, mixed> $package
   */
  public static function canonicalJson(array $package): string {
    $json = json_encode(self::canonicalize($package), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $json === FALSE ? '' : $json;
  }

  /**
   * The apply-time guard: may this package be ingested?
   *
   * Returns NULL when the package passes, otherwise the stable error code plus
   * a human-readable issue. Packages predating G3 (no integrity record) and
   * inline JSON registrations keep flowing, so the present site is not locked
   * out by the new requirement.
   *
   * @param array<string, mixed>|NULL $integrity
   *
   * @return array{code: string, message: string}|NULL
   */
  public static function applyGuard(?array $integrity, string $currentDigest = ''): ?array {
    if (!is_array($integrity)) {
      return NULL;
    }
    $status = (string) ($integrity['status'] ?? self::STATUS_LEGACY);
    if (in_array($status, [self::STATUS_INLINE, self::STATUS_LEGACY, self::STATUS_VERIFIED], TRUE)) {
      if ($status === self::STATUS_VERIFIED && $currentDigest !== '') {
        $stored = (string) ($integrity['content_sha256'] ?? '');
        if ($stored !== '' && !hash_equals($stored, $currentDigest)) {
          return [
            'code' => self::ERR_MISMATCH,
            'message' => 'Package content no longer matches its registered checksum (state was modified after registration).',
          ];
        }
      }
      return NULL;
    }
    if ($status === self::STATUS_MISSING) {
      return [
        'code' => self::ERR_MISSING,
        'message' => 'Offline package carries no ' . self::LEDGER_NAME . ' ledger; re-export it with dx:exchange-package-export.',
      ];
    }
    if ($status === self::STATUS_UNSIGNED) {
      return NULL;
    }
    return [
      'code' => in_array((string) ($integrity['error_code'] ?? ''), [self::ERR_MISMATCH, self::ERR_INVALID], TRUE)
        ? (string) $integrity['error_code']
        : self::ERR_MISMATCH,
      'message' => 'Offline package checksum verification failed (' . self::LEDGER_NAME . ').',
    ];
  }

  /**
   * A ledger must never escape the archive root or name a sibling directory.
   */
  public static function normalizeName(string $name): string {
    $name = str_replace('\\', '/', trim($name));
    $name = preg_replace('#^\./#', '', $name) ?? $name;
    return ltrim($name, '/');
  }

  /**
   * Sort-key normalisation for hashing (recursive ksort, lists preserved).
   *
   * @param mixed $value
   */
  private static function canonicalize(mixed $value): mixed {
    if (!is_array($value)) {
      return $value;
    }
    $out = [];
    foreach ($value as $key => $item) {
      $out[$key] = self::canonicalize($item);
    }
    if (array_keys($out) !== range(0, count($out) - 1)) {
      ksort($out);
    }
    return $out;
  }

}
