<?php

declare(strict_types=1);

namespace Drupal\dx_delivery\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\dx_delivery\Entity\DeliveryBlueprint;
use Drupal\dx_delivery\Service\HandoffTodoService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * L3 handoff todo board (Phase F / F2).
 *
 * Read-only aggregation of the todos the orchestrator opens on a blueprint's
 * acceptance JSON, plus a gated sign-off action. It reuses HandoffTodoService as
 * it is - no signature changes - so the blueprint page and the drush commands
 * keep behaving exactly as before.
 */
final class DeliveryTodoBoardController extends ControllerBase {

  /**
   * How many recent blueprints the aggregated board scans.
   */
  private const BLUEPRINT_SCAN = 30;

  /**
   * Board tab keys, in display order.
   */
  private const FILTERS = ['open', 'done', 'overdue', 'all'];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected HandoffTodoService $handoffTodos,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('dx_delivery.handoff_todos'),
    );
  }

  /**
   * Board landing: /deliver/todos?blueprint=&status=.
   */
  public function board(Request $request): array {
    $filter = HandoffTodoService::normalizeFilter($request->query->get('status'));
    $blueprintArg = (int) $request->query->get('blueprint');
    $canComplete = $this->currentUser()->hasPermission('access dx delivery todos')
      || $this->currentUser()->hasPermission('administer dx delivery');

    $notice = '';
    $blueprints = [];
    if ($blueprintArg > 0) {
      $entity = $this->entityTypeManager->getStorage('dx_blueprint')->load($blueprintArg);
      if ($entity instanceof DeliveryBlueprint) {
        $blueprints = [$entity];
      }
      else {
        $notice = (string) $this->t('找不到蓝图 @id，已回落到最近蓝图。', ['@id' => $blueprintArg]);
        $blueprintArg = 0;
      }
    }
    if ($blueprints === []) {
      $blueprints = $this->recentBlueprints();
    }

    $rows = [];
    $allTodos = [];
    $unconfirmed = [];
    $blueprintTabs = [];
    foreach ($blueprints as $blueprint) {
      $todos = $this->handoffTodos->listFromBlueprint($blueprint);
      if ($todos === []) {
        if (in_array($blueprint->getStatus(), ['draft', 'confirmed'], TRUE)) {
          $unconfirmed[] = $blueprint->label();
        }
        continue;
      }
      $blueprintTabs[] = [
        'id' => (int) $blueprint->id(),
        'label' => (string) $blueprint->label(),
        'url' => (string) Url::fromRoute('dx_delivery.todo_board', [], [
          'query' => ['blueprint' => (string) $blueprint->id(), 'status' => $filter],
        ])->toString(),
        'open' => count(HandoffTodoService::filter($todos, 'open')),
        'active' => $blueprintArg === (int) $blueprint->id(),
      ];
      foreach (HandoffTodoService::filter($todos, $filter) as $todo) {
        if (!is_array($todo)) {
          continue;
        }
        $rows[] = $this->buildRow($blueprint, $todo, $canComplete);
      }
      $allTodos = array_merge($allTodos, $todos);
    }

    return [
      '#theme' => 'dx_delivery_todo_board',
      '#title' => $this->t('L3 交接工单看板'),
      '#intro' => $this->t('深业务系统不假装一键：这里是人工交接工单的签核台。'),
      '#notice' => $notice,
      '#filter' => $filter,
      '#tabs' => $this->buildTabs($filter),
      '#blueprint_tabs' => $blueprintTabs,
      '#blueprint_filter' => $blueprintArg,
      '#clear_url' => $blueprintArg > 0 ? (string) Url::fromRoute('dx_delivery.todo_board', [], [
        'query' => $filter === 'all' ? [] : ['status' => $filter],
      ])->toString() : '',
      '#rows' => $rows,
      '#stats' => HandoffTodoService::stats($allTodos),
      '#empty_text' => $this->emptyText($blueprints, $allTodos, $unconfirmed, $rows, $filter),
      '#empty_hint' => $this->emptyHint($blueprints),
      '#can_complete' => $canComplete,
      '#drush_hint' => 'drush dx:delivery-todo-done <蓝图ID> --batch=<id1,id2> --owner=<负责人> --due=<YYYY-MM-DD>',
      '#attached' => ['library' => ['dx_delivery/dx_delivery']],
      '#cache' => [
        'max-age' => 0,
        'contexts' => ['url.query_args', 'user'],
      ],
    ];
  }

  /**
   * Recent blueprints, newest change first.
   *
   * The entity type has no per-blueprint view grant (access runs off
   * `administer dx delivery`), so board visibility is decided by the route
   * permission and the query runs unaccess-checked on purpose.
   *
   * @return list<\Drupal\dx_delivery\Entity\DeliveryBlueprint>
   */
  protected function recentBlueprints(): array {
    $storage = $this->entityTypeManager->getStorage('dx_blueprint');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->sort('changed', 'DESC')
      ->range(0, self::BLUEPRINT_SCAN)
      ->execute();
    $entities = $ids ? $storage->loadMultiple($ids) : [];
    return array_values(array_filter($entities, static fn($entity): bool => $entity instanceof DeliveryBlueprint));
  }

  /**
   * One board row.
   *
   * Signed off rows never offer the sign-off form again: the board is a work
   * list, and re-signing a closed item through the CLI stays possible (it is
   * idempotent).
   *
   * @param array<string, mixed> $todo
   *
   * @return array<string, mixed>
   */
  protected function buildRow(DeliveryBlueprint $blueprint, array $todo, bool $canComplete): array {
    $id = (string) ($todo['id'] ?? '');
    $open = HandoffTodoService::isOpen($todo);
    return [
      'blueprint_id' => (int) $blueprint->id(),
      'blueprint_label' => (string) $blueprint->label(),
      'blueprint_status' => $blueprint->getStatus(),
      'blueprint_url' => (string) Url::fromRoute('dx_delivery.blueprint', [
        'dx_blueprint' => $blueprint->id(),
      ])->toString(),
      'id' => $id,
      'title' => (string) ($todo['title'] ?? $id),
      'status' => (string) ($todo['status'] ?? 'open'),
      'kind' => (string) ($todo['kind'] ?? ''),
      'owner' => (string) ($todo['owner'] ?? ''),
      'due' => (string) ($todo['due'] ?? ''),
      'remark' => (string) ($todo['remark'] ?? ''),
      'notes' => (string) ($todo['notes'] ?? ''),
      'done_at' => (string) ($todo['done_at'] ?? ''),
      'open' => $open,
      'overdue' => HandoffTodoService::isOverdue($todo),
      'complete_url' => $canComplete && $open && $id !== '' ? (string) Url::fromRoute('dx_delivery.todo_complete', [
        'dx_blueprint' => $blueprint->id(),
        'todo_id' => $id,
      ])->toString() : '',
      'drush' => sprintf('drush dx:delivery-todo-done %d %s', (int) $blueprint->id(), $id),
    ];
  }

  /**
   * Status tabs for the board.
   *
   * @return list<array{key: string, label: string, url: string, active: bool, count: int}>
   */
  protected function buildTabs(string $filter): array {
    $labels = [
      'open' => (string) $this->t('待办'),
      'done' => (string) $this->t('已签核'),
      'overdue' => (string) $this->t('已逾期'),
      'all' => (string) $this->t('全部'),
    ];
    $tabs = [];
    foreach (self::FILTERS as $key) {
      $tabs[] = [
        'key' => $key,
        'label' => $labels[$key],
        'url' => (string) Url::fromRoute('dx_delivery.todo_board', [], [
          'query' => $key === 'all' ? [] : ['status' => $key],
        ])->toString(),
        'active' => $key === $filter,
        'count' => 0,
      ];
    }
    return $tabs;
  }

  /**
   * Readable empty state instead of a blank page.
   *
   * @param list<\Drupal\dx_delivery\Entity\DeliveryBlueprint> $blueprints
   * @param list<array<string, mixed>> $todos
   * @param list<string> $unconfirmed
   * @param list<array<string, mixed>> $rows
   */
  protected function emptyText(array $blueprints, array $todos, array $unconfirmed, array $rows, string $filter): string {
    if ($blueprints === []) {
      return (string) $this->t('还没有可扫描的交付蓝图：先在选型下单或对话下单里创建蓝图。');
    }
    if ($todos === []) {
      if ($unconfirmed !== []) {
        return (string) $this->t('蓝图「@names」尚未确认执行，因此没有 L3 交接工单。', [
          '@names' => implode('、', $unconfirmed),
        ]);
      }
      return (string) $this->t('所选范围内没有 L3 交接工单。工单在蓝图执行且移植级别为 L3 时自动开立。');
    }
    if ($rows === []) {
      return (string) $this->t('当前筛选「@filter」下没有工单，切换到全部看看。', [
        '@filter' => $filter,
      ]);
    }
    return '';
  }

  /**
   * Second line of the empty state: where to act.
   *
   * @param list<\Drupal\dx_delivery\Entity\DeliveryBlueprint> $blueprints
   */
  protected function emptyHint(array $blueprints): string {
    if ($blueprints === []) {
      return (string) $this->t('入口：/deliver/wizard · /deliver/chat · 管理列表 /admin/dx/delivery');
    }
    return (string) $this->t('批量签核：drush dx:delivery-todo-done <蓝图ID> --batch=<id1,id2>（重复执行幂等，已完成项返回 skipped）');
  }

}
