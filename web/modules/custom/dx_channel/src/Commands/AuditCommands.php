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

}
