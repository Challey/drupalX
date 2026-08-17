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

  /**
   * @var \Drupal\dx_health\Service\HealthChecker
   */
  protected $checker;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, HealthChecker $checker) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->checker = $checker;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
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
  public function build() {
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
    $detail = $ok ? 'All critical probes passed.' : ('Issues: ' . implode(', ', array_slice($failed, 0, 6)));
    return [
      '#markup' => '<div class="dx-tenant-health"><strong>' . ($ok ? 'Platform health: OK' : 'Platform health: check needed') . '</strong><p>' . htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') . '</p></div>',
      '#cache' => ['max-age' => 60],
    ];
  }

}
