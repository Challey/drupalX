<?php

declare(strict_types=1);

namespace Drupal\dx_channel\Service;

use Drupal\Core\State\StateInterface;

/**
 * DXEP Exchange package registry + apply (DE4).
 *
 * Packages are JSON documents; offline ZIP wraps the same payload as package.json.
 */
final class ExchangeService {

  public const PACKAGES_KEY = 'dx_channel.exchange_packages';
  public const CHANGES_KEY = 'dx_channel.exchange_changes';
  public const ZIP_INNER = 'package.json';

  public function __construct(
    private readonly StateInterface $state,
    private readonly IngestService $ingest,
    private readonly WebhookService $webhooks,
  ) {}

  /**
   * @return list<array<string, mixed>>
   */
  public function listPackages(): array {
    $all = $this->state->get(self::PACKAGES_KEY, []);
    if (!is_array($all)) {
      return [];
    }
    $out = [];
    foreach ($all as $pkg) {
      if (!is_array($pkg)) {
        continue;
      }
      $out[] = $this->publicView($pkg);
    }
    usort($out, static fn(array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
    return $out;
  }

  /**
   * @return array<string, mixed>|null
   */
  public function getPackage(string $packageId): ?array {
    $all = $this->loadAll();
    return $all[$packageId] ?? NULL;
  }

  /**
   * Register from a filesystem path (.json or .zip containing package.json).
   *
   * @return array{ok: bool, package?: array<string, mixed>, issues?: list<array<string, string>>}
   */
  public function registerFromPath(string $path): array {
    $raw = @file_get_contents($path);
    if ($raw === FALSE) {
      return ['ok' => FALSE, 'issues' => [['field' => 'path', 'issue' => 'cannot read']]];
    }
    return $this->registerFromBytes($raw, strtolower(pathinfo($path, PATHINFO_EXTENSION)));
  }

  /**
   * Decode JSON or ZIP bytes then register.
   *
   * @return array{ok: bool, package?: array<string, mixed>, issues?: list<array<string, string>>}
   */
  public function registerFromBytes(string $bytes, string $hint = ''): array {
    $body = $this->decodePackageBytes($bytes, $hint);
    if ($body === NULL) {
      return ['ok' => FALSE, 'issues' => [['field' => 'body', 'issue' => 'invalid JSON or ZIP package.json']]];
    }
    return $this->register($body);
  }

  /**
   * Build offline ZIP bytes for a registered package (package.json at root).
   */
  public function exportZip(string $packageId): ?string {
    $pkg = $this->getPackage($packageId);
    if ($pkg === NULL) {
      return NULL;
    }
    $resources = [];
    foreach ($pkg['resources'] ?? [] as $res) {
      if (!is_array($res)) {
        continue;
      }
      $payload = is_array($res['payload'] ?? NULL) ? $res['payload'] : [];
      $resources[] = array_merge($payload, [
        'type' => $res['type'] ?? ($payload['type'] ?? 'article'),
        'external_id' => $res['external_id'] ?? ($payload['external_id'] ?? ''),
      ]);
    }
    $doc = [
      'manifest' => $pkg['manifest'] ?? [],
      'resources' => $resources,
    ];
    $json = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === FALSE) {
      return NULL;
    }
    if (!class_exists(\ZipArchive::class)) {
      return NULL;
    }
    $tmp = tempnam(sys_get_temp_dir(), 'dxep_zip_');
    if ($tmp === FALSE) {
      return NULL;
    }
    $zipPath = $tmp . '.zip';
    @unlink($tmp);
    $zip = new \ZipArchive();
    if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== TRUE) {
      return NULL;
    }
    $zip->addFromString(self::ZIP_INNER, $json);
    $zip->close();
    $bytes = @file_get_contents($zipPath);
    @unlink($zipPath);
    return $bytes === FALSE ? NULL : $bytes;
  }

