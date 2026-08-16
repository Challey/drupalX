<?php

declare(strict_types=1);

namespace Drupal\dx_channel\Service;

use Drupal\Core\State\StateInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * DXEP API request audit log + simple per-token rate limit.
 */
final class ChannelAudit {

  public const LOG_KEY = 'dx_channel.api_audit';
  public const RATE_KEY = 'dx_channel.api_rate';
  public const RATE_LIMIT = 120;
  public const RATE_WINDOW = 60;

  public function __construct(
    private readonly StateInterface $state,
  ) {}

  /**
   * Record an API call.
   *
   * @param array<string, mixed> $extra
   */
  public function record(string $route, string $tokenId, int $status, string $requestId, array $extra = []): void {
    $all = $this->state->get(self::LOG_KEY, []);
    if (!is_array($all)) {
      $all = [];
    }
    $all[] = [
      'at' => gmdate('c'),
      'route' => $route,
      'token_id' => $tokenId,
      'status' => $status,
      'request_id' => $requestId,
    ] + $extra;
    if (count($all) > 1000) {
      $all = array_slice($all, -1000);
    }
    $this->state->set(self::LOG_KEY, $all);
  }

  /**
   * @return list<array<string, mixed>>
   */
  public function recent(int $limit = 50): array {
    $all = $this->state->get(self::LOG_KEY, []);
    if (!is_array($all)) {
      return [];
    }
    return array_slice(array_reverse($all), 0, max(1, min(200, $limit)));
  }

  /**
   * Per-token sliding window rate limit.
   */
  public function allow(string $tokenId): bool {
    $tokenId = $tokenId !== '' ? $tokenId : 'anonymous';
    $bucket = $this->state->get(self::RATE_KEY, []);
    if (!is_array($bucket)) {
      $bucket = [];
    }
    $now = time();
    $windowStart = $now - self::RATE_WINDOW;
    $times = array_values(array_filter(
      array_map('intval', $bucket[$tokenId] ?? []),
      static fn(int $t): bool => $t >= $windowStart,
    ));
    if (count($times) >= self::RATE_LIMIT) {
      $bucket[$tokenId] = $times;
      $this->state->set(self::RATE_KEY, $bucket);
      return FALSE;
    }
    $times[] = $now;
    $bucket[$tokenId] = $times;
    // Cap keys.
    if (count($bucket) > 200) {
      $bucket = array_slice($bucket, -200, NULL, TRUE);
    }
    $this->state->set(self::RATE_KEY, $bucket);
    return TRUE;
  }

  /**
   * Best-effort path from request.
   */
  public function routeFromRequest(Request $request): string {
    return $request->getPathInfo() ?: $request->getRequestUri();
  }

}
