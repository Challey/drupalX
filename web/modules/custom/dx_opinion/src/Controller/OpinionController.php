<?php

declare(strict_types=1);

namespace Drupal\dx_opinion\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\dx_opinion\Service\OpinionFeed;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Opinion monitoring demo / licensed feed pages.
 */
final class OpinionController extends ControllerBase {

  public function __construct(
    private readonly OpinionFeed $feed,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('dx_opinion.feed'));
  }

  /**
   * Public demo / licensed dashboard.
   */
  public function dashboard(): array {
    $data = $this->feed->load();
    return [
      '#theme' => 'dx_opinion_dashboard',
      '#keywords' => $this->config('dx_opinion.settings')->get('keywords') ?? [],
      '#items' => $data['items'],
      '#notice' => $data['notice'] . ' · mode=' . $data['mode'],
      '#attached' => [
        'library' => ['dx_opinion/feed'],
      ],
    ];
  }

}
