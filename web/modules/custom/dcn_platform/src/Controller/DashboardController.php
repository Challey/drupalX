<?php

declare(strict_types=1);

namespace Drupal\dcn_platform\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;

/**
 * Platform dashboard controller.
 */
class DashboardController extends ControllerBase {

  /**
   * Renders the platform dashboard.
   */
  public function dashboard(): array {
    $storage = $this->entityTypeManager()->getStorage('dcn_tenant');
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
        '#url' => Url::fromRoute('entity.dcn_tenant.add_form'),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ],
    ];

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
