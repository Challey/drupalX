<?php

declare(strict_types=1);

namespace Drupal\dx_channel\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Projects portal nodes to DXEP Channel content resources.
 */
final class ContentProjector {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * List contents.
   *
   * @return array{items: list<array<string, mixed>>, total: int}
   */
  public function list(string $type, int $page, int $pageSize, bool $includeBody = FALSE): array {
    $bundle = $this->bundleForType($type);
    if ($bundle === NULL) {
      return ['items' => [], 'total' => 0];
    }

    $storage = $this->entityTypeManager->getStorage('node');
    $countQuery = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', $bundle)
      ->condition('status', 1)
      ->count();
    $total = (int) $countQuery->execute();

    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', $bundle)
      ->condition('status', 1)
      ->sort('changed', 'DESC')
      ->range(($page - 1) * $pageSize, $pageSize);
    $nids = $query->execute();
    $nodes = $storage->loadMultiple($nids);

    $items = [];
    foreach ($nodes as $node) {
      if ($node instanceof NodeInterface) {
        $items[] = $this->projectNode($node, $type, $includeBody);
      }
    }
    return ['items' => $items, 'total' => $total];
  }

  /**
   * Load one by DX id (art_<nid> / prd_<nid>) or raw nid.
   *
   * @return array<string, mixed>|null
   */
  public function getByDxId(string $id, bool $includeBody = TRUE): ?array {
    $nid = $this->parseNid($id);
    if ($nid === NULL) {
      return NULL;
    }
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node instanceof NodeInterface || !$node->isPublished()) {
      return NULL;
    }
    $type = $this->typeForBundle($node->bundle());
    return $this->projectNode($node, $type, $includeBody);
  }

  /**
   * @return array<string, mixed>
   */
  public function projectNode(NodeInterface $node, string $type, bool $includeBody): array {
    $summary = '';
    $html = '';
    $text = '';
    if ($node->hasField('body') && !$node->get('body')->isEmpty()) {
      $html = (string) ($node->get('body')->value ?? '');
      $summary = trim(strip_tags((string) ($node->get('body')->summary ?: $html)));
      $summary = mb_substr(preg_replace('/\s+/u', ' ', $summary) ?: '', 0, 220);
      $text = trim(strip_tags($html));
    }

    $idPrefix = match ($type) {
      'product' => 'prd_',
      'notice' => 'ntc_',
      default => 'art_',
    };

    $item = [
      'id' => $idPrefix . $node->id(),
      'type' => $type,
      'status' => $node->isPublished() ? 'published' : 'draft',
      'visibility' => 'public',
      'title' => (string) $node->label(),
      'summary' => $summary !== '' ? $summary : NULL,
      'locale' => 'zh-CN',
      'channel' => ['web', 'miniprogram', 'app'],
      'published_at' => gmdate('c', (int) $node->getCreatedTime()),
      'updated_at' => gmdate('c', (int) $node->getChangedTime()),
      'created_at' => gmdate('c', (int) $node->getCreatedTime()),
      'external_id' => NULL,
    ];

    if ($type === 'product') {
      $amount = $node->hasField('field_dx_price') ? (string) $node->get('field_dx_price')->value : '0.00';
      $item['sku'] = $node->hasField('field_dx_sku') ? (string) $node->get('field_dx_sku')->value : NULL;
      $item['price'] = [
        'amount' => $amount !== '' ? $amount : '0.00',
        'currency' => 'CNY',
      ];
    }

    if ($includeBody) {
      $item['body'] = [
        'format' => 'html',
        'html' => $html,
        'text' => $text,
      ];
    }

    return $item;
  }

  public function bundleForType(string $type): ?string {
    return match ($type) {
      'article', 'notice' => 'dx_media',
      'product' => 'dx_product',
      default => NULL,
    };
  }

  public function typeForBundle(string $bundle): string {
    return match ($bundle) {
      'dx_product' => 'product',
      default => 'article',
    };
  }

  protected function parseNid(string $id): ?int {
    if (preg_match('/^(art|ntc|prd)_(\d+)$/', $id, $m)) {
      return (int) $m[2];
    }
    if (ctype_digit($id)) {
      return (int) $id;
    }
    return NULL;
  }

}
