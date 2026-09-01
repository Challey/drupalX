<?php

declare(strict_types=1);

namespace Drupal\dx_ecosystem\Service;

use Drupal\dx_ecosystem\Service\Composer\SatisMetadataBuilder;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads the L2 private package catalog (data/composer/manifest.yml).
 *
 * Same shape as PartnerDocRepository: a YAML manifest shipped with the module,
 * no entity, no database. The catalog is the *source of truth* for what a
 * partner may resolve; whether an artifact is downloadable is a separate
 * question answered by ::artifacts().
 */
final class L2PackageManifest {

  public const FILE = 'manifest.yml';

  public function dataDir(): string {
    return dirname(__DIR__, 2) . '/data/composer';
  }

  public function path(): string {
    return $this->dataDir() . '/' . self::FILE;
  }

  /**
   * Raw package entries, in file order.
   *
   * @return list<array<string, mixed>>
   */
  public function raw(): array {
    $path = $this->path();
    if (!is_readable($path)) {
      return [];
    }
    $data = Yaml::parseFile($path);
    $list = is_array($data) && is_array($data['packages'] ?? NULL) ? $data['packages'] : [];
    return array_values(array_filter($list, static fn($row): bool => is_array($row)));
  }

  /**
   * @return array<string, array<string, mixed>>
   *   Normalized composer name => entry.
   */
  public function packages(): array {
    $out = [];
    foreach ($this->raw() as $entry) {
      $name = SatisMetadataBuilder::normalizeName((string) ($entry['name'] ?? ''));
      if ($name !== '') {
        $entry['name'] = $name;
        $out[$name] = $entry;
      }
    }
    ksort($out);
    return $out;
  }

  /**
   * Version constraints for every valid package, keyed by normalized name.
   *
   * @param array<string, mixed> $options
   *   Passed through to SatisMetadataBuilder::versionData() (dist_url, …).
   *
   * @return array<string, array<string, mixed>>
   */
  public function constraints(array $options = []): array {
    $out = [];
    foreach ($this->packages() as $entry) {
      $result = SatisMetadataBuilder::packageConstraint($entry, $options);
      if ($result['ok']) {
        $out[$result['name']] = $result['constraint'];
      }
    }
    return $out;
  }

  /**
   * Manifest problems only: bad names, empty version lists, missing artifacts.
   *
   * @return list<array{name: string, code: string, message: string}>
   */
  public function lint(?string $repositoryRoot = NULL): array {
    $issues = SatisMetadataBuilder::lint($this->raw());
    if ($repositoryRoot === NULL || $repositoryRoot === '') {
      $issues[] = [
        'name' => '',
        'code' => SatisMetadataBuilder::CODE_NO_DIST,
        'message' => '未配置 l2_repository_root，无法校验 dist 产物是否存在。',
      ];
      return $issues;
    }
    foreach ($this->artifacts($repositoryRoot) as $artifact) {
      if (empty($artifact['exists'])) {
        $issues[] = [
          'name' => $artifact['name'],
          'code' => SatisMetadataBuilder::CODE_NO_DIST,
          'message' => '缺少产物 ' . $artifact['path'] . '（先跑 drush dx:ecosystem-l2-repo --build）',
        ];
      }
      elseif ($artifact['sha1'] === '') {
        $issues[] = [
          'name' => $artifact['name'],
          'code' => SatisMetadataBuilder::CODE_DIST_UNREADABLE,
          'message' => '产物不可读：' . $artifact['path'],
        ];
      }
    }
    return $issues;
  }

  /**
   * One row per declared version, with what the artifact on disk looks like.
   *
   * @return list<array<string, mixed>>
   */
  public function artifacts(string $repositoryRoot): array {
    $rows = [];
    $root = rtrim($repositoryRoot, '/');
    foreach ($this->packages() as $entry) {
      $name = (string) $entry['name'];
      foreach (is_array($entry['versions'] ?? NULL) ? $entry['versions'] : [] as $raw) {
        if (!is_array($raw)) {
          continue;
        }
        $version = ltrim(trim((string) ($raw['version'] ?? '')), 'v');
        $relative = ltrim(str_replace('\\', '/', trim((string) ($raw['dist_file'] ?? ''))), '/');
        if ($relative === '') {
          $relative = SatisMetadataBuilder::defaultDistPath($name, $version);
        }
        $path = $root . '/' . $relative;
        $exists = is_file($path);
        $rows[] = [
          'name' => $name,
          'module' => (string) ($entry['module'] ?? ''),
          'version' => $version,
          'path' => $relative,
          'absolute' => $path,
          'exists' => $exists,
          'size' => $exists ? (int) filesize($path) : 0,
          'sha1' => $exists && is_readable($path) ? (string) sha1_file($path) : '',
          'sha256' => $exists && is_readable($path) ? (string) hash_file('sha256', $path) : '',
        ];
      }
    }
    return $rows;
  }

  /**
   * Entry for one exact version, NULL when the catalog does not know it.
   *
   * @return array<string, mixed>|null
   */
  public function find(string $name, string $version): ?array {
    $entry = $this->packages()[SatisMetadataBuilder::normalizeName($name)] ?? NULL;
    if ($entry === NULL) {
      return NULL;
    }
    $want = ltrim(trim($version), 'v');
    foreach (is_array($entry['versions'] ?? NULL) ? $entry['versions'] : [] as $raw) {
      if (is_array($raw) && ltrim(trim((string) ($raw['version'] ?? '')), 'v') === $want) {
        return $raw + ['name' => (string) $entry['name'], 'module' => (string) ($entry['module'] ?? '')];
      }
    }
    return NULL;
  }

  /**
   * Every version this catalog can hand out, as "vendor/pkg:1.2.3" strings.
   *
   * @return list<string>
   */
  public function versionIndex(): array {
    $out = [];
    foreach ($this->packages() as $entry) {
      foreach (is_array($entry['versions'] ?? NULL) ? $entry['versions'] : [] as $raw) {
        if (is_array($raw)) {
          $out[] = $entry['name'] . ':' . ltrim((string) ($raw['version'] ?? ''), 'v');
        }
      }
    }
    sort($out);
    return $out;
  }

}
