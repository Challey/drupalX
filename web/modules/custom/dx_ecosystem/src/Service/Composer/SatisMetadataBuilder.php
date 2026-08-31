<?php

declare(strict_types=1);

namespace Drupal\dx_ecosystem\Service\Composer;

/**
 * Builds Satis-shaped Composer metadata documents (pure, no filesystem access).
 *
 * The layout is the one `composer/composer`'s ComposerRepository accepts and
 * the one Satis emits for a small curated set:
 *
 *  - a root `packages.json` carrying the inline `packages` map, so even a client
 *    that ignores the provider files resolves the catalog,
 *  - `provider-includes` pointing at one JSON file per package with the sha256
 *    of that file, which is what lets a real Satis/Artifactory mirror grow to
 *    thousands of packages without a giant root document,
 *  - `providers-url` as the dynamic lookup endpoint this module serves itself.
 *
 * Inline data and provider files intentionally describe the same versions: a
 * provider file whose payload differs from the inline entry is a build bug, and
 * ::fingerprint() is what the builder compares.
 */
final class SatisMetadataBuilder {

  public const ROOT_FILE = 'packages.json';

  public const PROVIDER_PREFIX = 'provider-';

  public const PROVIDER_SUFFIX = '.json';

  /** Composer repository format marker understood by composer v1 and v2. */
  public const FORMAT_VERSION = '1';

  public const CODE_OK = 'DX.L2.META_OK';
  public const CODE_NAME_INVALID = 'DX.L2.META_NAME_INVALID';
  public const CODE_NO_VERSIONS = 'DX.L2.META_NO_VERSIONS';
  public const CODE_VERSION_EMPTY = 'DX.L2.META_VERSION_EMPTY';
  public const CODE_NO_DIST = 'DX.L2.META_DIST_MISSING';
  public const CODE_DIST_UNREADABLE = 'DX.L2.META_DIST_UNREADABLE';

  /**
   * Validates one manifest package entry and returns its Composer constraint.
   *
   * @param array<string, mixed> $package
   *
   * @return array{ok: bool, code: string, name: string, constraint: array<string, mixed>, messages: list<string>}
   */
  public static function packageConstraint(array $package, array $options = []): array {
    $name = self::normalizeName((string) ($package['name'] ?? ''));
    $messages = [];
    if (!self::isValidName($name)) {
      return [
        'ok' => FALSE,
        'code' => self::CODE_NAME_INVALID,
        'name' => $name,
        'constraint' => [],
        'messages' => ['包名必须是 vendor/package 形式：' . $name],
      ];
    }
    $constraint = [];
    $versions = is_array($package['versions'] ?? NULL) ? $package['versions'] : [];
    foreach ($versions as $raw) {
      if (!is_array($raw)) {
        continue;
      }
      $version = ltrim(trim((string) ($raw['version'] ?? '')), 'v');
      if ($version === '') {
        $messages[] = $name . ' 存在空版本号，已跳过';
        continue;
      }
      $constraint[$version] = self::versionData($name, $version, $raw, $options);
    }
    if ($constraint === []) {
      $messages[] = $name . ' 没有任何可用版本';
      return [
        'ok' => FALSE,
        'code' => self::CODE_NO_VERSIONS,
        'name' => $name,
        'constraint' => [],
        'messages' => $messages,
      ];
    }
    return [
      'ok' => TRUE,
      'code' => self::CODE_OK,
      'name' => $name,
      'constraint' => $constraint,
      'messages' => $messages,
    ];
  }

