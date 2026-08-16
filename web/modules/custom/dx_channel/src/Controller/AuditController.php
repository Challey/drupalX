<?php

declare(strict_types=1);

namespace Drupal\dx_channel\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\dx_channel\Service\ChannelAudit;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin UI for DXEP API audit log.
 */
final class AuditController extends ControllerBase {

  public function __construct(
    private readonly ChannelAudit $audit,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('dx_channel.audit'));
  }

  /**
   * Recent API audit rows.
   */
  public function recent(): array {
    $rows = [];
    foreach ($this->audit->recent(100) as $item) {
      $rows[] = [
        (string) ($item['at'] ?? ''),
        (string) ($item['route'] ?? ''),
        (string) ($item['token_id'] ?? ''),
        (string) ($item['status'] ?? ''),
        (string) ($item['request_id'] ?? ''),
        (string) ($item['scope'] ?? ''),
      ];
    }
    return [
      '#type' => 'table',
      '#header' => [
        $this->t('时间'),
        $this->t('路径'),
        $this->t('Token'),
        $this->t('状态'),
        $this->t('Request ID'),
        $this->t('Scope'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('暂无审计记录。调用 Channel/Exchange API 后会出现在此。'),
    ];
  }

}
