<?php

declare(strict_types=1);

namespace Drupal\dx_channel\Service;

use Drupal\Core\State\StateInterface;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Outbound DXEP webhooks (DE5 MVP): register endpoints + fire resource.published.
 */
final class WebhookService {

  public const ENDPOINTS_KEY = 'dx_channel.webhooks';
  public const DEAD_LETTER_KEY = 'dx_channel.webhook_dead_letters';
  public const RATE_KEY = 'dx_channel.webhook_rate';
  public const STATS_KEY = 'dx_channel.webhook_stats';
  public const RATE_LIMIT = 60;
  public const RATE_WINDOW = 60;

  /** State id of the endpoint mirrored from `dx_channel.settings` (roadmap G4). */
  public const SITE_ENDPOINT_ID = 'wh_site';

  public function __construct(
    private readonly StateInterface $state,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * @return list<array{id: string, url: string, secret: string, events: list<string>, enabled: bool}>
   */
  public function listEndpoints(): array {
    $all = $this->state->get(self::ENDPOINTS_KEY, []);
    return is_array($all) ? array_values($all) : [];
  }

  /**
   * List endpoints with secrets redacted (safe for HTTP / logs).
   *
   * @return list<array{id: string, url: string, secret: string, events: list<string>, enabled: bool}>
   */
  public function listEndpointsRedacted(): array {
    $out = [];
    foreach ($this->listEndpoints() as $ep) {
      $ep['secret'] = '***';
      $out[] = $ep;
    }
    return $out;
  }

  /**
   * @return list<array{endpoint_id: string, failed_at: string, payload: array<string, mixed>}>
   */
  public function listDeadLetters(int $limit = 20): array {
    $all = $this->state->get(self::DEAD_LETTER_KEY, []);
    if (!is_array($all)) {
      return [];
    }
    $limit = max(1, min(200, $limit));
    return array_slice(array_values($all), -$limit);
  }

  /**
   * Depth of the dead-letter queue.
   */
  public function countDeadLetters(): int {
    $all = $this->state->get(self::DEAD_LETTER_KEY, []);
    return is_array($all) ? count($all) : 0;
  }

  /**
   * Clear dead-letter queue (or keep newest $keep).
   */
  public function clearDeadLetters(int $keep = 0): int {
    $all = $this->state->get(self::DEAD_LETTER_KEY, []);
    if (!is_array($all)) {
      $all = [];
    }
    $count = count($all);
    if ($keep <= 0) {
      $this->state->set(self::DEAD_LETTER_KEY, []);
      return $count;
    }
    $kept = array_slice(array_values($all), -$keep);
    $this->state->set(self::DEAD_LETTER_KEY, $kept);
    return $count - count($kept);
  }

  /**
   * Retry oldest dead letters against current endpoint URLs.
   *
   * A payload that already used its 8-try budget (§10.3) is *deferred*: it stays
   * in the queue for manual inspection instead of being re-posted forever. Rows
   * whose exponential backoff window has not elapsed are deferred too, unless
   * `$ignoreBackoff` is set by an operator.
   *
   * @return array{attempted: int, sent: int, failed: int, dropped: int, deferred: int}
   */
  public function retryDeadLetters(int $limit = 20, bool $ignoreBackoff = FALSE): array {
    $all = $this->state->get(self::DEAD_LETTER_KEY, []);
    if (!is_array($all) || $all === []) {
      return ['attempted' => 0, 'sent' => 0, 'failed' => 0, 'dropped' => 0, 'deferred' => 0];
    }
    $limit = max(1, min(100, $limit));
    $queue = array_values($all);
    $head = array_slice($queue, 0, $limit);
    $tail = array_slice($queue, $limit);
    $endpoints = [];
    foreach ($this->listEndpoints() as $ep) {
      $endpoints[(string) ($ep['id'] ?? '')] = $ep;
    }
    $sent = 0;
    $failed = 0;
    $dropped = 0;
    $deferred = 0;
    $remaining = [];
    foreach ($head as $item) {
      if (!is_array($item)) {
        $dropped++;
        continue;
      }
      $endpointId = (string) ($item['endpoint_id'] ?? '');
      $payload = is_array($item['payload'] ?? NULL) ? $item['payload'] : NULL;
      if ($endpointId === '' || $payload === NULL || !isset($endpoints[$endpointId])) {
        $dropped++;
        continue;
      }
      $retries = (int) ($item['retries'] ?? 0);
      $nextAt = trim((string) ($item['next_retry_at'] ?? ''));
      $waiting = $nextAt !== '' && strtotime($nextAt) !== FALSE && strtotime($nextAt) > time();
      if (!WebhookHealth::mayRetry($retries) || ($waiting && !$ignoreBackoff)) {
        $deferred++;
        $remaining[] = $item;
        continue;
      }
      $ep = $endpoints[$endpointId];
      if (empty($ep['enabled'])) {
        $remaining[] = $item;
        $failed++;
        continue;
      }
      $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
      if ($body === FALSE) {
        $dropped++;
        continue;
      }
      $ok = $this->post((string) $ep['url'], $body, (string) ($ep['secret'] ?? ''));
      if ($ok) {
        $sent++;
      }
      else {
        $failed++;
        $item['failed_at'] = gmdate('c');
        $item['retries'] = $retries + 1;
        $delay = WebhookHealth::backoffSeconds($retries + 1);
        $item['next_retry_in'] = $delay;
        $item['next_retry_at'] = gmdate('c', time() + max(0, $delay));
        $remaining[] = $item;
      }
    }
    $this->state->set(self::DEAD_LETTER_KEY, array_values(array_merge($remaining, $tail)));
    $this->saveStats(WebhookHealth::recordRetry($this->stats(), $sent, $failed, $dropped));
    return [
      'attempted' => count($head),
      'sent' => $sent,
      'failed' => $failed,
      'dropped' => $dropped,
      'deferred' => $deferred,
    ];
  }

  /**
   * Inject a dead-letter row (tests / ops).
   *
   * @param array<string, mixed> $payload
   */
  public function recordDeadLetter(string $endpointId, array $payload): void {
    $this->deadLetter($endpointId, $payload);
  }

  /**
   * @param list<string> $events
   *
   * @return array{id: string, url: string, secret: string, events: list<string>, enabled: bool}
   */
  public function register(string $url, string $secret = '', array $events = ['resource.published']): array {
    $url = trim($url);
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
      throw new \InvalidArgumentException('Webhook URL must be http(s)');
    }
    $id = 'wh_' . substr(bin2hex(random_bytes(6)), 0, 10);
    $endpoint = [
      'id' => $id,
      'url' => $url,
      'secret' => $secret !== '' ? $secret : bin2hex(random_bytes(16)),
      'events' => array_values($events) ?: ['resource.published'],
      'enabled' => TRUE,
      'created_at' => gmdate('c'),
    ];
    $all = $this->listEndpoints();
    $all[] = $endpoint;
    $this->state->set(self::ENDPOINTS_KEY, $all);
    return $endpoint;
  }

