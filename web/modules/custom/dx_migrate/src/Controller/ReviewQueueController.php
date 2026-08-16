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
use Symfony\Component\HttpFoundation\Request;

/**
 * Human review queue for L1/L2 Ingest drafts.
 */
final class ReviewQueueController extends ControllerBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $nodes,
    private readonly IngestService $ingest,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('dx_channel.ingest'),
    );
  }

  /**
   * Admin review table with bundle filter + external id.
   */
  public function list(Request $request): array {
    $bundleFilter = trim((string) $request->query->get('bundle', ''));
    $map = $this->ingest->getExternalMap();
    $nidToKeys = [];
    foreach ($map as $key => $nid) {
      $nidToKeys[(int) $nid][] = (string) $key;
    }
    $nids = array_keys($nidToKeys);
    $pending = [];
    $byBundle = [];
    if ($nids !== []) {
      foreach ($this->nodes->getStorage('node')->loadMultiple($nids) as $node) {
        if (!$node instanceof NodeInterface || $node->isPublished()) {
          continue;
        }
        $bundle = $node->bundle();
        $byBundle[$bundle] = ($byBundle[$bundle] ?? 0) + 1;
        if ($bundleFilter !== '' && $bundle !== $bundleFilter) {
          continue;
        }
        $keys = $nidToKeys[(int) $node->id()] ?? [];
        $pending[] = [
          'node' => $node,
          'external' => implode(', ', $keys),
        ];
      }
    }

    $filterLinks = [
      Link::fromTextAndUrl($this->t('全部 (@n)', ['@n' => array_sum($byBundle)]), Url::fromRoute('dx_migrate.review'))->toString(),
    ];
    foreach ($byBundle as $bundle => $count) {
      $filterLinks[] = Link::fromTextAndUrl(
        $this->t('@bundle (@n)', ['@bundle' => $bundle, '@n' => $count]),
        Url::fromRoute('dx_migrate.review', [], ['query' => ['bundle' => $bundle]]),
      )->toString();
    }

    $rows = [];
    foreach ($pending as $item) {
      /** @var \Drupal\node\NodeInterface $node */
      $node = $item['node'];
      $rows[] = [
        $node->id(),
        $item['external'] !== '' ? $item['external'] : '—',
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
              'discard' => [
                'title' => $this->t('丢弃'),
                'url' => Url::fromRoute('dx_migrate.review_discard', ['node' => $node->id()]),
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

    return [
      'summary' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['dx-migrate-review-summary']],
        'count' => [
          '#markup' => '<p><strong>' . $this->t('待审草稿：@n', ['@n' => count($pending)]) . '</strong></p>',
        ],
        'filters' => [
          '#markup' => '<p>' . $this->t('筛选：') . ' ' . implode(' · ', $filterLinks) . '</p>',
        ],
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('NID'),
          $this->t('外部 ID'),
          $this->t('标题'),
          $this->t('类型'),
          $this->t('操作'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('暂无待审草稿（Ingest 映射为空或均已发布）。可用 drush dx:migrate-l1 导入 fixture。'),
      ],
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

  /**
   * Discard draft: delete node and unmap external ids.
   */
  public function discard(NodeInterface $node): RedirectResponse {
    $title = $node->label();
    $nid = (int) $node->id();
    $node->delete();
    $removed = $this->ingest->unmapNid($nid);
    $this->messenger()->addStatus($this->t('已丢弃：@title（清除映射 @n）', [
      '@title' => $title,
      '@n' => $removed,
    ]));
    return new RedirectResponse(Url::fromRoute('dx_migrate.review')->toString());
  }

}
