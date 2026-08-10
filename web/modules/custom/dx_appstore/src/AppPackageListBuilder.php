<?php

declare(strict_types=1);

namespace Drupal\dx_appstore;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Link;

/**
 * List builder for app packages.
 */
class AppPackageListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    return [
      'label' => $this->t('Label'),
      'machine_name' => $this->t('Machine name'),
      'category' => $this->t('Category'),
      'trust_level' => $this->t('Trust'),
      'status' => $this->t('Published'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\dx_appstore\Entity\AppPackage $entity */
    return [
      'label' => Link::fromTextAndUrl($entity->label(), $entity->toUrl('edit-form')),
      'machine_name' => $entity->get('machine_name')->value,
      'category' => $entity->get('category')->value,
      'trust_level' => $entity->get('trust_level')->value,
      'status' => $entity->get('status')->value ? $this->t('Yes') : $this->t('No'),
    ] + parent::buildRow($entity);
  }

}
