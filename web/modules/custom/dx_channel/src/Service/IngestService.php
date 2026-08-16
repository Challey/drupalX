<?php

declare(strict_types=1);

namespace Drupal\dx_channel\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\node\NodeInterface;

/**
 * DXEP Ingest upsert by external_id.
 */
final class IngestService {

  public const MAP_KEY = 'dx_channel.external_map';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected StateInterface $state,
    protected ContentProjector $projector,
  ) {}

  /**
   * Upsert resource.
   *
   * @param array<string, mixed> $payload
   *   DXEP resource fields (title, body, status, …).
   *
   * @return array{ok: bool, dry_run?: bool, resource?: array<string, mixed>, issues?: list<array<string, string>>}
   */
  public function upsert(string $type, string $externalId, array $payload, bool $dryRun = FALSE, bool $review = FALSE): array {
    $bundle = $this->projector->bundleForType($type);
    if ($bundle === NULL) {
      return [
        'ok' => FALSE,
        'issues' => [['field' => 'type', 'issue' => 'unsupported type']],
      ];
    }
    $title = trim((string) ($payload['title'] ?? ''));
    if ($title === '') {
      return [
        'ok' => FALSE,
        'issues' => [['field' => 'title', 'issue' => 'required']],
      ];
    }

    $bodyHtml = '';
    if (isset($payload['body']) && is_array($payload['body'])) {
      $bodyHtml = (string) ($payload['body']['html'] ?? $payload['body']['text'] ?? '');
    }
    elseif (isset($payload['body']) && is_string($payload['body'])) {
      $bodyHtml = $payload['body'];
    }

    $status = ($payload['status'] ?? 'published') === 'published' && !$review ? 1 : 0;

    if ($dryRun) {
      return [
        'ok' => TRUE,
        'dry_run' => TRUE,
        'resource' => [
          'type' => $type,
          'external_id' => $externalId,
          'title' => $title,
          'status' => $status ? 'published' : 'draft',
        ],
      ];
    }

    $map = $this->getMap();
    $key = $type . ':' . $externalId;
    $storage = $this->entityTypeManager->getStorage('node');
    $nid = $map[$key] ?? NULL;
    $node = $nid ? $storage->load($nid) : NULL;

    if (!$node instanceof NodeInterface) {
      $values = [
        'type' => $bundle,
        'title' => $title,
        'status' => $status,
        'body' => [
          'value' => $bodyHtml,
          'format' => 'basic_html',
          'summary' => mb_substr(trim(strip_tags($bodyHtml)), 0, 200),
        ],
      ];
      if ($bundle === 'dx_product') {
        $values['field_dx_sku'] = (string) ($payload['sku'] ?? $externalId);
        $price = '0.00';
        if (isset($payload['price']['amount'])) {
          $price = (string) $payload['price']['amount'];
        }
        $values['field_dx_price'] = $price;
      }
      $node = $storage->create($values);
    }
    else {
      $node->setTitle($title);
      if ($status) {
        $node->setPublished();
      }
      else {
        $node->setUnpublished();
      }
      if ($node->hasField('body')) {
        $node->set('body', [
          'value' => $bodyHtml,
          'format' => 'basic_html',
          'summary' => mb_substr(trim(strip_tags($bodyHtml)), 0, 200),
        ]);
      }
      if ($bundle === 'dx_product') {
        if ($node->hasField('field_dx_sku') && isset($payload['sku'])) {
          $node->set('field_dx_sku', (string) $payload['sku']);
        }
        if ($node->hasField('field_dx_price') && isset($payload['price']['amount'])) {
          $node->set('field_dx_price', (string) $payload['price']['amount']);
        }
      }
    }

    $node->save();
    $map[$key] = (int) $node->id();
    $this->state->set(self::MAP_KEY, $map);

    $projected = $this->projector->projectNode($node, $type, TRUE);
    $projected['external_id'] = $externalId;
    return [
      'ok' => TRUE,
      'resource' => $projected,
    ];
  }

  /**
   * @return array<string, int>
   */
  public function getExternalMap(): array {
    return $this->getMap();
  }

  /**
   * Remove external map entries pointing at a node id.
   */
  public function unmapNid(int $nid): int {
    $map = $this->getMap();
    $removed = 0;
    foreach ($map as $key => $mapped) {
      if ((int) $mapped === $nid) {
        unset($map[$key]);
        $removed++;
      }
    }
    if ($removed > 0) {
      $this->state->set(self::MAP_KEY, $map);
    }
    return $removed;
  }

  /**
   * @return array<string, int>
   */
  protected function getMap(): array {
    $map = $this->state->get(self::MAP_KEY, []);
    return is_array($map) ? $map : [];
  }

}
