<?php

declare(strict_types=1);

namespace Drupal\dx_platform;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Link;
use Drupal\Core\Url;

/**
 * Defines a class to build a listing of tenant entities.
 */
class TenantListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['label'] = $this->t('Label');
    $header['machine_name'] = $this->t('Machine name');
    $header['status'] = $this->t('Status');
    $header['subdomain'] = $this->t('Subdomain');
    $header['owner_mail'] = $this->t('Owner email');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\dx_platform\Entity\Tenant $entity */
    $row['label'] = Link::fromTextAndUrl(
      $entity->label(),
      $entity->toUrl('edit-form')
    );
    $row['machine_name'] = $entity->getMachineName();
    $row['status'] = $entity->getStatus();
    $row['subdomain'] = $entity->get('subdomain')->value;
    $row['owner_mail'] = $entity->get('owner_mail')->value;
    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    $build = parent::render();
    $build['table']['#empty'] = $this->t('No tenants have been created yet.');
    return $build;
  }

}
