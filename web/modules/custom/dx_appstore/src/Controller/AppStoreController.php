<?php

declare(strict_types=1);

namespace Drupal\dx_appstore\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;

/**
 * App Store catalog controller.
 */
class AppStoreController extends ControllerBase {

  /**
   * Renders the public app catalog.
   */
  public function catalog(): array {
    $storage = $this->entityTypeManager()->getStorage('dx_app_package');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('status', 1)
      ->sort('label')
      ->execute();
    $packages = $storage->loadMultiple($ids);

    $rows = [];
    foreach ($packages as $package) {
      /** @var \Drupal\dx_appstore\Entity\AppPackage $package */
      $rows[] = [
        'data' => [
          $package->label(),
          $package->get('category')->value,
          $package->get('trust_level')->value,
          $package->get('price')->value,
          [
            'data' => [
              '#type' => 'link',
              '#title' => $this->t('Request install'),
              '#url' => Url::fromRoute('dx_appstore.request', ['dx_app_package' => $package->id()]),
            ],
          ],
        ],
      ];
    }

    return [
      '#type' => 'table',
      '#header' => [
        $this->t('App'),
        $this->t('Category'),
        $this->t('Trust'),
        $this->t('Price'),
        $this->t('Actions'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No apps in the catalog yet. Run drush dx:appstore-seed.'),
    ];
  }

}
