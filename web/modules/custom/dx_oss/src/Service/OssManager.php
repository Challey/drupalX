<?php

declare(strict_types=1);

namespace Drupal\dx_oss\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use GuzzleHttp\ClientInterface;

/**
 * OSS/COS credential storage and connectivity checks.
 */
class OssManager {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected StateInterface $state,
    protected ClientInterface $httpClient,
    protected LoggerChannelInterface $logger,
  ) {}

  /**
   * Provider checklist for UI / Drush.
   *
   * @return list<array{id: string, label: string, done: bool, detail: string}>
   */
  public function checklist(): array {
    $config = $this->configFactory->get('dx_oss.settings');
    $provider = (string) ($config->get('provider') ?: 'aliyun');
    $access = (string) ($this->state->get('dx_oss.access_key') ?: '');
    $secret = (string) ($this->state->get('dx_oss.secret_key') ?: '');
    $bucket = trim((string) ($config->get('bucket') ?: ''));
    $endpoint = trim((string) ($config->get('endpoint') ?: ''));

    return [
      [
        'id' => 'provider',
        'label' => 'Select provider',
        'done' => in_array($provider, ['aliyun', 'tencent'], TRUE),
        'detail' => $provider,
      ],
      [
        'id' => 'credentials',
        'label' => 'Access key + secret stored',
        'done' => $access !== '' && $secret !== '',
        'detail' => $access !== '' ? 'access key set' : 'missing',
      ],
      [
        'id' => 'bucket',
        'label' => 'Bucket + endpoint',
        'done' => $bucket !== '' && $endpoint !== '',
        'detail' => trim($bucket . ' @ ' . $endpoint),
      ],
      [
        'id' => 'enabled',
        'label' => 'Pack marked enabled',
        'done' => (bool) $config->get('enabled'),
        'detail' => $config->get('enabled') ? 'enabled' : 'disabled',
      ],
      [
        'id' => 'stream',
        'label' => 'Stream wrapper module present (optional)',
        'done' => \Drupal::moduleHandler()->moduleExists('s3fs')
          || \Drupal::moduleHandler()->moduleExists('flysystem'),
        'detail' => 'Install via App Store / Composer when ready (s3fs or flysystem)',
      ],
    ];
  }

  /**
   * Saves credentials into State.
   */
  public function setCredentials(string $accessKey, string $secretKey): void {
    if ($accessKey !== '') {
      $this->state->set('dx_oss.access_key', $accessKey);
    }
    if ($secretKey !== '') {
      $this->state->set('dx_oss.secret_key', $secretKey);
    }
  }

  /**
   * Lightweight endpoint reachability check (HEAD/GET).
   *
   * @return array{ok: bool, message: string}
   */
  public function testConnection(): array {
    $config = $this->configFactory->get('dx_oss.settings');
    $endpoint = rtrim((string) ($config->get('endpoint') ?: ''), '/');
    $bucket = trim((string) ($config->get('bucket') ?: ''));
    if ($endpoint === '' || $bucket === '') {
      return ['ok' => FALSE, 'message' => 'Bucket and endpoint are required.'];
    }
    if (!$this->state->get('dx_oss.access_key') || !$this->state->get('dx_oss.secret_key')) {
      return ['ok' => FALSE, 'message' => 'Access key / secret key missing.'];
    }

    // Endpoint formats vary; try the configured endpoint host first.
    $url = $endpoint;
    if (!str_contains($endpoint, $bucket)) {
      $url = preg_replace('#^https?://#', 'https://' . $bucket . '.', $endpoint) ?: $endpoint;
    }
    try {
      $response = $this->httpClient->request('GET', $url, [
        'http_errors' => FALSE,
        'timeout' => 10,
        'headers' => [
          // Real signed requests require provider SDK; this only probes DNS/TLS/HTTP.
          'User-Agent' => 'DrupalX-OSS-Pack/1.0',
        ],
      ]);
      $code = $response->getStatusCode();
      $this->logger->notice('OSS probe @url returned HTTP @code', [
        '@url' => $url,
        '@code' => $code,
      ]);
      return [
        'ok' => $code > 0 && $code < 500,
        'message' => "Probe {$url} → HTTP {$code}. Signed upload still requires s3fs/flysystem + provider SDK.",
      ];
    }
    catch (\Throwable $e) {
      return ['ok' => FALSE, 'message' => $e->getMessage()];
    }
  }

  /**
   * Marks pack enabled after checklist basics pass.
   */
  public function enablePack(): void {
    $items = $this->checklist();
    foreach ($items as $item) {
      if (in_array($item['id'], ['credentials', 'bucket', 'provider'], TRUE) && !$item['done']) {
        throw new \RuntimeException('Cannot enable: ' . $item['label']);
      }
    }
    $this->configFactory->getEditable('dx_oss.settings')->set('enabled', TRUE)->save();
  }

}