  public function revoke(string $id): bool {
    $all = $this->listEndpoints();
    $next = array_values(array_filter($all, static fn(array $e): bool => ($e['id'] ?? '') !== $id));
    if (count($next) === count($all)) {
      return FALSE;
    }
    $this->state->set(self::ENDPOINTS_KEY, $next);
    return TRUE;
  }

  /**
   * Update endpoint URL (e.g. fail-sink → live sink before retry).
   */
  public function updateUrl(string $id, string $url): bool {
    $url = trim($url);
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
      throw new \InvalidArgumentException('Webhook URL must be http(s)');
    }
    $all = $this->listEndpoints();
    $found = FALSE;
    foreach ($all as &$ep) {
      if (($ep['id'] ?? '') === $id) {
        $ep['url'] = $url;
        $found = TRUE;
        break;
      }
    }
    unset($ep);
    if (!$found) {
      return FALSE;
    }
    $this->state->set(self::ENDPOINTS_KEY, array_values($all));
    return TRUE;
  }

  /**
   * Dispatch an event to matching endpoints (best-effort HTTP POST).
   *
   * @param array<string, mixed> $resource
   *
   * @return array{sent: int, failed: int}
   */
  public function dispatch(string $event, array $resource, string $tenantId = 'platform'): array {
    if (!$this->allowDispatch()) {
      $this->logger->warning('Webhook rate limited');
      $this->saveStats(WebhookHealth::recordDispatch($this->stats(), $event, [], TRUE));
      return ['sent' => 0, 'failed' => 0, 'rate_limited' => TRUE];
    }
    $payload = [
      'event' => $event,
      'occurred_at' => gmdate('c'),
      'tenant_id' => $tenantId,
      'resource' => $resource,
    ];
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $sent = 0;
    $failed = 0;
    $deliveries = [];
    foreach ($this->listEndpoints() as $ep) {
      if (empty($ep['enabled'])) {
        continue;
      }
      $events = $ep['events'] ?? [];
      if ($events !== [] && !in_array($event, $events, TRUE) && !in_array('*', $events, TRUE)) {
        continue;
      }
      $ok = $this->post((string) $ep['url'], (string) $body, (string) ($ep['secret'] ?? ''));
      $deliveries[] = ['endpoint' => (string) ($ep['id'] ?? ''), 'ok' => $ok];
      if ($ok) {
        $sent++;
      }
      else {
        $failed++;
        $this->deadLetter($ep['id'] ?? '', $payload);
      }
    }
    $this->saveStats(WebhookHealth::recordDispatch($this->stats(), $event, $deliveries));
    return ['sent' => $sent, 'failed' => $failed];
  }

  /**
   * Verify inbound webhook signature header (for partners posting back).
   */
  public function verifySignature(string $body, string $timestamp, string $signatureHeader, string $secret): bool {
    $expected = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    $given = trim($signatureHeader);
    if (!hash_equals($expected, $given)) {
      return FALSE;
    }
    // Reject stale timestamps (>5 min).
    if (!ctype_digit($timestamp)) {
      return FALSE;
    }
    return abs(time() - (int) $timestamp) <= 300;
  }

  /**
   * Delivery counters as stored (never missing keys).
   *
   * @return array<string, mixed>
   */
  public function stats(): array {
    $raw = $this->state->get(self::STATS_KEY, []);
    return WebhookHealth::normalize(is_array($raw) ? $raw : []);
  }

  /**
   * Replace the counter document.
   *
   * @param array<string, mixed> $stats
   */
  public function saveStats(array $stats): void {
    $this->state->set(self::STATS_KEY, WebhookHealth::normalize($stats));
  }

  /**
   * Reset the counters (keeps endpoints and the dead-letter queue intact).
   */
  public function resetStats(): void {
    $this->state->set(self::STATS_KEY, WebhookHealth::emptyStats());
  }

  /**
   * Health report for the admin screen and `dx:webhook-health`.
   *
   * @return array<string, mixed>
   */
  public function healthReport(int $windowDays = 7): array {
    $index = [];
    foreach ($this->listEndpoints() as $ep) {
      $row = $ep;
      unset($row['secret']);
      $index[(string) ($ep['id'] ?? '')] = $row;
    }
    $report = WebhookHealth::report($this->stats(), $index, $this->countDeadLetters(), $windowDays);
    $report['site_endpoint'] = $this->siteEndpoint() !== NULL;
    return $report;
  }

  /**
   * The endpoint mirrored from `dx_channel.settings`, if any.
   *
   * @return array<string, mixed>|NULL
   */
  public function siteEndpoint(): ?array {
    foreach ($this->listEndpoints() as $ep) {
      if ((string) ($ep['id'] ?? '') === self::SITE_ENDPOINT_ID) {
        return $ep;
      }
    }
    return NULL;
  }

  /**
   * Mirror the site-level endpoint into state (roadmap G4).
   *
   * Passing an empty URL removes the mirrored endpoint, which returns dispatch()
   * to exactly the pre-G4 behaviour (registered endpoints only, no exception).
   *
   * @param list<string> $events
   *
   * @return array<string, mixed>|NULL
   */
  public function syncSiteEndpoint(string $url, string $secret = '', array $events = ['resource.published'], bool $enabled = TRUE): ?array {
    $url = trim($url);
    $all = $this->listEndpoints();
    if ($url === '') {
      $next = array_values(array_filter(
        $all,
        static fn(array $e): bool => (string) ($e['id'] ?? '') !== self::SITE_ENDPOINT_ID,
      ));
      $this->state->set(self::ENDPOINTS_KEY, $next);
      return NULL;
    }
    if (!preg_match('#^https?://#i', $url)) {
      throw new \InvalidArgumentException('Webhook URL must be http(s)');
    }
    $existing = $this->siteEndpoint();
    $endpoint = [
      'id' => self::SITE_ENDPOINT_ID,
      'url' => $url,
      'secret' => $secret !== '' ? $secret : (string) ($existing['secret'] ?? bin2hex(random_bytes(16))),
      'events' => array_values(array_map('strval', $events)) ?: ['resource.published'],
      'enabled' => $enabled,
      'created_at' => (string) ($existing['created_at'] ?? gmdate('c')),
      'updated_at' => gmdate('c'),
      'site_level' => TRUE,
    ];
    $replaced = FALSE;
    foreach ($all as &$ep) {
      if ((string) ($ep['id'] ?? '') === self::SITE_ENDPOINT_ID) {
        $ep = $endpoint;
        $replaced = TRUE;
        break;
      }
    }
    unset($ep);
    if (!$replaced) {
      $all[] = $endpoint;
    }
    $this->state->set(self::ENDPOINTS_KEY, array_values($all));
    return $endpoint;
  }

  protected function allowDispatch(): bool {
    $bucket = $this->state->get(self::RATE_KEY, []);
    if (!is_array($bucket)) {
      $bucket = [];
    }
    $now = time();
    $windowStart = $now - self::RATE_WINDOW;
    $times = array_values(array_filter(
      array_map('intval', $bucket['times'] ?? []),
      static fn(int $t): bool => $t >= $windowStart,
    ));
    if (count($times) >= self::RATE_LIMIT) {
      $this->state->set(self::RATE_KEY, ['times' => $times]);
      return FALSE;
    }
    $times[] = $now;
    $this->state->set(self::RATE_KEY, ['times' => $times]);
    return TRUE;
  }

  protected function post(string $url, string $body, string $secret): bool {
    // Explicit fail sink for dead-letter smoke (never network).
    if (preg_match('#^https?://fail\.example\.com(/|$)#i', $url)) {
      $this->logger->warning('Webhook fail-sink rejected @url', ['@url' => $url]);
      return FALSE;
    }
    // Fixture / local sink: accept example.com and localhost without network in smoke.
    if (preg_match('#^https?://(example\.com|localhost|127\.0\.0\.1)(/|$)#i', $url)) {
      $this->logger->notice('Webhook sink accepted @url event body length=@n', [
        '@url' => $url,
        '@n' => (string) strlen($body),
      ]);
      return TRUE;
    }
    $ts = (string) time();
    $sig = hash_hmac('sha256', $ts . '.' . $body, $secret);
    $ctx = stream_context_create([
      'http' => [
        'method' => 'POST',
        'header' => implode("\r\n", [
          'Content-Type: application/json',
          'X-DX-Timestamp: ' . $ts,
          'X-DX-Signature: sha256=' . $sig,
          'User-Agent: DrupalX-dx_channel-webhook/1.0',
        ]),
        'content' => $body,
        'timeout' => 8,
        'ignore_errors' => TRUE,
      ],
    ]);
    $resp = @file_get_contents($url, FALSE, $ctx);
    if ($resp === FALSE) {
      return FALSE;
    }
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
      $code = (int) $m[1];
    }
    return $code >= 200 && $code < 300;
  }

  /**
   * @param array<string, mixed> $payload
   */
  protected function deadLetter(string $endpointId, array $payload): void {
    $all = $this->state->get(self::DEAD_LETTER_KEY, []);
    if (!is_array($all)) {
      $all = [];
    }
    $all[] = [
      'endpoint_id' => $endpointId,
      'failed_at' => gmdate('c'),
      'payload' => $payload,
    ];
    if (count($all) > 200) {
      $all = array_slice($all, -200);
    }
    $this->state->set(self::DEAD_LETTER_KEY, $all);
    $this->logger->warning('Webhook dead-letter endpoint=@id', ['@id' => $endpointId]);
  }

}
