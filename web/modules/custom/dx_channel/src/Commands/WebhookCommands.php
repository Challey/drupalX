<?php

declare(strict_types=1);

namespace Drupal\dx_channel\Commands;

use Drupal\dx_channel\Service\WebhookService;
use Drush\Commands\DrushCommands;

/**
 * Drush helpers for DXEP webhooks.
 */
final class WebhookCommands extends DrushCommands {

  public function __construct(
    private readonly WebhookService $webhooks,
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
    $out = [];
    foreach ($this->webhooks->listEndpoints() as $ep) {
      $ep['secret'] = '***';
      $out[] = $ep;
    }
    $this->io()->writeln(json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
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

}
