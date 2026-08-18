<?php

declare(strict_types=1);

namespace Drupal\dx_health\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\dx_health\Service\HealthChecker;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Compact tenant/platform health summary for the portal theme.
 *
 * @Block(
 *   id = "dx_tenant_health_summary",
 *   admin_label = @Translation("Tenant health"),
 *   category = @Translation("DrupalX")
 * )
 */
class TenantHealthSummaryBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected HealthChecker $checker,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('dx_health.checker'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    try {
      $report = $this->checker->platform();
    }
    catch (\Throwable $e) {
      $report = ['ok' => FALSE, 'checks' => []];
    }

    $ok = !empty($report['ok']);
    $failed = [];
    foreach ($report['checks'] ?? [] as $check) {
      if (empty($check['ok'])) {
        $failed[] = (string) ($check['id'] ?? 'check');
      }
    }
    $detail = $ok
      ? 'All critical probes passed.'
      : ('Issues: ' . implode(', ', array_slice($failed, 0, 6)));

    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['dx-tenant-health', $ok ? 'dx-tenant-health--ok' : 'dx-tenant-health--warn'],
      ],
      'label' => [
        '#markup' => '<strong>' . ($ok ? 'Platform health: OK' : 'Platform health: check needed') . '</strong>',
      ],
      'detail' => [
        '#markup' => '<p>' . htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') . '</p>',
      ],
      '#cache' => ['max-age' => 60],
    ];
  }

}
