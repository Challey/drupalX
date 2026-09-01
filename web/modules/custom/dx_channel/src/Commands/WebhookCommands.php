<?php

declare(strict_types=1);

namespace Drupal\dx_channel\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\dx_channel\Service\WebhookService;
use Drush\Commands\DrushCommands;

/**
 * Drush helpers for DXEP webhooks.
 */
final class WebhookCommands extends DrushCommands {

  public function __construct(
    private readonly WebhookService $webhooks,
    private readonly ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct();
  }

  /**
   * Register an outbound webhook endpoint.
   *
   * @command dx:webhook-register
   * @option secret HMAC secret (optional)
   * @option events Comma-separated events
   * @param string $url Destination URL
   */
  public function register(string $url, array $options = ['secret' => '', 'events' => 'resource.published']): void {
    $events = array_values(array_filter(array_map('trim', explode(',', (string) $options['events']))));
    $ep = $this->webhooks->register($url, (string) $options['secret'], $events);
    $this->io()->writeln(json_encode($ep, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  }

  /**
   * List webhook endpoints (secrets redacted).
   *
   * @command dx:webhook-list
   */
  public function listEndpoints(): void {
    $this->io()->writeln(json_encode(
      $this->webhooks->listEndpointsRedacted(),
      JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
    ));
  }

  /**
   * Fire a test resource.published event.
   *
   * @command dx:webhook-test
   */
  public function testFire(): void {
    $result = $this->webhooks->dispatch('resource.published', [
      'type' => 'article',
      'external_id' => 'wh_test',
      'title' => 'Webhook test',
    ]);
    $this->io()->writeln(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  }

  /**
   * Self-test HMAC signature helper.
   *
   * @command dx:webhook-verify
   */
  public function verifySelfTest(): void {
    $secret = 'test_secret';
    $body = '{"event":"resource.published"}';
    $ts = (string) time();
    $sig = 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, $secret);
    $ok = $this->webhooks->verifySignature($body, $ts, $sig, $secret);
    $bad = $this->webhooks->verifySignature($body, $ts, 'sha256=deadbeef', $secret);
    $this->io()->writeln(json_encode([
      'ok' => $ok && !$bad,
      'valid_sig' => $ok,
      'invalid_sig_rejected' => !$bad,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    if (!$ok || $bad) {
      throw new \RuntimeException('Webhook signature self-test failed');
    }
  }

  /**
   * List webhook dead letters.
   *
   * @command dx:webhook-dead-letters
   * @option limit Max rows
   */
  public function deadLetters(array $options = ['limit' => 20]): void {
    $items = $this->webhooks->listDeadLetters((int) ($options['limit'] ?: 20));
    $this->io()->writeln(json_encode([
      'count' => count($items),
      'dead_letters' => $items,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  }

  /**
   * Retry dead-letter payloads.
   *
   * Payloads inside their backoff window or past the 8-try budget are deferred,
   * not re-posted; pass --force to retry them now.
   *
   * @command dx:webhook-retry
   * @option limit Max items to attempt
   * @option force Ignore the exponential backoff window
   */
  public function retry(array $options = ['limit' => 20, 'force' => FALSE]): void {
    $result = $this->webhooks->retryDeadLetters(
      (int) ($options['limit'] ?: 20),
      !empty($options['force']),
    );
    $this->io()->writeln(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  }

  /**
   * Delivery health report (success rate, retries, dead letters).
   *
   * @command dx:webhook-health
   * @option days Window in days (1-90)
   */
  public function health(array $options = ['days' => 7]): void {
    $days = (int) ($options['days'] ?: 7);
    $days = max(1, min(90, $days));
    $this->io()->writeln(json_encode(
      $this->webhooks->healthReport($days),
      JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
    ));
  }

  /**
   * Mirror the site-level webhook endpoint from dx_channel.settings into state.
   *
   * Run after importing config without the admin UI (config import does not
   * execute form submit handlers).
   *
   * @command dx:webhook-site-sync
   * @option enable Force the mirrored endpoint on
   * @option disable Force the mirrored endpoint off
   */
  public function syncSite(array $options = ['enable' => NULL, 'disable' => NULL]): void {
    $config = $this->configFactory->get('dx_channel.settings');
    $webhook = is_array($config->get('webhook')) ? $config->get('webhook') : [];
    $url = trim((string) ($webhook['url'] ?? ''));
    $events = array_values(array_map('strval', is_array($webhook['events'] ?? NULL) ? $webhook['events'] : ['resource.published']));
    $enabled = !empty($webhook['enabled']);
    if (!empty($options['enable'])) {
      $enabled = TRUE;
    }
    if (!empty($options['disable'])) {
      $enabled = FALSE;
    }
    $endpoint = $this->webhooks->syncSiteEndpoint($url, (string) ($webhook['secret'] ?? ''), $events ?: ['resource.published'], $enabled);
    if ($endpoint === NULL) {
      $this->io()->writeln(json_encode([
        'ok' => TRUE,
        'site_endpoint' => NULL,
        'message' => 'no url configured; mirrored endpoint removed',
      ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
      return;
    }
    $redacted = $endpoint;
    $redacted['secret'] = !empty($redacted['secret']) ? '***' : '';
    $this->io()->writeln(json_encode($redacted, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  }

  /**
   * Reset webhook delivery counters (endpoints and queue untouched).
   *
   * @command dx:webhook-stats-reset
   */
  public function resetStats(): void {
    $this->webhooks->resetStats();
    $this->io()->writeln(json_encode(['ok' => TRUE, 'stats' => $this->webhooks->stats()], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  }

  /**
   * Clear webhook dead-letter queue.
   *
   * @command dx:webhook-dead-letters-clear
   */
  public function clearDeadLetters(): void {
    $n = $this->webhooks->clearDeadLetters(0);
    $this->io()->writeln(json_encode(['ok' => TRUE, 'cleared' => $n], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  }

  /**
   * Update an endpoint URL.
   *
   * @command dx:webhook-update-url
   * @param string $id Endpoint id
   * @param string $url New URL
   */
  public function updateUrl(string $id, string $url): void {
    $ok = $this->webhooks->updateUrl($id, $url);
    $this->io()->writeln(json_encode(['ok' => $ok, 'id' => $id, 'url' => $url], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    if (!$ok) {
      throw new \RuntimeException('Endpoint not found');
    }
  }

}
