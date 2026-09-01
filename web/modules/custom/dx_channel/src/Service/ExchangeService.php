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

  /** How often one package's failed items may be retried before we stop. */
  public const MAX_REPORT_RETRIES = 5;

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
   * An offline ZIP without a verifiable `checksums.sha256` ledger is refused
   * here — before anything reaches state or Ingest — with a stable error code.
   *
   * @return array{ok: bool, package?: array<string, mixed>, issues?: list<array<string, string>>, error_code?: string}
   */
  public function registerFromBytes(string $bytes, string $hint = ''): array {
    $inspected = $this->inspectBytes($bytes, $hint);
    $body = $inspected['body'];
    if ($body === NULL) {
      return ['ok' => FALSE, 'issues' => [['field' => 'body', 'issue' => 'invalid JSON or ZIP package.json']]];
    }
    $integrity = $inspected['integrity'];
    if (($integrity['transport'] ?? '') === 'zip' && ($integrity['status'] ?? '') !== ExchangeChecksums::STATUS_VERIFIED) {
      return [
        'ok' => FALSE,
        'error_code' => (string) ($integrity['error_code'] ?? ExchangeChecksums::ERR_MISMATCH),
        'integrity' => $this->integrityView($integrity),
        'issues' => $integrity['issues'] ?? [['field' => ExchangeChecksums::LEDGER_NAME, 'issue' => 'verification failed']],
      ];
    }
    return $this->register($body, $integrity);
  }

  /**
   * Decode a package payload *and* describe how trustworthy the delivery was.
   *
   * @return array{body: array<string, mixed>|NULL, integrity: array<string, mixed>}
   */
  public function inspectBytes(string $bytes, string $hint = ''): array {
    $trimmed = ltrim($bytes);
    $looksZip = $hint === 'zip' || str_starts_with($bytes, "PK\x03\x04") || str_starts_with($bytes, "PK\x05\x06");
    if (!$looksZip && $trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
      $decoded = json_decode($bytes, TRUE);
      if (is_array($decoded)) {
        return ['body' => $decoded, 'integrity' => $this->inlineIntegrity()];
      }
    }
    $archive = $this->readArchive($bytes);
    if ($archive !== NULL) {
      return ['body' => $archive['body'], 'integrity' => $archive['integrity']];
    }
    $decoded = json_decode($bytes, TRUE);
    return [
      'body' => is_array($decoded) ? $decoded : NULL,
      'integrity' => is_array($decoded) ? $this->inlineIntegrity() : [],
    ];
  }

  /**
   * @return array{body: array<string, mixed>|NULL, integrity: array<string, mixed>}|NULL
   */
  protected function readArchive(string $bytes): ?array {
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
    $files = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
      $name = (string) $zip->getNameIndex($i);
      if ($name === '' || str_ends_with($name, '/')) {
        continue;
      }
      $contents = $zip->getFromIndex($i);
      if (is_string($contents)) {
        $files[ExchangeChecksums::normalizeName($name)] = $contents;
      }
    }
    $zip->close();
    @unlink($tmp);

    $ledgerText = (string) ($files[ExchangeChecksums::LEDGER_NAME] ?? '');
    unset($files[ExchangeChecksums::LEDGER_NAME]);
    $packageJson = $files[self::ZIP_INNER] ?? NULL;
    if (!is_string($packageJson)) {
      // Accept first root *.json, matching the historical reader.
      foreach ($files as $name => $contents) {
        if (!str_contains($name, '/') && str_ends_with(strtolower($name), '.json')) {
          $packageJson = $contents;
          break;
        }
      }
    }
    if (!is_string($packageJson) || $packageJson === '') {
      return NULL;
    }
    $decoded = json_decode($packageJson, TRUE);
    if (!is_array($decoded)) {
      return NULL;
    }

    $archiveDigest = ExchangeChecksums::archiveDigest($bytes);
    if ($ledgerText === '') {
      return [
        'body' => $decoded,
        'integrity' => [
          'status' => ExchangeChecksums::STATUS_MISSING,
          'algorithm' => ExchangeChecksums::ALGORITHM,
          'transport' => 'zip',
          'ledger' => [],
          'archive_sha256' => $archiveDigest,
          'issues' => [[
            'field' => ExchangeChecksums::LEDGER_NAME,
            'issue' => 'offline package carries no checksum ledger',
          ]],
          'error_code' => ExchangeChecksums::ERR_MISSING,
          'verified_at' => gmdate('c'),
        ],
      ];
    }
    $parsed = ExchangeChecksums::parseLedger($ledgerText);
    $comparison = ExchangeChecksums::compare($parsed['entries'], ExchangeChecksums::ledger($files), $parsed['malformed']);
    $integrity = ExchangeChecksums::integrityFromComparison($comparison, $parsed['entries'], $archiveDigest);
    return ['body' => $decoded, 'integrity' => $integrity];
  }

  /**
   * An inline JSON registration cannot be ledger-verified; it stays accepted.
   *
   * @return array<string, mixed>
   */
  protected function inlineIntegrity(): array {
    return [
      'status' => ExchangeChecksums::STATUS_INLINE,
      'algorithm' => ExchangeChecksums::ALGORITHM,
      'transport' => 'json',
      'ledger' => [],
      'archive_sha256' => '',
      'issues' => [],
      'error_code' => '',
      'verified_at' => gmdate('c'),
    ];
  }

  /**
   * Trim an integrity record down to what the API should expose.
   *
   * @param array<string, mixed> $integrity
   *
   * @return array<string, mixed>
   */
  protected function integrityView(array $integrity): array {
    $ledger = is_array($integrity['ledger'] ?? NULL) ? $integrity['ledger'] : [];
    unset($integrity['ledger']);
    $integrity['ledger_files'] = count($ledger);
    return $integrity;
  }

  /**
   * Build offline ZIP bytes for a registered package.
   *
   * The archive carries `package.json` plus a `checksums.sha256` ledger, and a
   * SHA-256 of the canonical document is embedded in the manifest so a receiver
   * can verify the content without our state (roadmap G3).
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
    $manifest = is_array($pkg['manifest'] ?? NULL) ? $pkg['manifest'] : [];
    $contentDigest = ExchangeChecksums::contentDigest(
      $manifest,
      is_array($pkg['resources'] ?? NULL) ? array_values($pkg['resources']) : [],
    );
    $manifest['content_sha256'] = $contentDigest;
    $doc = [
      'manifest' => $manifest,
      'resources' => $resources,
    ];
    $json = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === FALSE) {
      return NULL;
    }
    if (!class_exists(\ZipArchive::class)) {
      return NULL;
    }
    $ledger = ExchangeChecksums::renderLedger(
      ExchangeChecksums::ledger([self::ZIP_INNER => $json]),
      (string) ($pkg['package_id'] ?? $packageId),
    );
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
    $zip->addFromString(ExchangeChecksums::LEDGER_NAME, $ledger);
    $zip->close();
    $bytes = @file_get_contents($zipPath);
    @unlink($zipPath);
    return $bytes === FALSE ? NULL : $bytes;
  }

  /**
   * Verify an offline ZIP on disk without registering anything.
   *
   * This is the courier check: a package copied to a USB stick can be validated
   * on the target machine before anybody dares to register it.
   *
   * @return array{ok: bool, code: string, message: string, package_id: string, integrity: array<string, mixed>}
   */
  public function verifyArchive(string $path): array {
    $raw = @file_get_contents($path);
    if ($raw === FALSE) {
      return [
        'ok' => FALSE,
        'code' => 'DX.REQ.VALIDATION',
        'message' => 'cannot read ' . $path,
        'package_id' => '',
        'integrity' => [],
      ];
    }
    $inspected = $this->inspectBytes($raw, strtolower((string) pathinfo($path, PATHINFO_EXTENSION)));
    $integrity = $inspected['integrity'];
    $body = $inspected['body'];
    if ($body === NULL) {
      return [
        'ok' => FALSE,
        'code' => ExchangeChecksums::ERR_INVALID,
        'message' => 'no readable package.json inside the archive',
        'package_id' => '',
        'integrity' => $this->integrityView($integrity),
      ];
    }
    $manifest = is_array($body['manifest'] ?? NULL) ? $body['manifest'] : [];
    $status = (string) ($integrity['status'] ?? ExchangeChecksums::STATUS_UNSIGNED);
    if (($integrity['transport'] ?? '') !== 'zip') {
      return [
        'ok' => FALSE,
        'code' => ExchangeChecksums::ERR_MISSING,
        'message' => 'not an offline ZIP package (inline JSON has no ledger)',
        'package_id' => (string) ($manifest['package_id'] ?? ''),
        'integrity' => $this->integrityView($integrity),
      ];
    }
    return [
      'ok' => $status === ExchangeChecksums::STATUS_VERIFIED,
      'code' => $status === ExchangeChecksums::STATUS_VERIFIED ? 'DX.OK' : (string) ($integrity['error_code'] ?? ExchangeChecksums::ERR_MISMATCH),
      'message' => $status === ExchangeChecksums::STATUS_VERIFIED
        ? 'ledger matches all ' . count(is_array($integrity['ledger'] ?? NULL) ? $integrity['ledger'] : []) . ' archived file(s)'
        : 'ledger verification failed (' . $status . ')',
      'package_id' => (string) ($manifest['package_id'] ?? ''),
      'content_sha256' => (string) ($manifest['content_sha256'] ?? ''),
      'integrity' => $this->integrityView($integrity),
    ];
  }

  /**
   * @return array<string, mixed>|null
   */
  public function decodePackageBytes(string $bytes, string $hint = ''): ?array {
    return $this->inspectBytes($bytes, $hint)['body'];
  }

  /**
   * @return array<string, mixed>|null
   */
  protected function decodeZipPackage(string $bytes): ?array {
    return $this->readArchive($bytes)['body'] ?? NULL;
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
   * @param array<string, mixed> $integrity
   *   Delivery integrity as produced by inspectBytes(); empty means an inline
   *   JSON registration, which has no archive to verify.
   *
   * @return array{ok: bool, package?: array<string, mixed>, issues?: list<array<string, string>>, error_code?: string}
   */
  public function register(array $body, array $integrity = []): array {
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
      'integrity' => $this->sealIntegrity($integrity, $manifest, $normalized),
    ];

    $all = $this->loadAll();
    $all[$packageId] = $pkg;
    $this->state->set(self::PACKAGES_KEY, $all);

    return ['ok' => TRUE, 'package' => $this->publicView($pkg, TRUE)];
  }

  /**
   * Attach the content digest of the stored shape to an integrity record.
   *
   * @param array<string, mixed> $integrity
   * @param array<string, mixed> $manifest
   * @param list<array<string, mixed>> $resources
   *
   * @return array<string, mixed>
   */
  protected function sealIntegrity(array $integrity, array $manifest, array $resources): array {
    if ($integrity === []) {
      $integrity = $this->inlineIntegrity();
    }
    $integrity['content_sha256'] = ExchangeChecksums::contentDigest($manifest, $resources);
    $integrity += [
      'status' => ExchangeChecksums::STATUS_INLINE,
      'algorithm' => ExchangeChecksums::ALGORITHM,
      'transport' => 'json',
      'ledger' => [],
      'issues' => [],
      'error_code' => '',
    ];
    return $integrity;
  }

  /**
   * Apply a registered package via Ingest upserts.
   *
   * Guarded by the checksum sealed at registration (roadmap G3): a package whose
   * stored content no longer matches its registered digest, or whose offline
   * ledger failed, is refused before a single resource reaches Ingest.
   *
   * @param bool $dryRun
   * @param array<string, mixed> $options
   *   only: list of `type:external_id` keys to replay (others keep their stored
   *   row); page / page_size: slice the *returned* per-item rows, counters always
   *   describe the whole run.
   *
   * @return array{ok: bool, package_id: string, dry_run: bool, applied: int, failed: int, report: array<string, mixed>, error_code?: string}
   */
  public function apply(string $packageId, bool $dryRun = FALSE, array $options = []): array {
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
    $refusal = $this->verifyPackage($packageId, $pkg);
    if ($refusal !== NULL) {
      return [
        'ok' => FALSE,
        'package_id' => $packageId,
        'dry_run' => $dryRun,
        'applied' => 0,
        'failed' => is_array($pkg['resources'] ?? NULL) ? count($pkg['resources']) : 0,
        'error_code' => $refusal['code'],
        'report' => [
          'error' => $refusal['message'],
          'code' => $refusal['code'],
          'integrity' => $this->integrityView(is_array($pkg['integrity'] ?? NULL) ? $pkg['integrity'] : []),
        ],
      ];
    }

    $only = NULL;
    if (isset($options['only']) && is_array($options['only']) && $options['only'] !== []) {
      $only = [];
      foreach ($options['only'] as $key) {
        $only[ExchangeReport::key(...self::splitKey((string) $key))] = TRUE;
      }
    }

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
    $skipped = 0;

    foreach ($pkg['resources'] as $res) {
      $type = (string) ($res['type'] ?? 'article');
      $externalId = (string) ($res['external_id'] ?? '');
      if ($only !== NULL && !isset($only[ExchangeReport::key($type, $externalId)])) {
        $skipped++;
        continue;
      }
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
    if ($only !== NULL) {
      // A replay run only carries the rows it touched; fold them into the
      // stored report so the package keeps one row per resource (idempotent).
      $report['replay_of'] = (string) ($pkg['report']['applied_at'] ?? '');
    }

    if (!$dryRun) {
      $stored = is_array($pkg['report'] ?? NULL) ? $pkg['report'] : [];
      if ($stored !== []) {
        // Fold this run into the stored report: exactly one row per resource,
        // newest outcome wins, so a replay never grows the report.
        $report = ExchangeReport::mergeReports($stored, $report);
      }
      $counts = ExchangeReport::summarize(ExchangeReport::items($report));
      if ($only === NULL) {
        // A full re-apply re-attempts every row, so it must not burn the retry
        // budget that `retryFailed()` guards with.
        $report['retry_count'] = (int) ($stored['retry_count'] ?? 0);
      }
      $pkg['status'] = $counts['failed'] === 0 ? 'applied' : 'partial';
      $pkg['report'] = $report;
      $all[$packageId] = $pkg;
      $this->state->set(self::PACKAGES_KEY, $all);
      $applied = $counts['applied'];
      $failed = $counts['failed'];
    }

    $view = ExchangeReport::paginate(
      $report,
      max(1, (int) ($options['page'] ?? 1)),
      max(1, (int) ($options['page_size'] ?? ExchangeReport::MAX_PAGE_SIZE)),
    );
    if ($only !== NULL) {
      $view['replayed'] = count($results);
    }
    if ($skipped > 0) {
      $view['skipped'] = $skipped;
    }

    return [
      'ok' => $failed === 0,
      'package_id' => $packageId,
      'dry_run' => $dryRun,
      'applied' => $applied,
      'failed' => $failed,
      'report' => $view,
    ];
  }

  /**
   * Re-apply the rows that failed last time, folding results into the report.
   *
   * Retrying is safe on a partially applied package: already-applied resources
   * are not replayed, and Ingest addresses resources by `type:external_id`, so a
   * repeated key updates the same node instead of creating another one.
   *
   * @return array{ok: bool, package_id: string, dry_run: bool, applied: int, failed: int, report: array<string, mixed>, error_code?: string, retry_blocked?: bool}
   */
  public function retryFailed(string $packageId, bool $dryRun = FALSE, int $maxRetries = self::MAX_REPORT_RETRIES): array {
    $pkg = $this->getPackage($packageId);
    if ($pkg === NULL) {
      return [
        'ok' => FALSE,
        'package_id' => $packageId,
        'dry_run' => $dryRun,
        'applied' => 0,
        'failed' => 0,
        'report' => ['error' => 'package not found'],
      ];
    }
    $report = is_array($pkg['report'] ?? NULL) ? $pkg['report'] : [];
    if ($report === []) {
      return [
        'ok' => FALSE,
        'package_id' => $packageId,
        'dry_run' => $dryRun,
        'applied' => 0,
        'failed' => 0,
        'report' => ['error' => 'package has no apply report yet; run dx:exchange-package-apply first'],
      ];
    }
    $failed = ExchangeReport::failedItems($report);
    if ($failed === []) {
      return [
        'ok' => TRUE,
        'package_id' => $packageId,
        'dry_run' => $dryRun,
        'applied' => (int) ($report['applied'] ?? 0),
        'failed' => 0,
        'report' => ExchangeReport::paginate($report, 1, ExchangeReport::MAX_PAGE_SIZE) + ['replayed' => 0],
      ];
    }
    if (ExchangeReport::retriesExhausted($report, $maxRetries)) {
      return [
        'ok' => FALSE,
        'package_id' => $packageId,
        'dry_run' => $dryRun,
        'applied' => (int) ($report['applied'] ?? 0),
        'failed' => (int) ($report['failed'] ?? count($failed)),
        'retry_blocked' => TRUE,
        'error_code' => 'DX.EXCHANGE.RETRY_EXHAUSTED',
        'report' => ExchangeReport::paginate($report, 1, ExchangeReport::MAX_PAGE_SIZE) + [
          'replay_blocked' => 'retry limit reached (' . $maxRetries . '); inspect the failing payloads or raise MAX_REPORT_RETRIES',
        ],
      ];
    }
    $keys = array_map(static fn(array $row): string => $row['key'], $failed);
    $result = $this->apply($packageId, $dryRun, ['only' => $keys]);
    if (($result['report']['error'] ?? '') !== '') {
      return $result;
    }
    $result['failed_ids'] = $keys;
    return $result;
  }

  /**
   * The stored apply report, paged.
   *
   * @return array<string, mixed>|NULL
   */
  public function report(string $packageId, int $page = 1, int $pageSize = ExchangeReport::DEFAULT_PAGE_SIZE): ?array {
    $pkg = $this->getPackage($packageId);
    if ($pkg === NULL) {
      return NULL;
    }
    $report = is_array($pkg['report'] ?? NULL) ? $pkg['report'] : [];
    $report['package_id'] = (string) ($pkg['package_id'] ?? $packageId);
    $report['package_status'] = (string) ($pkg['status'] ?? '');
    $report['failed_items'] = ExchangeReport::failedItems($report);
    return ExchangeReport::paginate($report, $page, $pageSize);
  }

  /**
   * Re-verify a stored package against the checksum sealed at registration.
   *
   * @param array<string, mixed>|NULL $pkg
   *
   * @return array{code: string, message: string}|NULL
   */
  public function verifyPackage(string $packageId, ?array $pkg = NULL): ?array {
    $pkg ??= $this->getPackage($packageId);
    if ($pkg === NULL) {
      return ['code' => 'DX.RES.NOT_FOUND', 'message' => 'Package not found'];
    }
    $integrity = is_array($pkg['integrity'] ?? NULL) ? $pkg['integrity'] : NULL;
    $resources = is_array($pkg['resources'] ?? NULL) ? array_values($pkg['resources']) : [];
    $current = ExchangeChecksums::contentDigest(
      is_array($pkg['manifest'] ?? NULL) ? $pkg['manifest'] : [],
      $resources,
    );
    return ExchangeChecksums::applyGuard($integrity, $current);
  }

  /**
   * Integrity summary of a registered package (no ledger dump).
   *
   * @return array<string, mixed>|NULL
   */
  public function integrity(string $packageId): ?array {
    $pkg = $this->getPackage($packageId);
    if ($pkg === NULL) {
      return NULL;
    }
    $integrity = is_array($pkg['integrity'] ?? NULL) ? $pkg['integrity'] : [];
    $resources = is_array($pkg['resources'] ?? NULL) ? array_values($pkg['resources']) : [];
    $current = ExchangeChecksums::contentDigest(
      is_array($pkg['manifest'] ?? NULL) ? $pkg['manifest'] : [],
      $resources,
    );
    $view = $this->integrityView($integrity);
    $view['current_content_sha256'] = $current;
    $refusal = ExchangeChecksums::applyGuard($integrity, $current);
    $view['apply_allowed'] = $refusal === NULL;
    $view['apply_error_code'] = $refusal['code'] ?? '';
    return $view;
  }

  /**
   * Split a `type:external_id` key back into its two halves.
   *
   * @return array{0: string, 1: string}
   */
  public static function splitKey(string $key): array {
    $pos = strpos($key, ':');
    if ($pos === FALSE) {
      return ['article', $key];
    }
    return [substr($key, 0, $pos) ?: 'article', substr($key, $pos + 1)];
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
      'integrity' => $this->integrityView(is_array($pkg['integrity'] ?? NULL) ? $pkg['integrity'] : []),
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
