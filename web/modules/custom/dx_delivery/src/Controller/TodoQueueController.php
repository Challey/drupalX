<?php

declare(strict_types=1);

namespace Drupal\dx_delivery\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\dx_delivery\Service\TodoService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Admin queue for turnkey manual todos (L3 / 待补).
 */
final class TodoQueueController extends ControllerBase {

  public function __construct(
    private readonly TodoService $todos,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('dx_delivery.todo'));
  }

  /**
   * Admin table of delivery todos.
   */
  public function list(Request $request): array {
    $statusFilter = trim((string) $request->query->get('status', 'open'));
    $blueprintId = (int) $request->query->get('blueprint', 0);
    $rows = $this->todos->list(
      $blueprintId > 0 ? $blueprintId : NULL,
      $statusFilter === 'all' ? NULL : ($statusFilter !== '' ? $statusFilter : NULL),
    );
    $counts = $this->todos->counts($blueprintId > 0 ? $blueprintId : NULL);

    $filterLinks = [
      Link::fromTextAndUrl(
        $this->t('待办 (@n)', ['@n' => $counts['open']]),
        Url::fromRoute('dx_delivery.todos', [], ['query' => array_filter(['status' => 'open', 'blueprint' => $blueprintId ?: NULL])]),
      )->toString(),
      Link::fromTextAndUrl(
        $this->t('已完成 (@n)', ['@n' => $counts['done']]),
        Url::fromRoute('dx_delivery.todos', [], ['query' => array_filter(['status' => 'done', 'blueprint' => $blueprintId ?: NULL])]),
      )->toString(),
      Link::fromTextAndUrl(
        $this->t('全部 (@n)', ['@n' => $counts['total']]),
        Url::fromRoute('dx_delivery.todos', [], ['query' => array_filter(['status' => 'all', 'blueprint' => $blueprintId ?: NULL])]),
      )->toString(),
    ];

    $tableRows = [];
    foreach ($rows as $item) {
      $links = [
        'blueprint' => [
          'title' => $this->t('蓝图'),
          'url' => Url::fromRoute('dx_delivery.blueprint', ['dx_blueprint' => $item['blueprint_id']]),
        ],
      ];
      if ($item['status'] !== 'done') {
        $links['done'] = [
          'title' => $this->t('标记完成'),
          'url' => Url::fromRoute('dx_delivery.todo_done', ['todo' => $item['id']], [
            'query' => ['destination' => $request->getRequestUri()],
          ]),
        ];
      }
      $tableRows[] = [
        $item['id'],
        $item['blueprint_id'],
        $item['tenant'],
        $item['category'],
        $item['title'],
        $item['status'],
        $item['quote_hint'] !== '' ? $item['quote_hint'] : '—',
        ['data' => ['#type' => 'dropbutton', '#links' => $links]],
      ];
    }

    return [
      'summary' => [
        '#markup' => '<p><strong>'
          . $this->t('人工待办（L3 / 待补）：打开 @open / 共 @total', [
            '@open' => $counts['open'],
            '@total' => $counts['total'],
          ])
          . '</strong></p><p>' . $this->t('筛选：') . ' ' . implode(' · ', $filterLinks) . '</p>',
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('ID'),
          $this->t('蓝图'),
          $this->t('租户'),
          $this->t('类别'),
          $this->t('标题'),
          $this->t('状态'),
          $this->t('报价提示'),
          $this->t('操作'),
        ],
        '#rows' => $tableRows,
        '#empty' => $this->t('暂无待办。L3 或含 App 渠道的交付会自动生成。'),
      ],
    ];
  }

  /**
   * Mark a todo done and return to the queue.
   */
  public function markDone(int $todo, Request $request): RedirectResponse {
    $this->todos->markDone($todo);
    $this->messenger()->addStatus($this->t('待办 @id 已标记完成。', ['@id' => $todo]));
    $destination = $request->query->get('destination');
    if (is_string($destination) && str_starts_with($destination, '/')) {
      return new RedirectResponse($destination);
    }
    return new RedirectResponse(Url::fromRoute('dx_delivery.todos')->toString());
  }

}