  /**
   * @return array<string, mixed>|null
   */
  public function decodePackageBytes(string $bytes, string $hint = ''): ?array {
    $trimmed = ltrim($bytes);
    $looksZip = $hint === 'zip' || str_starts_with($bytes, "PK\x03\x04") || str_starts_with($bytes, "PK\x05\x06");
    if ($looksZip) {
      return $this->decodeZipPackage($bytes);
    }
    if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
      $decoded = json_decode($bytes, TRUE);
      return is_array($decoded) ? $decoded : NULL;
    }
    // Last resort: try ZIP then JSON.
    $fromZip = $this->decodeZipPackage($bytes);
    if ($fromZip !== NULL) {
      return $fromZip;
    }
    $decoded = json_decode($bytes, TRUE);
    return is_array($decoded) ? $decoded : NULL;
  }

  /**
   * @return array<string, mixed>|null
   */
  protected function decodeZipPackage(string $bytes): ?array {
    if (!class_exists(\ZipArchive::class)) {
      return NULL;
    }
    $tmp = tempnam(sys_get_temp_dir(), 'dxep_in_');
    if ($tmp === FALSE) {
      return NULL;
    }
    if (@file_put_contents($tmp, $bytes) === FALSE) {
      @unlink($tmp);
      return NULL;
    }
    $zip = new \ZipArchive();
    if ($zip->open($tmp) !== TRUE) {
      @unlink($tmp);
      return NULL;
    }
    $json = $zip->getFromName(self::ZIP_INNER);
    if ($json === FALSE) {
      // Accept first *.json at archive root.
      for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        if (!str_contains($name, '/') && str_ends_with(strtolower($name), '.json')) {
          $json = $zip->getFromIndex($i);
          break;
        }
      }
    }
    $zip->close();
    @unlink($tmp);
    if (!is_string($json) || $json === '') {
      return NULL;
    }
    $decoded = json_decode($json, TRUE);
    return is_array($decoded) ? $decoded : NULL;
  }

  /**
   * Register a package from JSON body.
   *
   * Expected body:
   * {
   *   "manifest": { ... },
   *   "resources": [ { "type":"article", "external_id":"...", "title":"...", "body":{...} }, ... ]
   * }
   *
   * @param array<string, mixed> $body
   *
   * @return array{ok: bool, package?: array<string, mixed>, issues?: list<array<string, string>>}
   */
  public function register(array $body): array {
    $manifest = $body['manifest'] ?? NULL;
    $resources = $body['resources'] ?? NULL;
    if (!is_array($manifest)) {
      return ['ok' => FALSE, 'issues' => [['field' => 'manifest', 'issue' => 'required']]];
    }
    if (!is_array($resources)) {
      return ['ok' => FALSE, 'issues' => [['field' => 'resources', 'issue' => 'required array']]];
    }
    if (count($resources) > 500) {
      return ['ok' => FALSE, 'issues' => [['field' => 'resources', 'issue' => 'max 500']]];
    }

    $spec = (string) ($manifest['spec'] ?? 'DXEP');
    $version = (string) ($manifest['spec_version'] ?? '1.0');
    if ($spec !== 'DXEP') {
      return ['ok' => FALSE, 'issues' => [['field' => 'manifest.spec', 'issue' => 'must be DXEP']]];
    }
    if (!in_array($version, ['1.0', '1'], TRUE)) {
      return ['ok' => FALSE, 'issues' => [['field' => 'manifest.spec_version', 'issue' => 'unsupported']]];
    }

    $packageId = trim((string) ($manifest['package_id'] ?? ''));
    if ($packageId === '') {
      $packageId = 'pkg_' . substr(bin2hex(random_bytes(8)), 0, 12);
      $manifest['package_id'] = $packageId;
    }

    $normalized = [];
    foreach ($resources as $i => $res) {
      if (!is_array($res)) {
        return ['ok' => FALSE, 'issues' => [['field' => "resources[$i]", 'issue' => 'must be object']]];
      }
      $type = (string) ($res['type'] ?? 'article');
      $externalId = trim((string) ($res['external_id'] ?? $res['id'] ?? ''));
      if ($externalId === '') {
        return ['ok' => FALSE, 'issues' => [['field' => "resources[$i].external_id", 'issue' => 'required']]];
      }
      $normalized[] = [
        'type' => $type,
        'external_id' => $externalId,
        'payload' => $res,
      ];
    }

    $pkg = [
      'package_id' => $packageId,
      'status' => 'registered',
      'created_at' => gmdate('c'),
      'manifest' => $manifest,
      'resources' => $normalized,
      'report' => NULL,
    ];

    $all = $this->loadAll();
    $all[$packageId] = $pkg;
    $this->state->set(self::PACKAGES_KEY, $all);

    return ['ok' => TRUE, 'package' => $this->publicView($pkg, TRUE)];
  }

  /**
   * Apply a registered package via Ingest upserts.
   *
   * @return array{ok: bool, package_id: string, dry_run: bool, applied: int, failed: int, report: array<string, mixed>}
   */
  public function apply(string $packageId, bool $dryRun = FALSE): array {
    $all = $this->loadAll();
    if (!isset($all[$packageId])) {
      return [
        'ok' => FALSE,
        'package_id' => $packageId,
        'dry_run' => $dryRun,
        'applied' => 0,
        'failed' => 0,
        'report' => ['error' => 'package not found'],
      ];
    }

    $pkg = $all[$packageId];
    $requireReview = !empty($pkg['manifest']['require_review']);
    if (\Drupal::moduleHandler()->moduleExists('dx_trust') && \Drupal::hasService('dx_trust.policy')) {
      $trust = \Drupal::service('dx_trust.policy')->settings();
      if (!empty($trust['require_content_review'])) {
        $requireReview = TRUE;
      }
    }
    $results = [];
    $applied = 0;
    $failed = 0;

    foreach ($pkg['resources'] as $res) {
      $type = (string) ($res['type'] ?? 'article');
      $externalId = (string) ($res['external_id'] ?? '');
      $payload = is_array($res['payload'] ?? NULL) ? $res['payload'] : [];
      // Strip envelope keys from payload for ingest.
      unset($payload['type'], $payload['external_id'], $payload['id']);
      if (!isset($payload['status'])) {
        $payload['status'] = $requireReview ? 'draft' : 'published';
      }
      $result = $this->ingest->upsert($type, $externalId, $payload, $dryRun, $requireReview);
      $entry = [
        'type' => $type,
        'external_id' => $externalId,
        'ok' => !empty($result['ok']),
        'issues' => $result['issues'] ?? [],
      ];
      $results[] = $entry;
      if (!empty($result['ok'])) {
        $applied++;
        if (!$dryRun) {
          $this->appendChange('upsert', $type, $externalId);
          $status = (string) ($payload['status'] ?? 'draft');
          if ($status === 'published' && !$requireReview) {
            $this->webhooks->dispatch('resource.published', [
              'type' => $type,
              'external_id' => $externalId,
              'title' => (string) ($payload['title'] ?? ''),
            ], (string) ($pkg['manifest']['tenant_id'] ?? 'platform'));
          }
        }
      }
      else {
        $failed++;
      }
    }

    $report = [
      'applied_at' => gmdate('c'),
      'dry_run' => $dryRun,
      'applied' => $applied,
      'failed' => $failed,
      'items' => $results,
    ];

    if (!$dryRun) {
      $pkg['status'] = $failed === 0 ? 'applied' : 'partial';
      $pkg['report'] = $report;
      $all[$packageId] = $pkg;
      $this->state->set(self::PACKAGES_KEY, $all);
    }

    return [
      'ok' => $failed === 0,
      'package_id' => $packageId,
      'dry_run' => $dryRun,
      'applied' => $applied,
      'failed' => $failed,
      'report' => $report,
    ];
  }

  /**
   * Push a batch of resources (≤100).
   *
   * @param list<array<string, mixed>> $resources
   *
   * @return array{ok: bool, applied: int, failed: int, items: list<array<string, mixed>>}
   */
  public function push(array $resources, bool $dryRun = FALSE, bool $review = FALSE): array {
    if (count($resources) > 100) {
      return [
        'ok' => FALSE,
        'applied' => 0,
        'failed' => count($resources),
        'items' => [['field' => 'resources', 'issue' => 'max 100']],
      ];
    }
    $items = [];
    $applied = 0;
    $failed = 0;
    foreach ($resources as $res) {
      if (!is_array($res)) {
        $failed++;
        $items[] = ['ok' => FALSE, 'issue' => 'not object'];
        continue;
      }
      $type = (string) ($res['type'] ?? 'article');
      $externalId = trim((string) ($res['external_id'] ?? $res['id'] ?? ''));
      if ($externalId === '') {
        $failed++;
        $items[] = ['ok' => FALSE, 'issue' => 'external_id required'];
        continue;
      }
      $payload = $res;
      unset($payload['type'], $payload['external_id'], $payload['id']);
      $result = $this->ingest->upsert($type, $externalId, $payload, $dryRun, $review);
      $ok = !empty($result['ok']);
      $items[] = [
        'type' => $type,
        'external_id' => $externalId,
        'ok' => $ok,
        'issues' => $result['issues'] ?? [],
      ];
      if ($ok) {
        $applied++;
        if (!$dryRun) {
          $this->appendChange('upsert', $type, $externalId);
        }
      }
      else {
        $failed++;
      }
    }
    return [
      'ok' => $failed === 0,
      'applied' => $applied,
      'failed' => $failed,
      'items' => $items,
    ];
  }

  /**
   * Incremental change feed.
   *
   * @return list<array<string, mixed>>
   */
  public function changes(?string $updatedSince = NULL, int $limit = 100): array {
    $all = $this->state->get(self::CHANGES_KEY, []);
    if (!is_array($all)) {
      return [];
    }
    $limit = max(1, min(500, $limit));
    $out = [];
    foreach (array_reverse($all) as $chg) {
      if (!is_array($chg)) {
        continue;
      }
      if ($updatedSince !== NULL && $updatedSince !== '') {
        $at = (string) ($chg['occurred_at'] ?? '');
        if ($at !== '' && strcmp($at, $updatedSince) <= 0) {
          continue;
        }
      }
      $out[] = $chg;
      if (count($out) >= $limit) {
        break;
      }
    }
    return $out;
  }

  /**
   * @return array<string, array<string, mixed>>
   */
  protected function loadAll(): array {
    $all = $this->state->get(self::PACKAGES_KEY, []);
    return is_array($all) ? $all : [];
  }

  /**
   * @param array<string, mixed> $pkg
   *
   * @return array<string, mixed>
   */
  protected function publicView(array $pkg, bool $includeResources = FALSE): array {
    $view = [
      'package_id' => $pkg['package_id'] ?? '',
      'status' => $pkg['status'] ?? 'unknown',
      'created_at' => $pkg['created_at'] ?? '',
      'manifest' => $pkg['manifest'] ?? [],
      'resource_count' => is_array($pkg['resources'] ?? NULL) ? count($pkg['resources']) : 0,
      'report' => $pkg['report'] ?? NULL,
    ];
    if ($includeResources) {
      $view['resources'] = $pkg['resources'] ?? [];
    }
    return $view;
  }

  protected function appendChange(string $op, string $type, string $externalId): void {
    $all = $this->state->get(self::CHANGES_KEY, []);
    if (!is_array($all)) {
      $all = [];
    }
    $all[] = [
      'change_id' => 'chg_' . substr(bin2hex(random_bytes(8)), 0, 12),
      'op' => $op,
      'occurred_at' => gmdate('c'),
      'resource' => [
        'type' => $type,
        'external_id' => $externalId,
      ],
    ];
    // Cap log size.
    if (count($all) > 2000) {
      $all = array_slice($all, -2000);
    }
    $this->state->set(self::CHANGES_KEY, $all);
  }

}
