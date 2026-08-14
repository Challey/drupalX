<?php

declare(strict_types=1);

namespace Drupal\dx_oss\Commands;

use Drupal\dx_oss\Service\OssService;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for Cloud OSS storage.
 */
class OssCommands extends DrushCommands {

  public function __construct(
    protected OssService $ossService,
  ) {
    parent::__construct();
  }

  /**
   * Test OSS connection.
   *
   * @command dx:oss-test
   * @param string $provider
   *   Provider: aliyun or tencent.
   * @usage drush dx:oss-test aliyun
   */
  public function test(string $provider = 'aliyun'): void {
    try {
      $result = $this->ossService->ping($provider);
      $this->logger()->success(sprintf('OSS Connection to %s is healthy.', $result['provider']));
    }
    catch (\Throwable $e) {
      $this->logger()->error('OSS ping failed: ' . $e->getMessage());
    }
  }

  /**
   * Upload / sync a file to Cloud OSS.
   *
   * @command dx:oss-upload
   * @param string $file
   *   Local file path.
   * @param string $key
   *   Remote OSS object key.
   * @param string $provider
   *   OSS provider (aliyun or tencent).
   * @usage drush dx:oss-upload /path/to/logo.png images/logo.png aliyun
   */
  public function upload(string $file, string $key, string $provider = 'aliyun'): void {
    try {
      $res = $this->ossService->uploadFile($file, $key, $provider);
      $this->io()->success(sprintf('Uploaded to %s: %s', $res['provider'], $res['url']));
    }
    catch (\Throwable $e) {
      $this->logger()->error('OSS Upload failed: ' . $e->getMessage());
    }
  }

}