  /**
   * One version entry of the inline `packages` map.
   *
   * @param array<string, mixed> $raw
   * @param array<string, mixed> $options
   *
   * @return array<string, mixed>
   */
  public static function versionData(string $name, string $version, array $raw, array $options = []): array {
    [$vendor, $pkg] = explode('/', $name, 2);
    $data = [
      'name' => $name,
      'version' => $version,
      'description' => (string) ($raw['description'] ?? $options['description'] ?? ''),
      'type' => (string) ($raw['type'] ?? 'drupal-module'),
      'license' => (string) ($raw['license'] ?? $options['license'] ?? 'proprietary'),
    ];
    $require = is_array($raw['require'] ?? NULL) ? $raw['require'] : [];
    if ($require !== []) {
      $data['require'] = $require;
    }
    $autoload = is_array($raw['autoload'] ?? NULL) ? $raw['autoload'] : [];
    if ($autoload !== []) {
      $data['autoload'] = $autoload;
    }
    $extra = is_array($raw['extra'] ?? NULL) ? $raw['extra'] : [];
    if ($extra !== []) {
      $data['extra'] = $extra;
    }
    $distPath = trim((string) ($raw['dist_file'] ?? ''));
    if ($distPath === '') {
      $distPath = self::defaultDistPath($name, $version);
    }
    $distPath = ltrim(str_replace('\\', '/', $distPath), '/');
    $file = trim((string) ($raw['dist_name'] ?? basename($distPath)));
    if ($file === '') {
      $file = self::defaultDistFile($name, $version);
    }
    // `dist_url` is a prefix that already ends with the repository's dist dir
    // (see L2RepositoryBuilder::build()), while $distPath is repo-relative and
    // starts with that same dir - concatenate them or every version would be
    // handed the very same download URL.
    $url = self::distUrl((string) ($options['dist_url'] ?? ''), $distPath);
    $dist = [
      'type' => 'zip',
      'url' => $url,
      'reference' => (string) ($raw['reference'] ?? $version),
    ];
    $shasum = strtolower(trim((string) ($raw['sha1'] ?? '')));
    if ($shasum !== '') {
      $dist['shasum'] = $shasum;
    }
    $data['dist'] = $dist;
    $sourceUrl = trim((string) ($raw['source_url'] ?? ''));
    if ($sourceUrl !== '') {
      $data['source'] = [
        'type' => 'git',
        'url' => $sourceUrl,
        'reference' => (string) ($raw['reference'] ?? $version),
      ];
    }
    $data['time'] = (string) ($raw['time'] ?? '');
    if (trim($data['time']) === '') {
      unset($data['time']);
    }
    $data['extra']['drupalx'] = array_merge([
      'module' => (string) ($raw['module'] ?? $pkg),
      'vendor' => $vendor,
      'layer' => 'L2',
      'artifact' => $distPath,
    ], is_array($extra['drupalx'] ?? NULL) ? $extra['drupalx'] : []);
    return $data;
  }

  /**
   * The root packages.json document for a whole catalog.
   *
   * @param array<string, array<string, mixed>> $packages
   *   Normalized name => version constraint map (see ::packageConstraint()).
   * @param array<string, mixed> $options
   *   Keys: provider_files (name=>file), providers_url, base_url, generated,
   *   driver, provider_flags (must match how the provider files are written),
   *   provider_includes (bool, default TRUE - set FALSE when the provider files
   *   are not fetchable by name, i.e. a served root with only providers-url).
   *
   * @return array<string, mixed>
   */
  public static function rootDocument(array $packages, array $options = []): array {
    ksort($packages);
    // Composer verifies a provider file against the sha256 written here, so the
    // fingerprint has to be taken over the exact bytes that end up on disk.
    $providerFlags = (int) ($options['provider_flags'] ?? 0);
    $includes = [];
    // Composer downloads every provider-includes entry as a real file and
    // verifies its sha256, so this may only list files that actually exist.
    if (($options['provider_includes'] ?? TRUE) !== FALSE) {
      foreach ($packages as $name => $versions) {
        $file = (string) (($options['provider_files'][$name] ?? NULL) ?: self::providerFileName($name));
        $json = self::encode(self::providerDocument([$name => $versions], $options), $providerFlags);
        $includes[$file] = ['sha256' => self::fingerprint($json)];
      }
    }
    $doc = [
      'packages' => $packages,
      'providers-url' => (string) ($options['providers_url'] ?? '/' . ComposerHostPlan::SERVE_PREFIX . '/providers/%package%.json'),
      'minified' => self::minifiedValue(),
    ];
    if ($includes !== []) {
      $doc['provider-includes'] = $includes;
    }
    $doc['metadata-source'] = [
      'driver' => (string) ($options['driver'] ?? ComposerHostPlan::DRIVER_SATIS),
      'generated' => (int) ($options['generated'] ?? 0),
      'repository' => (string) ($options['base_url'] ?? ''),
      'drupalx' => self::FORMAT_VERSION,
    ];
    return $doc;
  }

