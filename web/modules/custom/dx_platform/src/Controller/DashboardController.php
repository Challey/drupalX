<?php

declare(strict_types=1);

namespace Drupal\dx_platform\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\dx_ai_gateway\Service\UsageTracker;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Platform dashboard controller.
 */
class DashboardController extends ControllerBase {

  public function __construct(
    protected ?UsageTracker $usageTracker = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $tracker = $container->has('dx_ai_gateway.usage_tracker')
      ? $container->get('dx_ai_gateway.usage_tracker')
      : NULL;
    return new static($tracker);
  }

  /**
   * Renders the platform dashboard.
   */
  public function dashboard(): array {
    $storage = $this->entityTypeManager()->getStorage('dx_tenant');
    $tenants = $storage->loadMultiple();

    $rows = [];
    foreach ($tenants as $tenant) {
      $rows[] = [
        $tenant->label(),
        $tenant->get('machine_name')->value,
        $tenant->get('status')->value,
        $tenant->get('subdomain')->value ?: '-',
        $tenant->get('owner_mail')->value ?: '-',
      ];
    }

    $build['summary'] = [
      '#type' => 'container',
      'count' => [
        '#markup' => '<p>' . $this->t('@count tenant(s) registered.', ['@count' => count($rows)]) . '</p>',
      ],
      'actions' => [
        '#type' => 'link',
        '#title' => $this->t('Add tenant'),
        '#url' => Url::fromRoute('entity.dx_tenant.add_form'),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ],
    ];

    if ($this->usageTracker && $this->moduleHandler()->moduleExists('dx_ai_gateway')) {
      $s = $this->usageTracker->summary();
      $build['ai'] = [
        '#type' => 'details',
        '#title' => $this->t('AI Gateway (@period)', ['@period' => $s['period']]),
        '#open' => TRUE,
        'stats' => [
          '#markup' => '<p>' . $this->t('Tokens @used / @quota · calls @calls (@ok ok)', [
            '@used' => number_format($s['tokens_used']),
            '@quota' => number_format($s['quota']),
            '@calls' => $s['calls'],
            '@ok' => $s['ok_calls'],
          ]) . '</p>',
        ],
        'links' => [
          '#type' => 'container',
          'settings' => [
            '#type' => 'link',
            '#title' => $this->t('AI settings'),
            '#url' => Url::fromRoute('dx_ai_gateway.settings'),
            '#attributes' => ['class' => ['button']],
          ],
          'chat' => [
            '#type' => 'link',
            '#title' => $this->t('Open AI chat'),
            '#url' => Url::fromRoute('dx_ai_gateway.chat_page'),
            '#attributes' => ['class' => ['button']],
          ],
        ],
      ];
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Label'),
        $this->t('Machine name'),
        $this->t('Status'),
        $this->t('Subdomain'),
        $this->t('Owner email'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No tenants have been created yet.'),
    ];

    return $build;
  }

}
