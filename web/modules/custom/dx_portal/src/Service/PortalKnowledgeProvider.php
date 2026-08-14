<?php

declare(strict_types=1);

namespace Drupal\dx_portal\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Component\Utility\Html;

/**
 * Supplies published portal content as bounded chat context.
 */
class PortalKnowledgeProvider {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns concise, access-checked portal knowledge for an AI prompt.
   */
  public function getContext(string $query): string {
    $storage = $this->entityTypeManager->getStorage('node');
    $nids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', ['dx_product', 'dx_company', 'dx_media'], 'IN')
      ->condition('status', 1)
      ->sort('changed', 'DESC')
      ->range(0, 8)
      ->execute();
    $items = [];
    $length = 0;

    foreach ($storage->loadMultiple($nids) as $node) {
      $text = $node->hasField('body') ? ($node->get('body')->summary ?: $node->get('body')->value) : '';
      $text = trim(Html::decodeEntities(strip_tags((string) $text)));
      if ($node->bundle() === 'dx_product') {
        $sku = $node->hasField('field_dx_sku') ? (string) $node->get('field_dx_sku')->value : '';
        $price = $node->hasField('field_dx_price') ? (string) $node->get('field_dx_price')->value : '';
        $text = trim($text . ($sku !== '' ? " SKU: {$sku}." : '') . ($price !== '' ? " Price: {$price}." : ''));
      }
      $entry = sprintf('[%s] %s: %s', $node->bundle(), $node->label(), mb_substr($text, 0, 900));
      if ($length + mb_strlen($entry) > 6000) {
        break;
      }
      $items[] = $entry;
      $length += mb_strlen($entry);
    }

    return implode("\n", $items);
  }

}
