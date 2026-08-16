<?php

declare(strict_types=1);

namespace Drupal\dx_appstore\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\Request;

/**
 * App Store catalog controller.
 */
class AppStoreController extends ControllerBase {

  /**
   * Renders the public app catalog.
   */
  public function catalog(Request $request): array {
    $storage = $this->entityTypeManager()->getStorage('dx_app_package');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('status', 1)
      ->sort('label')
      ->execute();
    $packages = $storage->loadMultiple($ids);

    $trustFilter = array_values(array_filter(array_map(
      'trim',
      explode(',', (string) $request->query->get('trust', '')),
    )));
    if ($request->query->get('policy') === 'gov' && \Drupal::hasService('dx_trust.policy')) {
      $trustFilter = \Drupal::service('dx_trust.policy')->settings()['allowed_trust_tiers'] ?? $trustFilter;
    }

    $rows = [];
    $blocked = 0;
    foreach ($packages as $package) {
      /** @var \Drupal\dx_appstore\Entity\AppPackage $package */
      $trust = (string) $package->get('trust_level')->value;
      if ($trustFilter !== [] && !in_array($trust, $trustFilter, TRUE)) {
        $blocked++;
        continue;
      }
      $rows[] = [
        'data' => [
          $package->label(),
          $package->get('category')->value,
          $trust,
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

    $build = [];
    if ($trustFilter !== []) {
      $build['filter'] = [
        '#markup' => '<p>' . $this->t('Trust filter: @t (@n hidden)', [
          '@t' => implode(', ', $trustFilter),
          '@n' => $blocked,
        ]) . '</p>',
      ];
    }
    $build['table'] = [
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
    return $build;
  }

}
