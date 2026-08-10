<?php

declare(strict_types=1);

namespace Drupal\dx_appstore;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Link;

/**
 * List builder for revenue shares.
 */
class RevenueShareListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    return [
      'id' => $this->t('ID'),
      'license' => $this->t('License'),
      'amount' => $this->t('Amount'),
      'share_percent' => $this->t('Share %'),
      'status' => $this->t('Status'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\dx_appstore\Entity\RevenueShare $entity */
    return [
      'id' => Link::fromTextAndUrl((string) $entity->id(), $entity->toUrl('edit-form')),
      'license' => $entity->get('license_id')->target_id,
      'amount' => $entity->get('amount')->value,
      'share_percent' => $entity->get('share_percent')->value,
      'status' => $entity->get('status')->value,
    ] + parent::buildRow($entity);
  }

}
