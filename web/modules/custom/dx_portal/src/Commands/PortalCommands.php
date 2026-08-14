<?php

declare(strict_types=1);

namespace Drupal\dx_portal\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\Entity\Node;
use Drush\Commands\DrushCommands;

/**
 * Portal content seed helpers.
 */
class PortalCommands extends DrushCommands {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  /**
   * Seed demo product and media nodes for portal pages.
   *
   * @command dx:portal-seed
   * @option industry
   *   Optional industry hint: manufacturing|retail|services
   */
  public function seed(array $options = ['industry' => '']): void {
    $industry = (string) ($options['industry'] ?? '');
    $products = match ($industry) {
      'retail' => [
        ['旗舰款智能手环', 'RB-100', '299.00', '轻量续航，适合日常运动与门店导购演示。'],
        ['会员礼盒 A', 'RB-GIFT', '99.00', '节庆促销礼盒，含优惠券与周边。'],
      ],
      'services' => [
        ['标准咨询套餐', 'SV-STD', '1500.00', '含需求访谈与方案建议书。'],
        ['年度运维服务', 'SV-OPS', '28000.00', '7×12 响应，含巡检与培训。'],
      ],
      default => [
        ['数控机床 DX-200', 'MF-200', '128000.00', '中型精密加工，支持多轴联动与远程诊断。'],
        ['工业传感器套件', 'MF-SEN', '8600.00', '产线温度/振动监测，适配 DrupalX 门户展示。'],
      ],
    };

    $created = 0;
    foreach ($products as [$title, $sku, $price, $body]) {
      if ($this->nodeExists('dx_product', $title)) {
        continue;
      }
      $node = Node::create([
        'type' => 'dx_product',
        'title' => $title,
        'status' => 1,
        'body' => ['value' => $body, 'format' => 'basic_html', 'summary' => $body],
      ]);
      if ($node->hasField('field_dx_sku')) {
        $node->set('field_dx_sku', $sku);
      }
      if ($node->hasField('field_dx_price')) {
        $node->set('field_dx_price', $price);
      }
      $node->save();
      $created++;
    }

    $mediaTitle = '门户上线公告';
    if (!$this->nodeExists('dx_media', $mediaTitle)) {
      Node::create([
        'type' => 'dx_media',
        'title' => $mediaTitle,
        'status' => 1,
        'body' => [
          'value' => 'DrupalX 门户产品与媒体中心已就绪，欢迎通过 AI 客服咨询。',
          'format' => 'basic_html',
          'summary' => 'DrupalX 门户产品与媒体中心已就绪。',
        ],
      ])->save();
      $created++;
    }

    $this->logger()->success("Portal seed complete ({$created} nodes created).");
  }

  /**
   * Checks for an existing node by type + title.
   */
  protected function nodeExists(string $type, string $title): bool {
    $ids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $type)
      ->condition('title', $title)
      ->range(0, 1)
      ->execute();
    return (bool) $ids;
  }

}
