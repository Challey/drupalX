<?php

declare(strict_types=1);

namespace Drupal\dx_channel\Commands;

use Drupal\dx_channel\Service\ChannelAudit;
use Drush\Commands\DrushCommands;

/**
 * DXEP API audit Drush.
 */
final class AuditCommands extends DrushCommands {

  public function __construct(
    private readonly ChannelAudit $audit,
  ) {
    parent::__construct();
  }

  /**
   * Show recent DXEP API audit entries.
   *
   * @command dx:channel-audit
   * @option limit Max rows
   */
  public function recent(array $options = ['limit' => 20]): void {
    $rows = $this->audit->recent((int) ($options['limit'] ?: 20));
    $this->io()->writeln(json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  }

  /**
   * Aggregate audit status codes / routes.
   *
   * @command dx:channel-audit-stats
   */
  public function stats(): void {
    $rows = $this->audit->recent(500);
    $byStatus = [];
    $byRoute = [];
    foreach ($rows as $row) {
      $s = (string) ($row['status'] ?? '0');
      $r = (string) ($row['route'] ?? '');
      $byStatus[$s] = ($byStatus[$s] ?? 0) + 1;
      $byRoute[$r] = ($byRoute[$r] ?? 0) + 1;
    }
    arsort($byRoute);
    $this->io()->writeln(json_encode([
      'sample' => count($rows),
      'by_status' => $byStatus,
      'top_routes' => array_slice($byRoute, 0, 10, TRUE),
      'rate_limit' => [
        'per_token' => \Drupal\dx_channel\Service\ChannelAudit::RATE_LIMIT,
        'window_sec' => \Drupal\dx_channel\Service\ChannelAudit::RATE_WINDOW,
      ],
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  }

}
