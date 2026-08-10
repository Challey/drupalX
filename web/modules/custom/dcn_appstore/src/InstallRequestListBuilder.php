<?php

declare(strict_types=1);

namespace Drupal\dcn_appstore;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Link;

/**
 * List builder for install requests.
 */
class InstallRequestListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    return [
      'id' => $this->t('ID'),
      'app' => $this->t('App'),
      'tenant' => $this->t('Tenant'),
      'status' => $this->t('Status'),
      'created' => $this->t('Created'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\dcn_appstore\Entity\InstallRequest $entity */
    $app = $entity->get('app_id')->entity;
    return [
      'id' => Link::fromTextAndUrl((string) $entity->id(), $entity->toUrl('edit-form')),
      'app' => $app ? $app->label() : '-',
      'tenant' => $entity->get('tenant_machine')->value,
      'status' => $entity->get('status')->value,
      'created' => \Drupal::service('date.formatter')->format((int) $entity->get('created')->value),
    ] + parent::buildRow($entity);
  }

}
