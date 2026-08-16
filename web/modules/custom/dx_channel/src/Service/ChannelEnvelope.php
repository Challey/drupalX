<?php

declare(strict_types=1);

namespace Drupal\dx_channel\Service;

/**
 * DXEP JSON response envelope builder.
 */
final class ChannelEnvelope {

  public const API_VERSION = '1.0';

  /**
   * Build a success envelope.
   *
   * @param mixed $data
   *   Payload.
   * @param array<string, mixed> $meta
   *   Optional meta.
   * @param string|null $requestId
   *   Request id.
   *
   * @return array<string, mixed>
   */
  public function ok(mixed $data, array $meta = [], ?string $requestId = NULL): array {
    $envelope = [
      'ok' => TRUE,
      'api_version' => self::API_VERSION,
      'request_id' => $requestId ?? $this->newRequestId(),
      'tenant_id' => $this->tenantId(),
      'data' => $data,
    ];
    if ($meta !== []) {
      $envelope['meta'] = $meta;
    }
    return $envelope;
  }

  /**
   * Build an error envelope.
   *
   * @param string $code
   *   Stable DX.* code.
   * @param string $message
   *   Human message.
   * @param array<int, array<string, string>> $details
   *   Field issues.
   * @param string|null $requestId
   *   Request id.
   *
   * @return array<string, mixed>
   */
  public function error(string $code, string $message, array $details = [], ?string $requestId = NULL): array {
    $error = [
      'code' => $code,
      'message' => $message,
    ];
    if ($details !== []) {
      $error['details'] = $details;
    }
    return [
      'ok' => FALSE,
      'api_version' => self::API_VERSION,
      'request_id' => $requestId ?? $this->newRequestId(),
      'tenant_id' => $this->tenantId(),
      'error' => $error,
    ];
  }

  /**
   * New request id.
   */
  public function newRequestId(): string {
    return 'req_' . bin2hex(random_bytes(8));
  }

  /**
   * Best-effort tenant id from site path.
   */
  public function tenantId(): string {
    try {
      $sitePath = \Drupal::getContainer()->getParameter('site.path');
      if (is_string($sitePath) && $sitePath !== '') {
        return basename($sitePath);
      }
    }
    catch (\Throwable) {
      // Fall through.
    }
    return 'default';
  }

}
