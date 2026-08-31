<?php

declare(strict_types=1);

namespace Drupal\dx_ecosystem\Service;

use Drupal\dx_ecosystem\Service\Composer\ComposerHostPlan;
use Drupal\dx_ecosystem\Service\Composer\SatisMetadataBuilder;

/**
 * Materialises an on-disk L2 repository (the offline / loopback half of I1).
 *
 * `drush dx:ecosystem-l2-repo --build=/var/www/l2` produces exactly the tree a
 * real Satis would publish:
 *
 *   <root>/packages.json
 *   <root>/provider-drupalx-dx_oss-<hash>.json
 *   <root>/dist/drupalx/dx_oss/1.0.0/drupalx-dx_oss-1.0.0.zip
 *
 * Because the tree is static, dist urls are *not* signed here (a signature that
 * expires in 15 minutes is useless inside a committed file); the signed form is
 * produced by L2ComposerRepository on the HTTP endpoints. Pointing
 * `l2_composer_base_url` at this directory makes Composer resolve offline with
 * no network at all, which is what the CI loopback exercise uses.
 */
final class L2RepositoryBuilder {

  public const CODE_OK = 'DX.L2.BUILD_OK';
  public const CODE_DEST_INSIDE_REPO = 'DX.L2.BUILD_DEST_INSIDE_REPO';
  public const CODE_DEST_NOT_WRITABLE = 'DX.L2.BUILD_DEST_NOT_WRITABLE';
  public const CODE_MANIFEST_EMPTY = 'DX.L2.BUILD_MANIFEST_EMPTY';
  public const CODE_SRC_MISSING = 'DX.L2.BUILD_SRC_MISSING';
  public const CODE_ARTIFACT_MISSING = 'DX.L2.BUILD_ARTIFACT_MISSING';

  public function __construct(
    protected L2PackageManifest $manifest,
  ) {}

