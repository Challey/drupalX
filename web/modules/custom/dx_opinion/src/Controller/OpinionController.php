<?php

declare(strict_types=1);

namespace Drupal\dx_opinion\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Opinion monitoring demo pages.
 */
final class OpinionController extends ControllerBase {

  /**
   * Public demo dashboard.
   */
  public function dashboard(): array {
    $config = $this->config('dx_opinion.settings');
    return [
      '#theme' => 'dx_opinion_dashboard',
      '#keywords' => $config->get('keywords') ?? [],
      '#items' => $config->get('demo_items') ?? [],
      '#notice' => $this->t('演示数据 · 交钥匙第一波能力包（D5-B）'),
      '#attached' => [
        'library' => ['dx_opinion/feed'],
      ],
    ];
  }

}
