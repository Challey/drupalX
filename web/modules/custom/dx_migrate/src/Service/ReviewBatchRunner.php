<?php

declare(strict_types=1);

namespace Drupal\dx_migrate\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\dx_channel\Service\IngestService;
use Drupal\node\NodeInterface;

/**
 * Executes review-queue batch operations (roadmap G2).
 *
 * Every action runs item by item: a failing item is recorded and the run
 * continues, because already-published nodes must not be rolled back by a later
 * timeout. The aggregated report is built by {@see \Drupal\dx_migrate\Service\ReviewBatch}.
 */
final class ReviewBatchRunner {

  public function __construct(
    private readonly EntityTypeManagerInterface $entities,
    private readonly IngestService $ingest,
    private readonly ReviewPayloadStore $snapshots,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Run one batch action.
   *
   * @param array<array-key, mixed> $ids
   *   Node ids (publish/discard) or external ids (replay).
   *
   * @return array<string, mixed>
   *   A {@see \Drupal\dx_migrate\Service\ReviewBatch::report()} payload.
   */
  public function run(string $action, array $ids, int $limit = ReviewBatch::MAX_ITEMS): array {
    if (!ReviewBatch::isAction($action)) {
      throw new \InvalidArgumentException('Unknown review batch action: ' . $action);
    }
    $plan = ReviewBatch::normalize($ids, $limit, $action === ReviewBatch::ACTION_REPLAY ? 'external_id' : 'nid');
    $results = [];
    foreach ($plan['accepted'] as $id) {
      $results = match ($action) {
        ReviewBatch::ACTION_PUBLISH => $this->publishOne($results, (int) $id),
        ReviewBatch::ACTION_DISCARD => $this->discardOne($results, (int) $id),
        default => $this->replayOne($results, (string) $id),
      };
    }
    $report = ReviewBatch::report($action, $plan, $results);
    $this->logger->notice(sprintf(
      'dx_migrate review batch %s: %d/%d ok, %d failed, %d rejected',
      $action,
      $report['succeeded'],
      $report['total'],
      $report['failed'],
      count($report['rejected']),
    ));
    return $report;
  }

  /**
   * Pending review drafts as lightweight rows for the batch form / Drush.
   *
   * @return list<array{nid: int, title: string, bundle: string, external_ids: list<string>, published: bool}>
   */
  public function pending(int $limit = 200, string $bundle = ''): array {
    $map = $this->ingest->getExternalMap();
    $nidToKeys = [];
    foreach ($map as $key => $nid) {
      $nidToKeys[(int) $nid][] = (string) $key;
    }
    $rows = [];
    if ($nidToKeys === []) {
      return $rows;
    }
    $storage = $this->entities->getStorage('node');
    foreach ($storage->loadMultiple(array_keys($nidToKeys)) as $node) {
      if (!$node instanceof NodeInterface || $node->isPublished()) {
        continue;
      }
      if ($bundle !== '' && $node->bundle() !== $bundle) {
        continue;
      }
      $rows[] = [
        'nid' => (int) $node->id(),
        'title' => (string) $node->label(),
        'bundle' => $node->bundle(),
        'external_ids' => $nidToKeys[(int) $node->id()] ?? [],
        'published' => FALSE,
      ];
      if (count($rows) >= max(1, $limit)) {
        break;
      }
    }
    usort($rows, static fn(array $a, array $b): int => $a['nid'] <=> $b['nid']);
    return $rows;
  }

  /**
   * @param list<array{key: string, ok: bool, message: string}> $results
   *
   * @return list<array{key: string, ok: bool, message: string}>
   */
  private function publishOne(array $results, int $nid): array {
    $key = (string) $nid;
    try {
      $node = $this->entities->getStorage('node')->load($nid);
      if (!$node instanceof NodeInterface) {
        return ReviewBatch::record($results, $key, FALSE, '节点不存在');
      }
      if ($node->isPublished()) {
        return ReviewBatch::record($results, $key, TRUE, '已是发布状态：' . $node->label());
      }
      $node->setPublished();
      $node->save();
      return ReviewBatch::record($results, $key, TRUE, '已发布：' . $node->label());
    }
    catch (\Throwable $e) {
      return ReviewBatch::record($results, $key, FALSE, '发布失败：' . $e->getMessage());
    }
  }

  /**
   * @param list<array{key: string, ok: bool, message: string}> $results
   *
   * @return list<array{key: string, ok: bool, message: string}>
   */
  private function discardOne(array $results, int $nid): array {
    $key = (string) $nid;
    try {
      $node = $this->entities->getStorage('node')->load($nid);
      if (!$node instanceof NodeInterface) {
        return ReviewBatch::record($results, $key, FALSE, '节点不存在');
      }
      $title = (string) $node->label();
      $map = $this->ingest->getExternalMap();
      $snapshotKeys = [];
      foreach (array_keys($this->snapshots->all()) as $storeKey) {
        if ((int) ($map[(string) $storeKey] ?? 0) === $nid) {
          $snapshotKeys[] = (string) $storeKey;
        }
      }
      $node->delete();
      $removed = $this->ingest->unmapNid($nid);
      foreach ($snapshotKeys as $storeKey) {
        $this->snapshots->forgetKey((string) $storeKey);
      }
      return ReviewBatch::record($results, $key, TRUE, '已丢弃：' . $title . '（清除映射 ' . $removed . '）');
    }
    catch (\Throwable $e) {
      return ReviewBatch::record($results, $key, FALSE, '丢弃失败：' . $e->getMessage());
    }
  }

  /**
   * @param list<array{key: string, ok: bool, message: string}> $results
   *
   * @return list<array{key: string, ok: bool, message: string}>
   */
  private function replayOne(array $results, string $externalId): array {
    try {
      $snapshot = $this->snapshots->find($externalId);
      if ($snapshot === NULL) {
        return ReviewBatch::record($results, $externalId, FALSE, '无 payload 快照，无法重放（请用 dx:migrate-l2 重新抓取）');
      }
      $type = (string) ($snapshot['type'] ?? 'article');
      $payload = is_array($snapshot['payload'] ?? NULL) ? $snapshot['payload'] : [];
      if ($payload === []) {
        return ReviewBatch::record($results, $externalId, FALSE, 'payload 快照为空');
      }
      // review = TRUE keeps a replayed item in the queue; upsert itself is
      // idempotent on `type:external_id`, so a replay never doubles a node.
      $result = $this->ingest->upsert($type, $externalId, $payload, FALSE, TRUE);
      if (!empty($result['ok'])) {
        $nid = (int) ($this->ingest->getExternalMap()[$type . ':' . $externalId] ?? 0);
        return ReviewBatch::record($results, $externalId, TRUE, '已重放：' . $type . ':' . $externalId . ($nid ? ' → nid ' . $nid : ''));
      }
      $issue = $result['issues'][0]['issue'] ?? 'unknown';
      $field = $result['issues'][0]['field'] ?? '?';
      return ReviewBatch::record($results, $externalId, FALSE, 'Ingest 拒绝：' . $field . ' ' . $issue);
    }
    catch (\Throwable $e) {
      return ReviewBatch::record($results, $externalId, FALSE, '重放失败：' . $e->getMessage());
    }
  }

}
