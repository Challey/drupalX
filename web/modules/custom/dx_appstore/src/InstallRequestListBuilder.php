<?php

declare(strict_types=1);

namespace Drupal\dx_appstore;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Link;
use Drupal\Core\Url;

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
    /** @var \Drupal\dx_appstore\Entity\InstallRequest $entity */
    $app = $entity->get('app_id')->entity;
    return [
      'id' => Link::fromTextAndUrl((string) $entity->id(), $entity->toUrl('edit-form')),
      'app' => $app ? $app->label() : '-',
      'tenant' => $entity->get('tenant_machine')->value,
      'status' => $entity->get('status')->value,
      'created' => \Drupal::service('date.formatter')->format((int) $entity->get('created')->value),
    ] + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultOperations(EntityInterface $entity): array {
    /** @var \Drupal\dx_appstore\Entity\InstallRequest $entity */
    $operations = parent::getDefaultOperations($entity);
    $status = (string) $entity->get('status')->value;
    if ($status === 'pending') {
      $operations['approve'] = [
        'title' => $this->t('Approve'),
        'weight' => 10,
        'url' => Url::fromRoute('dx_appstore.request_approve', ['dx_install_request' => $entity->id()]),
      ];
      $operations['reject'] = [
        'title' => $this->t('Reject'),
        'weight' => 11,
        'url' => Url::fromRoute('dx_appstore.request_reject', ['dx_install_request' => $entity->id()]),
      ];
    }
    if ($status === 'approved') {
      $operations['install'] = [
        'title' => $this->t('Install'),
        'weight' => 10,
        'url' => Url::fromRoute('dx_appstore.request_install', ['dx_install_request' => $entity->id()]),
      ];
      $operations['reject'] = [
        'title' => $this->t('Reject'),
        'weight' => 11,
        'url' => Url::fromRoute('dx_appstore.request_reject', ['dx_install_request' => $entity->id()]),
      ];
    }
    return $operations;
  }

}