  /**
   * Builds (or refreshes) the metadata tree, optionally importing artifacts.
   *
   * @param array<string, mixed> $options
   *   Recognised: base_url, driver, generated, src, pretty, repo_root.
   *
   * @return array{
   *   ok: bool,
   *   code: string,
   *   dest: string,
   *   written: list<string>,
   *   artifacts: list<array<string, mixed>>,
   *   issues: list<array<string, mixed>>,
   *   messages: list<string>
   * }
   */
  public function build(string $dest, array $options = []): array {
    $dest = rtrim(ComposerHostPlan::pathFromUrl($dest), '/');
    $messages = [];
    $issues = [];
    if ($dest === '' || !str_starts_with($dest, '/')) {
      return $this->report(FALSE, self::CODE_DEST_NOT_WRITABLE, $dest, [], [], [], ['--build 需要一个绝对目录']);
    }
    $repoRoot = rtrim((string) ($options['repo_root'] ?? ''), '/');
    if ($repoRoot !== '' && ($dest === $repoRoot || str_starts_with($dest, $repoRoot . '/'))) {
      return $this->report(FALSE, self::CODE_DEST_INSIDE_REPO, $dest, [], [], [], [
        '拒绝把 L2 仓库建在代码库内部：' . $dest . '（会泄露进 L0 发布树）',
      ]);
    }
    if (is_dir($dest) && !is_writable($dest)) {
      return $this->report(FALSE, self::CODE_DEST_NOT_WRITABLE, $dest, [], [], [], ['目录不可写：' . $dest]);
    }
    if (!is_dir($dest) && !mkdir($dest, 0775, TRUE) && !is_dir($dest)) {
      return $this->report(FALSE, self::CODE_DEST_NOT_WRITABLE, $dest, [], [], [], ['无法创建目录：' . $dest]);
    }

    $packages = $this->manifest->packages();
    if ($packages === []) {
      return $this->report(FALSE, self::CODE_MANIFEST_EMPTY, $dest, [], [], [], ['L2 目录为空：' . $this->manifest->path()]);
    }

    $artifacts = $this->importArtifacts($dest, (string) ($options['src'] ?? ''), $messages, $issues);
    foreach ($artifacts as $artifact) {
      if (empty($artifact['imported'])) {
        $issues[] = [
          'name' => (string) $artifact['name'],
          'code' => self::CODE_ARTIFACT_MISSING,
          'message' => '产物未落地：' . (string) $artifact['path'],
        ];
      }
    }

    $fingerprints = [];
    foreach ($artifacts as $artifact) {
      if (!empty($artifact['sha1'])) {
        $fingerprints[(string) $artifact['name'] . ':' . (string) $artifact['version']] = [
          'sha1' => (string) $artifact['sha1'],
          'sha256' => (string) $artifact['sha256'],
        ];
      }
    }

    $baseUrl = rtrim((string) ($options['base_url'] ?? ComposerHostPlan::fileUrl($dest)), '/');
    $providerFlags = JSON_PRETTY_PRINT;
    $metaOptions = [
      'driver' => (string) ($options['driver'] ?? ComposerHostPlan::DRIVER_LOOPBACK),
      'base_url' => $baseUrl,
      'generated' => (int) ($options['generated'] ?? time()),
      'dist_url' => $baseUrl . '/dist/',
      // Same flags as the file write below, or packages.json would advertise a
      // sha256 that Composer cannot reproduce from the bytes on disk.
      'provider_flags' => $providerFlags,
    ];
    $constraints = $this->constraintsWithHashes($metaOptions, $fingerprints);
    $root = SatisMetadataBuilder::rootDocument($constraints, $metaOptions);

    $written = [];
    foreach ($constraints as $name => $versions) {
      $file = SatisMetadataBuilder::providerFileName($name);
      $payload = SatisMetadataBuilder::encode(SatisMetadataBuilder::providerDocument([$name => $versions]), $providerFlags);
      if ($this->writeContents($dest . '/' . $file, $payload)) {
        $written[] = $file;
      }
      else {
        $issues[] = [
          'name' => $name,
          'code' => self::CODE_DEST_NOT_WRITABLE,
          'message' => 'provider 文件写入失败：' . $file,
        ];
      }
    }
    // The root references the provider files it just wrote, so fingerprints line up.
    $root = SatisMetadataBuilder::rootDocument($constraints, $metaOptions);
    $rootJson = SatisMetadataBuilder::encode($root, !empty($options['pretty']) ? JSON_PRETTY_PRINT : 0);
    if ($this->writeContents($dest . '/' . SatisMetadataBuilder::ROOT_FILE, $rootJson)) {
      $written[] = SatisMetadataBuilder::ROOT_FILE;
    }
    else {
      $issues[] = [
        'name' => '',
        'code' => self::CODE_DEST_NOT_WRITABLE,
        'message' => 'packages.json 写入失败：' . $dest,
      ];
    }

    $ok = $issues === [];
    return $this->report(
      $ok,
      $ok ? self::CODE_OK : self::CODE_ARTIFACT_MISSING,
      $dest,
      $written,
      $artifacts,
      $issues,
      $messages,
    );
  }

  /**
   * Validates the manifest + on-disk tree without writing anything.
   *
   * @return list<array{name: string, code: string, message: string}>
   */
  public function lint(?string $root = NULL): array {
    return $this->manifest->lint($root);
  }

