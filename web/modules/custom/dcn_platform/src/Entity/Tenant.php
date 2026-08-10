<?php

declare(strict_types=1);

namespace Drupal\dcn_platform\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\dcn_platform\Form\TenantDeleteForm;
use Drupal\dcn_platform\Form\TenantForm;
use Drupal\dcn_platform\Form\TenantProvisionConfirmForm;
use Drupal\dcn_platform\TenantAccessControlHandler;
use Drupal\dcn_platform\TenantListBuilder;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use Drupal\views\EntityViewsData;

/**
 * Defines the tenant entity.
 */
#[ContentEntityType(
  id: 'dcn_tenant',
  label: new TranslatableMarkup('Tenant'),
  label_collection: new TranslatableMarkup('Tenants'),
  label_singular: new TranslatableMarkup('tenant'),
  label_plural: new TranslatableMarkup('tenants'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'label',
  ],
  handlers: [
    'storage' => SqlContentEntityStorage::class,
    'access' => TenantAccessControlHandler::class,
    'list_builder' => TenantListBuilder::class,
    'views_data' => EntityViewsData::class,
    'form' => [
      'default' => TenantForm::class,
      'add' => TenantForm::class,
      'edit' => TenantForm::class,
      'delete' => TenantDeleteForm::class,
      'provision' => TenantProvisionConfirmForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  links: [
    'collection' => '/admin/dcn/tenants',
    'canonical' => '/admin/dcn/tenants/{dcn_tenant}',
    'add-form' => '/admin/dcn/tenants/add',
    'edit-form' => '/admin/dcn/tenants/{dcn_tenant}/edit',
    'delete-form' => '/admin/dcn/tenants/{dcn_tenant}/delete',
  ],
  admin_permission: 'administer dcn tenants',
  base_table: 'dcn_tenant',
  label_count: [
    'singular' => '@count tenant',
    'plural' => '@count tenants',
  ],
)]
class Tenant extends ContentEntityBase {

  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['machine_name'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Machine name'))
      ->setDescription(new TranslatableMarkup('Unique identifier used for site directory and database naming.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 64)
      ->addConstraint('UniqueField')
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -20,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Label'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -15,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Status'))
      ->setRequired(TRUE)
      ->setDefaultValue('draft')
      ->setSetting('allowed_values', [
        'draft' => 'Draft',
        'provisioning' => 'Provisioning',
        'active' => 'Active',
        'failed' => 'Failed',
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => -10,
      ])
      ->setDisplayOptions('view', [
        'type' => 'list_default',
        'weight' => -10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['subdomain'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Subdomain'))
      ->setSetting('max_length', 128)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['database_name'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Database name'))
      ->setSetting('max_length', 128)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 5,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['owner_mail'] = BaseFieldDefinition::create('email')
      ->setLabel(new TranslatableMarkup('Owner email'))
      ->setDisplayOptions('form', [
        'type' => 'email_default',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['portal_url'] = BaseFieldDefinition::create('uri')
      ->setLabel(new TranslatableMarkup('Portal URL'))
      ->setDisplayOptions('form', [
        'type' => 'uri',
        'weight' => 15,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'))
      ->setDisplayOptions('view', [
        'type' => 'timestamp',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'))
      ->setDisplayConfigurable('view', TRUE);

    return $fields;
  }

  /**
   * Gets the machine name.
   */
  public function getMachineName(): string {
    return (string) $this->get('machine_name')->value;
  }

  /**
   * Gets the provisioning status.
   */
  public function getStatus(): string {
    return (string) $this->get('status')->value;
  }

}
