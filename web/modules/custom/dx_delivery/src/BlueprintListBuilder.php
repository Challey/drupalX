<?php

declare(strict_types=1);

namespace Drupal\dx_delivery;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\dx_delivery\Entity\DeliveryBlueprint;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Admin list of delivery blueprints, partitioned by delivery state.
 */
final class BlueprintListBuilder extends EntityListBuilder {

  /**
   * Machine statuses backing the segments; used for the count queries.
   */
  private const COUNT_STATUSES = ['draft', 'confirmed', 'running', 'completed', 'failed'];

  public function __construct(
    EntityTypeInterface $entity_type,
    EntityStorageInterface $storage,
    protected ?RequestStack $requestStack = NULL,
  ) {
    parent::__construct($entity_type, $storage);
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    return new static(
      $entity_type,
      $container->get('entity_type.manager')->getStorage($entity_type->id()),
      $container->get('request_stack'),
    );
  }

  /**
   * The segment resolved from ?status=, normalised to a known key.
   */
  protected function currentSegment(): string {
    $request = $this->requestStack?->getCurrentRequest();
    return BlueprintStatusFilter::normalize($request?->query->get(BlueprintStatusFilter::QUERY_KEY));
  }

  /**
   * {@inheritdoc}
   */
  protected function getEntityListQuery(): QueryInterface {
    $query = parent::getEntityListQuery();
    $statuses = BlueprintStatusFilter::statuses($this->currentSegment());
    if ($statuses !== []) {
      $query->condition('status', $statuses, 'IN');
    }
    return $query;
  }

  /**
   * {@inheritdoc}
   *
   * The failed-retry partition adds a 失败重试 shortcut beside the operations
   * core already renders. Any other partition returns exactly what core would,
   * so /admin/dx/delivery keeps the column set and links it has on the live
   * site.
   */
  public function getOperations(EntityInterface $entity/* , ?CacheableMetadata $cacheability = NULL */) {
    $operations = parent::getOperations(...func_get_args());
    if ($this->currentSegment() === 'failed' && $entity instanceof DeliveryBlueprint) {
      // Failure is the one state an operator has to act on, so the retry
      // shortcut lives next to the row instead of hiding behind the entity id.
      $operations['delivery_retry'] = [
        'title' => $this->t('失败重试'),
        'weight' => 5,
        'url' => Url::fromRoute('dx_delivery.confirm', ['dx_blueprint' => $entity->id()], [
          'query' => [BlueprintStatusFilter::QUERY_KEY => 'failed'],
        ]),
      ];
      uasort($operations, '\Drupal\Component\Utility\SortArray::sortByWeightElement');
    }
    return $operations;
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['id'] = $this->t('ID');
    $header['label'] = $this->t('Label');
    $header['machine_name'] = $this->t('Tenant');
    $header['status'] = $this->t('Status');
    $header['site_type'] = $this->t('Type');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\dx_delivery\Entity\DeliveryBlueprint $entity */
    $row['id'] = $entity->id();
    $row['label'] = Link::fromTextAndUrl(
      $entity->label(),
      Url::fromRoute('dx_delivery.blueprint', ['dx_blueprint' => $entity->id()]),
    );
    $row['machine_name'] = $entity->getMachineName();
    $row['status'] = $entity->getStatus();
    $row['site_type'] = (string) $entity->get('site_type')->value;
    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   *
   * Prepends the four-state partition tabs (plain links + ?status=, no Views
   * dependency) and keeps the table itself exactly as it rendered before.
   */
  public function render(): array {
    $build = parent::render();
    $segment = $this->currentSegment();
    $counts = $this->countSegments();

    $tabs = [
      'status_filter' => [
        '#theme' => 'dx_delivery_status_tabs',
        '#label' => (string) $this->t('蓝图状态分区'),
        '#tabs' => $this->buildTabs($segment, $counts),
        '#attached' => ['library' => ['dx_delivery/dx_delivery']],
        '#cache' => ['contexts' => ['url.query_args:' . BlueprintStatusFilter::QUERY_KEY]],
      ],
    ];
    // Tabs render above the table; the table markup itself is untouched.
    $build = $tabs + $build;

    $build['table']['#cache']['contexts'][] = 'url.query_args:' . BlueprintStatusFilter::QUERY_KEY;
    $build['table']['#attributes']['class'][] = 'dx-delivery-blueprints';
    $build['table']['#attributes']['data-status-filter'] = $segment;
    if ($segment !== BlueprintStatusFilter::ALL) {
      $build['table']['#empty'] = $this->t('该状态分区下暂无蓝图，请切换其它分区或查看全部。');
    }

    return $build;
  }

  /**
   * Tab definitions for the partition bar.
   *
   * @param string $segment
   *   The active segment.
   * @param array<string, int> $counts
   *   Segment (and "all") => blueprint count.
   *
   * @return list<array{key: string, label: string, url: string, active: bool, count: int}>
   */
  protected function buildTabs(string $segment, array $counts): array {
    $labels = [
      BlueprintStatusFilter::ALL => (string) $this->t('全部'),
      'draft' => (string) $this->t('草稿'),
      'confirmed' => (string) $this->t('已确认'),
      'executed' => (string) $this->t('已执行'),
      'failed' => (string) $this->t('失败重试'),
    ];
    $keys = array_merge([BlueprintStatusFilter::ALL], BlueprintStatusFilter::segments());
    $tabs = [];
    foreach ($keys as $key) {
      $tabs[] = [
        'key' => $key,
        'label' => $labels[$key],
        'url' => (string) Url::fromRoute('dx_delivery.admin', [], [
          'query' => $key === BlueprintStatusFilter::ALL ? [] : [BlueprintStatusFilter::QUERY_KEY => $key],
        ])->toString(),
        'active' => $key === $segment,
        'count' => (int) ($counts[$key] ?? 0),
      ];
    }
    return $tabs;
  }

  /**
   * Blueprint counts per segment, access checked like the list itself.
   *
   * @return array<string, int>
   */
  protected function countSegments(): array {
    $statusCounts = [];
    foreach (self::COUNT_STATUSES as $status) {
      $statusCounts[$status] = (int) $this->getStorage()->getQuery()
        ->accessCheck(TRUE)
        ->condition('status', $status)
        ->count()
        ->execute();
    }
    return BlueprintStatusFilter::rollup($statusCounts);
  }

}