  /**
   * Copies declared zips from a build output dir into the repository tree.
   *
   * @return list<array<string, mixed>>
   */
  protected function importArtifacts(string $dest, string $src, array &$messages, array &$issues): array {
    $rows = $this->manifest->artifacts($dest);
    if ($src === '') {
      $messages[] = '未提供 --src：只生成元数据，不导入 zip 产物。';
      foreach ($rows as $i => $row) {
        $rows[$i]['imported'] = FALSE;
        $rows[$i]['source'] = '';
      }
      return $rows;
    }
    if (!is_dir($src)) {
      $messages[] = '--src 目录不存在：' . $src;
      $issues[] = [
        'name' => '',
        'code' => self::CODE_SRC_MISSING,
        'message' => '--src 目录不存在：' . $src,
      ];
      foreach ($rows as $i => $row) {
        $rows[$i]['imported'] = FALSE;
        $rows[$i]['source'] = $src;
      }
      return $rows;
    }
    foreach ($rows as $i => $row) {
      $file = (string) $row['path'];
      $candidate = rtrim($src, '/') . '/' . $file;
      if (!is_file($candidate)) {
        // Also accept a flat build dir: <src>/<name>-<version>.zip.
        $candidate = rtrim($src, '/') . '/' . basename($file);
      }
      $target = rtrim($dest, '/') . '/' . $file;
      $imported = FALSE;
      if (is_file($candidate)) {
        $dir = dirname($target);
        if (!is_dir($dir) && !mkdir($dir, 0775, TRUE) && !is_dir($dir)) {
          $messages[] = '无法创建 ' . $dir;
        }
        elseif ((string) sha1_file($candidate) === (string) (@sha1_file($target) ?: '')) {
          $imported = TRUE;
        }
        elseif (copy($candidate, $target)) {
          $imported = TRUE;
        }
        else {
          $messages[] = '复制失败：' . $candidate;
        }
      }
      $rows[$i]['imported'] = $imported;
      $rows[$i]['source'] = is_file($candidate) ? $candidate : '';
      $rows[$i]['exists'] = is_file($target);
      $rows[$i]['sha1'] = is_file($target) && is_readable($target) ? (string) sha1_file($target) : '';
      $rows[$i]['sha256'] = is_file($target) && is_readable($target) ? (string) hash_file('sha256', $target) : '';
      $rows[$i]['size'] = is_file($target) ? (int) filesize($target) : 0;
    }
    return $rows;
  }

  /**
   * Manifest constraints with `dist.shasum` filled from what is on disk.
   *
   * @param array<string, mixed> $metaOptions
   * @param array<string, array<string, string>> $fingerprints
   *
   * @return array<string, array<string, mixed>>
   */
  protected function constraintsWithHashes(array $metaOptions, array $fingerprints): array {
    $constraints = [];
    foreach ($this->manifest->packages() as $entry) {
      $name = (string) $entry['name'];
      $versions = [];
      foreach (is_array($entry['versions'] ?? NULL) ? $entry['versions'] : [] as $raw) {
        if (!is_array($raw)) {
          continue;
        }
        $version = ltrim(trim((string) ($raw['version'] ?? '')), 'v');
        if ($version === '') {
          continue;
        }
        $fingerprint = $fingerprints[$name . ':' . $version]['sha1'] ?? '';
        if ($fingerprint !== '') {
          $raw = ['sha1' => $fingerprint] + $raw;
        }
        $versions[$version] = SatisMetadataBuilder::versionData($name, $version, $raw, $metaOptions);
      }
      if ($versions !== []) {
        $constraints[$name] = $versions;
      }
    }
    ksort($constraints);
    return $constraints;
  }

  /**
   * Writes metadata bytes exactly as handed in.
   *
   * No trailing newline: packages.json fingerprints these files with sha256 and
   * Composer verifies the downloaded bytes against that hash.
   */
  protected function writeContents(string $path, string $payload): bool {
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, TRUE) && !is_dir($dir)) {
      return FALSE;
    }
    return file_put_contents($path, $payload) !== FALSE;
  }

  /**
   * @param list<string> $written
   * @param list<array<string, mixed>> $artifacts
   * @param list<array<string, mixed>> $issues
   * @param list<string> $messages
   *
   * @return array<string, mixed>
   */
  protected function report(bool $ok, string $code, string $dest, array $written, array $artifacts, array $issues, array $messages): array {
    return [
      'ok' => $ok,
      'code' => $code,
      'dest' => $dest,
      'written' => $written,
      'artifacts' => $artifacts,
      'issues' => $issues,
      'messages' => $messages,
    ];
  }

}
