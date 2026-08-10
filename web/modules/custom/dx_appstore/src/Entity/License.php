<?php

declare(strict_types=1);

namespace Drupal\dx_appstore\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use Drupal\dx_appstore\AppStoreAccessControlHandler;
use Drupal\dx_appstore\Form\EntityDeleteForm;
use Drupal\dx_appstore\Form\EntityForm;
use Drupal\dx_appstore\LicenseListBuilder;
use Drupal\views\EntityViewsData;

/**
 * Defines the license entity.
 */
#[ContentEntityType(
  id: 'dx_license',
  label: new TranslatableMarkup('License'),
  label_collection: new TranslatableMarkup('Licenses'),
  label_singular: new TranslatableMarkup('license'),
  label_plural: new TranslatableMarkup('licenses'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
  ],
  handlers: [
    'storage' => SqlContentEntityStorage::class,
    'access' => AppStoreAccessControlHandler::class,
    'list_builder' => LicenseListBuilder::class,
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
    'collection' => '/admin/dx/appstore/licenses',
    'add-form' => '/admin/dx/appstore/licenses/add',
    'edit-form' => '/admin/dx/appstore/licenses/{dx_license}/edit',
    'delete-form' => '/admin/dx/appstore/licenses/{dx_license}/delete',
  ],
  admin_permission: 'administer dx appstore',
  base_table: 'dx_license',
  label_count: [
    'singular' => '@count license',
    'plural' => '@count licenses',
  ],
)]
class License extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['app_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('App'))
      ->setSetting('target_type', 'dx_app_package')
      ->setRequired(TRUE);

    $fields['tenant_machine'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Tenant machine name'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 128);

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Status'))
      ->setSetting('allowed_values', [
        'active' => 'Active',
        'expired' => 'Expired',
        'cancelled' => 'Cancelled',
      ])
      ->setDefaultValue('active');

    $fields['amount'] = BaseFieldDefinition::create('decimal')
      ->setLabel(new TranslatableMarkup('Amount'))
      ->setSetting('precision', 10)
      ->setSetting('scale', 2);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'));

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return (string) $this->t('License #@id', ['@id' => $this->id() ?: 'new']);
  }

}
