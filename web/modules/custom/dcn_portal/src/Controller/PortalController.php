<?php

declare(strict_types=1);

namespace Drupal\dcn_portal\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Portal page controllers.
 */
class PortalController extends ControllerBase {

  /**
   * Products listing page.
   */
  public function products(): array {
    $nodes = $this->loadPublishedNodes('dcn_product');
    $items = [];
    foreach ($nodes as $node) {
      $items[] = [
        'title' => $node->label(),
        'sku' => $node->hasField('field_dcn_sku') ? $node->get('field_dcn_sku')->value : '',
        'price' => $node->hasField('field_dcn_price') ? $node->get('field_dcn_price')->value : '',
        'url' => $node->toUrl()->toString(),
      ];
    }

    return [
      '#theme' => 'dcn_portal_product_list',
      '#products' => $items,
      '#cache' => ['tags' => ['node_list:dcn_product']],
    ];
  }

  /**
   * Media center listing page.
   */
  public function mediaCenter(): array {
    $nodes = $this->loadPublishedNodes('dcn_media');
    $items = [];
    foreach ($nodes as $node) {
      $items[] = [
        'title' => $node->label(),
        'summary' => $node->hasField('body') ? $node->get('body')->summary : '',
        'url' => $node->toUrl()->toString(),
      ];
    }

    return [
      '#theme' => 'dcn_portal_media_list',
      '#items' => $items,
      '#cache' => ['tags' => ['node_list:dcn_media']],
    ];
  }

  /**
   * Portal front landing page.
   */
  public function front(): array {
    $companyConfig = $this->config('dcn_tenant.settings');
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['dcn-portal-front']],
      'intro' => [
        '#markup' => '<h2>' . $this->t('Welcome to @company', [
          '@company' => $companyConfig->get('company_name') ?: $this->t('Digital AI Portal'),
        ]) . '</h2>',
      ],
      'links' => [
        '#theme' => 'item_list',
        '#items' => [
          $this->t('<a href=":url">Browse products</a>', [':url' => '/products']),
          $this->t('<a href=":url">Media center</a>', [':url' => '/media-center']),
        ],
      ],
    ];
  }

  /**
   * Loads published nodes for a bundle.
   */
  protected function loadPublishedNodes(string $bundle): array {
    $storage = $this->entityTypeManager()->getStorage('node');
    $nids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', $bundle)
      ->condition('status', 1)
      ->sort('created', 'DESC')
      ->execute();
    return $storage->loadMultiple($nids);
  }

}
