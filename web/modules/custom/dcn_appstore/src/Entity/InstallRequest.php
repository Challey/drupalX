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
use Drupal\dcn_appstore\InstallRequestListBuilder;
use Drupal\views\EntityViewsData;

/**
 * Defines the install request entity.
 */
#[ContentEntityType(
  id: 'dcn_install_request',
  label: new TranslatableMarkup('Install request'),
  label_collection: new TranslatableMarkup('Install requests'),
  label_singular: new TranslatableMarkup('install request'),
  label_plural: new TranslatableMarkup('install requests'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
  ],
  handlers: [
    'storage' => SqlContentEntityStorage::class,
    'access' => AppStoreAccessControlHandler::class,
    'list_builder' => InstallRequestListBuilder::class,
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
    'collection' => '/admin/dcn/appstore/requests',
    'add-form' => '/admin/dcn/appstore/requests/add',
    'edit-form' => '/admin/dcn/appstore/requests/{dcn_install_request}/edit',
    'delete-form' => '/admin/dcn/appstore/requests/{dcn_install_request}/delete',
  ],
  admin_permission: 'administer dcn appstore',
  base_table: 'dcn_install_request',
  label_count: [
    'singular' => '@count install request',
    'plural' => '@count install requests',
  ],
)]
class InstallRequest extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['app_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('App'))
      ->setSetting('target_type', 'dcn_app_package')
      ->setRequired(TRUE);

    $fields['tenant_machine'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Tenant machine name'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 128);

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Status'))
      ->setSetting('allowed_values', [
        'pending' => 'Pending',
        'approved' => 'Approved',
        'installed' => 'Installed',
        'rejected' => 'Rejected',
      ])
      ->setDefaultValue('pending');

    $fields['requester_uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Requester'))
      ->setSetting('target_type', 'user');

    $fields['notes'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Notes'));

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'));

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return (string) $this->t('Install request #@id', ['@id' => $this->id() ?: 'new']);
  }

}
