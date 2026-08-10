<?php

declare(strict_types=1);

namespace Drupal\dcn_appstore\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use Drupal\dcn_appstore\AppStoreAccessControlHandler;
use Drupal\dcn_appstore\Form\EntityDeleteForm;
use Drupal\dcn_appstore\Form\EntityForm;
use Drupal\dcn_appstore\RevenueShareListBuilder;
use Drupal\views\EntityViewsData;

/**
 * Defines the revenue share entity.
 */
#[ContentEntityType(
  id: 'dcn_revenue_share',
  label: new TranslatableMarkup('Revenue share'),
  label_collection: new TranslatableMarkup('Revenue shares'),
  label_singular: new TranslatableMarkup('revenue share'),
  label_plural: new TranslatableMarkup('revenue shares'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
  ],
  handlers: [
    'storage' => SqlContentEntityStorage::class,
    'access' => AppStoreAccessControlHandler::class,
    'list_builder' => RevenueShareListBuilder::class,
    'views_data' => EntityViewsData::class,
    'form' => [
      'default' => EntityForm::class,
      'add' => EntityForm::class,
      'edit' => EntityForm::class,
      'delete' => EntityDeleteForm::class,
    ],
    'route_provider' => ['html' => AdminHtmlRouteProvider::class],
  ],
  links: [
    'collection' => '/admin/dcn/appstore/revenue',
    'add-form' => '/admin/dcn/appstore/revenue/add',
    'edit-form' => '/admin/dcn/appstore/revenue/{dcn_revenue_share}/edit',
    'delete-form' => '/admin/dcn/appstore/revenue/{dcn_revenue_share}/delete',
  ],
  admin_permission: 'administer dcn appstore',
  base_table: 'dcn_revenue_share',
  label_count: [
    'singular' => '@count revenue share',
    'plural' => '@count revenue shares',
  ],
)]
class RevenueShare extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['license_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('License'))
      ->setSetting('target_type', 'dcn_license')
      ->setRequired(TRUE);

    $fields['developer_uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Developer'))
      ->setSetting('target_type', 'user');

    $fields['amount'] = BaseFieldDefinition::create('decimal')
      ->setLabel(new TranslatableMarkup('Amount'))
      ->setSetting('precision', 10)
      ->setSetting('scale', 2);

    $fields['share_percent'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Share percent'))
      ->setDefaultValue(70);

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Status'))
      ->setSetting('allowed_values', [
        'pending' => 'Pending',
        'paid' => 'Paid',
      ])
      ->setDefaultValue('pending');

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return (string) $this->t('Revenue share #@id', ['@id' => $this->id() ?: 'new']);
  }

}
