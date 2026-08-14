<?php

declare(strict_types=1);

namespace Drupal\dx_ai_gateway\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Builds enterprise knowledge context from portal product/company nodes.
 */
class KnowledgeBase {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Whether knowledge injection is enabled.
   */
  public function isEnabled(): bool {
    return (bool) $this->configFactory->get('dx_ai_gateway.settings')->get('inject_knowledge_base');
  }

  /**
   * Builds a compact knowledge block for the system prompt.
   */
  public function buildContext(): string {
    if (!$this->isEnabled()) {
      return '';
    }

    $parts = [];
    $tenant = $this->configFactory->get('dx_tenant.settings');
    $company = trim((string) ($tenant->get('company_name') ?: ''));
    $industry = trim((string) ($tenant->get('industry') ?: ''));
    if ($company !== '' || $industry !== '') {
      $line = '企业资料：';
      if ($company !== '') {
        $line .= $company;
      }
      if ($industry !== '') {
        $line .= ($company !== '' ? '（' . $industry . '）' : $industry);
      }
      $parts[] = $line;
    }

    $products = $this->loadProductSummaries();
    if ($products) {
      $parts[] = "产品目录：\n" . implode("\n", $products);
    }

    if (!$parts) {
      return '';
    }

    return "以下是本站企业资料与产品摘要，回答访客问题时优先参考；若资料不足请说明并引导联系人工。\n"
      . implode("\n", $parts);
  }

  /**
   * Loads published product summaries.
   *
   * @return string[]
   */
  protected function loadProductSummaries(): array {
    if (!$this->moduleHandler->moduleExists('node')) {
      return [];
    }

    $storage = $this->entityTypeManager->getStorage('node');
    $definitions = $this->entityTypeManager->getDefinitions();
    if (!isset($definitions['node'])) {
      return [];
    }

    // Skip when product bundle is not installed yet.
    $type_storage = $this->entityTypeManager->getStorage('node_type');
    if (!$type_storage->load('dx_product')) {
      return [];
    }

    $limit = (int) ($this->configFactory->get('dx_ai_gateway.settings')->get('knowledge_max_products') ?: 20);
    $limit = max(1, min(50, $limit));

    try {
      $nids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'dx_product')
        ->condition('status', 1)
        ->sort('changed', 'DESC')
        ->range(0, $limit)
        ->execute();
    }
    catch (\Throwable) {
      return [];
    }

    if (!$nids) {
      return [];
    }

    $lines = [];
    foreach ($storage->loadMultiple($nids) as $node) {
      $sku = $node->hasField('field_dx_sku') ? trim((string) $node->get('field_dx_sku')->value) : '';
      $price = $node->hasField('field_dx_price') ? trim((string) $node->get('field_dx_price')->value) : '';
      $summary = '';
      if ($node->hasField('body') && !$node->get('body')->isEmpty()) {
        $summary = trim(strip_tags((string) ($node->get('body')->summary ?: $node->get('body')->value)));
        $summary = mb_substr(preg_replace('/\s+/u', ' ', $summary) ?: '', 0, 160);
      }
      $bits = ['- ' . $node->label()];
      if ($sku !== '') {
        $bits[] = 'SKU:' . $sku;
      }
      if ($price !== '') {
        $bits[] = '价格:' . $price;
      }
      if ($summary !== '') {
        $bits[] = $summary;
      }
      $lines[] = implode(' | ', $bits);
    }
    return $lines;
  }

}
