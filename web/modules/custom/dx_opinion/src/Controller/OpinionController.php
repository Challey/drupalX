<?php

declare(strict_types=1);

namespace Drupal\dx_opinion\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Opinion monitoring demo pages.
 */
final class OpinionController extends ControllerBase {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('config.factory'));
  }

  /**
   * Public demo dashboard.
   */
  public function dashboard(): array {
    $config = $this->configFactory->get('dx_opinion.settings');
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
