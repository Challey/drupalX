<?php

declare(strict_types=1);

namespace Drupal\dx_migrate\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\dx_channel\Service\IngestService;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Human review queue for L1/L2 Ingest drafts.
 */
final class ReviewQueueController extends ControllerBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $nodes,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('entity_type.manager'));
  }

  /**
   * Admin review table.
   */
  public function list(): array {
    $map = \Drupal::state()->get(IngestService::MAP_KEY, []);
    $nids = array_values(array_unique(array_map('intval', array_values(is_array($map) ? $map : []))));
    $rows = [];
    if ($nids !== []) {
      foreach ($this->nodes->getStorage('node')->loadMultiple($nids) as $node) {
        if (!$node instanceof NodeInterface || $node->isPublished()) {
          continue;
        }
        $rows[] = [
          $node->id(),
          $node->label(),
          $node->bundle(),
          [
            'data' => [
              '#type' => 'dropbutton',
              '#links' => [
                'publish' => [
                  'title' => $this->t('发布'),
                  'url' => Url::fromRoute('dx_migrate.review_publish', ['node' => $node->id()]),
                ],
                'edit' => [
                  'title' => $this->t('编辑'),
                  'url' => Url::fromRoute('entity.node.edit_form', ['node' => $node->id()]),
                ],
              ],
            ],
          ],
        ];
      }
    }
    return [
      '#type' => 'table',
      '#header' => [
        $this->t('NID'),
        $this->t('标题'),
        $this->t('类型'),
        $this->t('操作'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('暂无待审草稿（Ingest 映射为空或均已发布）。'),
    ];
  }

  /**
   * Publish one draft node from the queue.
   */
  public function publish(NodeInterface $node): RedirectResponse {
    $node->setPublished();
    $node->save();
    $this->messenger()->addStatus($this->t('已发布：@title', ['@title' => $node->label()]));
    return new RedirectResponse(Url::fromRoute('dx_migrate.review')->toString());
  }

}
