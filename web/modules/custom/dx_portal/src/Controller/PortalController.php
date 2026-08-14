<?php

declare(strict_types=1);

namespace Drupal\dx_portal\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;

/**
 * Portal page controllers.
 */
class PortalController extends ControllerBase {

  /**
   * Products listing page.
   */
  public function products(): array {
    $nodes = $this->loadPublishedNodes('dx_product');
    $items = [];
    foreach ($nodes as $node) {
      $summary = '';
      if ($node->hasField('body') && !$node->get('body')->isEmpty()) {
        $summary = trim(strip_tags((string) ($node->get('body')->summary ?: $node->get('body')->value)));
        $summary = mb_substr(preg_replace('/\s+/u', ' ', $summary) ?: '', 0, 200);
      }
      $items[] = [
        'id' => $node->id(),
        'title' => $node->label(),
        'sku' => $node->hasField('field_dx_sku') ? (string) $node->get('field_dx_sku')->value : '',
        'price' => $node->hasField('field_dx_price') ? (string) $node->get('field_dx_price')->value : '',
        'summary' => $summary,
        'url' => $node->toUrl()->toString(),
      ];
    }

    return [
      '#theme' => 'dx_portal_product_list',
      '#products' => $items,
      '#attached' => ['library' => ['dx_portal/portal']],
      '#cache' => ['tags' => ['node_list:dx_product']],
    ];
  }

  /**
   * Media center listing page.
   */
  public function mediaCenter(): array {
    $nodes = $this->loadPublishedNodes('dx_media');
    $items = [];
    foreach ($nodes as $node) {
      $summary = '';
      if ($node->hasField('body') && !$node->get('body')->isEmpty()) {
        $summary = trim(strip_tags((string) ($node->get('body')->summary ?: $node->get('body')->value)));
        $summary = mb_substr(preg_replace('/\s+/u', ' ', $summary) ?: '', 0, 220);
      }
      $items[] = [
        'title' => $node->label(),
        'summary' => $summary,
        'url' => $node->toUrl()->toString(),
        'created' => \Drupal::service('date.formatter')->format((int) $node->getCreatedTime(), 'medium'),
      ];
    }

    return [
      '#theme' => 'dx_portal_media_list',
      '#items' => $items,
      '#attached' => ['library' => ['dx_portal/portal']],
      '#cache' => ['tags' => ['node_list:dx_media']],
    ];
  }

  /**
   * Portal front landing page.
   */
  public function front(): array {
    $companyConfig = $this->config('dx_tenant.settings');
    $company = $companyConfig->get('company_name') ?: $this->t('Digital AI Portal');
    $industry = $companyConfig->get('industry') ?: '';

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['dx-portal-front']],
      '#attached' => ['library' => ['dx_portal/portal']],
      'hero' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['dx-portal-front__hero']],
        'brand' => [
          '#markup' => '<p class="dx-portal-front__brand">' . $this->t('DrupalX') . '</p>',
        ],
        'title' => [
          '#markup' => '<h1>' . $this->t('@company', ['@company' => $company]) . '</h1>',
        ],
        'lead' => [
          '#markup' => '<p class="dx-portal-front__lead">' . $this->t('企业数字门户：产品展示、媒体中心与 AI 客服一站触达。@industry', [
            '@industry' => $industry !== '' ? $this->t('行业：@i', ['@i' => $industry]) : '',
          ]) . '</p>',
        ],
        'cta' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['dx-portal-front__cta']],
          'products' => [
            '#type' => 'link',
            '#title' => $this->t('浏览产品'),
            '#url' => Url::fromRoute('dx_portal.products'),
            '#attributes' => ['class' => ['dx-portal-btn']],
          ],
          'media' => [
            '#type' => 'link',
            '#title' => $this->t('媒体中心'),
            '#url' => Url::fromRoute('dx_portal.media_center'),
            '#attributes' => ['class' => ['dx-portal-btn', 'dx-portal-btn--ghost']],
          ],
          'ai' => [
            '#type' => 'link',
            '#title' => $this->t('AI 客服'),
            '#url' => Url::fromUri('internal:/ai/chat'),
            '#attributes' => ['class' => ['dx-portal-btn', 'dx-portal-btn--ghost']],
          ],
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
