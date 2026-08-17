<?php

declare(strict_types=1);

namespace Drupal\dx_delivery;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\dx_delivery\Entity\Blueprint;

/**
 * Defines a class to build a listing of delivery blueprint entities.
 */
class BlueprintListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['label'] = $this->t('Label');
    $header['machine_name'] = $this->t('Machine name');
    $header['site_type'] = $this->t('Site type');
    $header['status'] = $this->t('Status');
    $header['theme_skin'] = $this->t('Theme');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\dx_delivery\Entity\Blueprint $entity */
    $row['label'] = $entity->label();
    $row['machine_name'] = $entity->getMachineName();
    $row['site_type'] = (string) $entity->get('site_type')->value;
    $row['status'] = $entity->getStatus();
    $row['theme_skin'] = (string) $entity->get('theme_skin')->value;
    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultOperations(EntityInterface $entity): array {
    $operations = parent::getDefaultOperations($entity);
    /** @var \Drupal\dx_delivery\Entity\Blueprint $entity */
    $status = $entity->getStatus();

    if (in_array($status, ['draft', 'confirmed', 'failed'], TRUE)) {
      $operations['execute'] = [
        'title' => $this->t('Execute'),
        'weight' => 5,
        'url' => Url::fromRoute('entity.dx_blueprint.execute_form', ['dx_blueprint' => $entity->id()]),
      ];
    }

    if (in_array($status, ['completed', 'failed', 'running'], TRUE)) {
      $operations['acceptance'] = [
        'title' => $this->t('Acceptance'),
        'weight' => 10,
        'url' => Url::fromRoute('dx_delivery.acceptance', ['dx_blueprint' => $entity->id()]),
      ];
    }

    return $operations;
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    $build = parent::render();
    $build['table']['#empty'] = $this->t('No delivery orders yet. <a href=":url">Start a new order</a>.', [
      ':url' => Url::fromRoute('dx_delivery.wizard')->toString(),
    ]);
    return $build;
  }

}
