<?php

declare(strict_types=1);

namespace Drupal\dx_opinion\Commands;

use Drupal\dx_opinion\Service\OpinionFeed;
use Drush\Commands\DrushCommands;

/**
 * Opinion ops helpers.
 */
final class OpinionCommands extends DrushCommands {

  public function __construct(
    private readonly OpinionFeed $feed,
  ) {
    parent::__construct();
  }

  /**
   * Show opinion feed readiness (no secrets).
   *
   * @command dx:opinion-status
   */
  public function status(): void {
    $c = \Drupal::config('dx_opinion.settings');
    $loaded = $this->feed->load();
    $this->io()->writeln(json_encode([
      'ok' => TRUE,
      'mode' => $loaded['mode'],
      'licensed_ok' => $loaded['licensed_ok'],
      'item_count' => count($loaded['items']),
      'endpoint_configured' => trim((string) ($c->get('licensed_endpoint') ?: '')) !== '',
      'token_configured' => trim((string) ($c->get('licensed_token') ?: '')) !== '',
      'sample_titles' => array_values(array_map(
        static fn(array $i): string => (string) ($i['title'] ?? ''),
        array_slice($loaded['items'], 0, 3),
      )),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  }

}
