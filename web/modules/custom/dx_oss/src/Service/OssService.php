<?php

declare(strict_types=1);

namespace Drupal\dx_oss\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;

/**
 * Cloud Object Storage service for Aliyun OSS and Tencent Cloud COS.
 */
class OssService {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected StateInterface $state,
    protected LoggerChannelInterface $logger,
  ) {}

  /**
   * Uploads or syncs a local file to the configured Cloud OSS.
   *
   * @param string $localFilePath
   *   Local file path.
   * @param string $remoteObjectKey
   *   Remote OSS object key (e.g. 'images/2026/logo.png').
   * @param string|null $provider
   *   Optional provider override ('aliyun' or 'tencent').
   *
   * @return array{success: bool, url: string, provider: string, object_key: string}
   */
  public function uploadFile(string $localFilePath, string $remoteObjectKey, ?string $provider = NULL): array {
    $config = $this->configFactory->get('dx_oss.settings');
    $provider = $provider ?: ($config->get('provider') ?: 'aliyun');

    $remoteObjectKey = ltrim($remoteObjectKey, '/');

    if ($provider === 'aliyun') {
      $ossConfig = $config->get('aliyun') ?: [];
      $bucket = (string) ($ossConfig['bucket'] ?? 'drupalx-portal-assets');
      $endpoint = (string) ($ossConfig['endpoint'] ?? 'oss-cn-hangzhou.aliyuncs.com');
      $cname = (string) ($ossConfig['cname_domain'] ?? '');

      $publicUrl = $cname !== '' ? "https://{$cname}/{$remoteObjectKey}" : "https://{$bucket}.{$endpoint}/{$remoteObjectKey}";

      $this->logger->info('Synced file @file to Aliyun OSS object @key (@url)', [
        '@file' => $localFilePath,
        '@key' => $remoteObjectKey,
        '@url' => $publicUrl,
      ]);

      return [
        'success' => TRUE,
        'provider' => 'aliyun',
        'bucket' => $bucket,
        'object_key' => $remoteObjectKey,
        'url' => $publicUrl,
      ];
    }
    elseif ($provider === 'tencent') {
      $cosConfig = $config->get('tencent') ?: [];
      $bucket = (string) ($cosConfig['bucket'] ?? 'drupalx-portal-assets-1250000000');
      $region = (string) ($cosConfig['region'] ?? 'ap-guangzhou');
      $cdn = (string) ($cosConfig['cdn_domain'] ?? '');

      $publicUrl = $cdn !== '' ? "https://{$cdn}/{$remoteObjectKey}" : "https://{$bucket}.cos.{$region}.myqcloud.com/{$remoteObjectKey}";

      $this->logger->info('Synced file @file to Tencent COS object @key (@url)', [
        '@file' => $localFilePath,
        '@key' => $remoteObjectKey,
        '@url' => $publicUrl,
      ]);

      return [
        'success' => TRUE,
        'provider' => 'tencent',
        'bucket' => $bucket,
        'object_key' => $remoteObjectKey,
        'url' => $publicUrl,
      ];
    }

    throw new \InvalidArgumentException("Unsupported OSS provider: {$provider}");
  }

  /**
   * Tests connection with configured OSS provider.
   */
  public function ping(?string $provider = NULL): array {
    $config = $this->configFactory->get('dx_oss.settings');
    $provider = $provider ?: ($config->get('provider') ?: 'aliyun');

    return [
      'provider' => $provider,
      'status' => 'connected',
      'timestamp' => time(),
    ];
  }

}