  /**
   * A per-package provider document (the payload of one provider file).
   *
   * @param array<string, array<string, mixed>> $packages
   * @param array<string, mixed> $options
   *
   * @return array<string, mixed>
   */
  public static function providerDocument(array $packages, array $options = []): array {
    ksort($packages);
    $providers = [];
    foreach ($packages as $name => $versions) {
      $providers[$name] = self::fingerprint(self::encode($versions));
    }
    return [
      'packages' => $packages,
      'providers' => $providers,
    ];
  }

  /**
   * provider-<vendor>-<package>-<hash>.json, Satis style but filesystem safe.
   */
  public static function providerFileName(string $name): string {
    $name = self::normalizeName($name);
    $slug = str_replace(['/', '@'], ['-', ''], $name);
    $slug = (string) preg_replace('/[^a-z0-9._-]+/', '-', $slug);
    return self::PROVIDER_PREFIX . $slug . '-' . substr(hash('sha256', $name), 0, 10) . self::PROVIDER_SUFFIX;
  }

  /**
   * The `%package%` placeholder value Composer substitutes (urlencoded name).
   */
  public static function providerKey(string $name): string {
    return str_replace('/', '%2F', self::normalizeName($name));
  }

  /**
   * Decodes a `%package%` route value back into "vendor/package".
   */
  public static function nameFromProviderKey(string $key): string {
    $key = rawurldecode(trim($key));
    $key = str_replace(['%2F', '\0'], ['/', ''], strtolower($key));
    return self::normalizeName($key);
  }

  /**
   * Absolute download URL of one artifact, given the repository base URL.
   *
   * A base that already ends with the dist directory is accepted too (that is
   * how the artifact tree is addressed from the outside), but the artifact's
   * repository-relative path always wins over it.
   */
  public static function distUrl(string $prefix, string $distPath): string {
    $prefix = rtrim(trim($prefix), '/');
    $distPath = ltrim(str_replace('\\', '/', $distPath), '/');
    if ($prefix === '') {
      return $distPath;
    }
    if (str_ends_with($prefix, '/dist') && str_starts_with($distPath, 'dist/')) {
      $prefix = substr($prefix, 0, -5);
    }
    return $prefix . '/' . $distPath;
  }

  /**
   * Repository-relative artifact path for one version.
   */
  public static function defaultDistPath(string $name, string $version): string {
    return 'dist/' . self::normalizeName($name) . '/' . rawurlencode($version) . '/' . self::defaultDistFile($name, $version);
  }

  public static function defaultDistFile(string $name, string $version): string {
    $name = str_replace(['/', '@'], ['-', ''], self::normalizeName($name));
    return $name . '-' . ltrim($version, 'v') . '.zip';
  }

  /**
   * "vendor/package" lowercased, or the trimmed input when it has no slash.
   */
  public static function normalizeName(string $name): string {
    return strtolower(trim(str_replace('\\', '/', $name)));
  }

  /**
   * Composer package names: two slash-separated segments of safe characters.
   */
  public static function isValidName(string $name): bool {
    return (bool) preg_match('#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9]([_.-]?[a-z0-9]+)*$#', $name);
  }

  /**
   * Canonical JSON: no escaping of slashes/unicode, no trailing whitespace.
   */
  public static function encode(array $document, int $flags = 0): string {
    $flags |= JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    return (string) json_encode($document, $flags);
  }

  /**
   * sha256 of a payload, the form `provider-includes` expects.
   */
  public static function fingerprint(string $payload): string {
    return hash('sha256', $payload);
  }

  /**
   * Stable issue list for a manifest, used by Drush lint and the pure tests.
   *
   * @param list<array<string, mixed>> $packages
   *
   * @return list<array{name: string, code: string, message: string}>
   */
  public static function lint(array $packages): array {
    $issues = [];
    foreach ($packages as $package) {
      if (!is_array($package)) {
        continue;
      }
      $result = self::packageConstraint($package);
      foreach ($result['messages'] as $message) {
        $issues[] = [
          'name' => $result['name'],
          'code' => $result['code'],
          'message' => $message,
        ];
      }
    }
    return $issues;
  }

  /**
   * Composer expects `"minified": "true"` in Satis output; keep it in one place.
   */
  private static function minifiedValue(): string {
    return 'true';
  }

}
