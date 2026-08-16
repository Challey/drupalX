<?php

declare(strict_types=1);

namespace Drupal\dx_delivery;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Link;
use Drupal\Core\Url;

/**
 * Admin list of delivery blueprints.
 */
final class BlueprintListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['id'] = $this->t('ID');
    $header['label'] = $this->t('Label');
    $header['machine_name'] = $this->t('Tenant');
    $header['status'] = $this->t('Status');
    $header['site_type'] = $this->t('Type');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\dx_delivery\Entity\DeliveryBlueprint $entity */
    $row['id'] = $entity->id();
    $row['label'] = Link::fromTextAndUrl(
      $entity->label(),
      Url::fromRoute('dx_delivery.blueprint', ['dx_blueprint' => $entity->id()]),
    );
    $row['machine_name'] = $entity->getMachineName();
    $row['status'] = $entity->getStatus();
    $row['site_type'] = (string) $entity->get('site_type')->value;
    return $row + parent::buildRow($entity);
  }

}
