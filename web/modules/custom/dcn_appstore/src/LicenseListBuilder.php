<?php

declare(strict_types=1);

namespace Drupal\dcn_appstore;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Link;

/**
 * List builder for licenses.
 */
class LicenseListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    return [
      'id' => $this->t('ID'),
      'app' => $this->t('App'),
      'tenant' => $this->t('Tenant'),
      'amount' => $this->t('Amount'),
      'status' => $this->t('Status'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\dcn_appstore\Entity\License $entity */
    $app = $entity->get('app_id')->entity;
    return [
      'id' => Link::fromTextAndUrl((string) $entity->id(), $entity->toUrl('edit-form')),
      'app' => $app ? $app->label() : '-',
      'tenant' => $entity->get('tenant_machine')->value,
      'amount' => $entity->get('amount')->value,
      'status' => $entity->get('status')->value,
    ] + parent::buildRow($entity);
  }

}
