<?php

declare(strict_types=1);

namespace Drupal\dx_appstore\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use Drupal\dx_appstore\AppStoreAccessControlHandler;
use Drupal\dx_appstore\AppPackageListBuilder;
use Drupal\dx_appstore\Form\AppPackageDeleteForm;
use Drupal\dx_appstore\Form\AppPackageForm;
use Drupal\views\EntityViewsData;

/**
 * Defines the app package entity.
 */
#[ContentEntityType(
  id: 'dx_app_package',
  label: new TranslatableMarkup('App package'),
  label_collection: new TranslatableMarkup('App packages'),
  label_singular: new TranslatableMarkup('app package'),
  label_plural: new TranslatableMarkup('app packages'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'label',
  ],
  handlers: [
    'storage' => SqlContentEntityStorage::class,
    'access' => AppStoreAccessControlHandler::class,
    'list_builder' => AppPackageListBuilder::class,
    'views_data' => EntityViewsData::class,
    'form' => [
      'default' => AppPackageForm::class,
      'add' => AppPackageForm::class,
      'edit' => AppPackageForm::class,
      'delete' => AppPackageDeleteForm::class,
    ],
    'route_provider' => ['html' => AdminHtmlRouteProvider::class],
  ],
  links: [
    'collection' => '/admin/dx/appstore/packages',
    'add-form' => '/admin/dx/appstore/packages/add',
    'edit-form' => '/admin/dx/appstore/packages/{dx_app_package}/edit',
    'delete-form' => '/admin/dx/appstore/packages/{dx_app_package}/delete',
    'canonical' => '/admin/dx/appstore/packages/{dx_app_package}',
  ],
  admin_permission: 'administer dx appstore',
  base_table: 'dx_app_package',
  label_count: [
    'singular' => '@count app package',
    'plural' => '@count app packages',
  ],
)]
class AppPackage extends ContentEntityBase {

  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['machine_name'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Machine name'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 128)
      ->addConstraint('UniqueField');

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Label'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255);

    $fields['category'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Category'))
      ->setRequired(TRUE)
      ->setSetting('allowed_values', [
        'ai' => 'AI',
        'social' => 'Social',
        'oss' => 'Open Source',
        'commerce' => 'Commerce',
        'marketing' => 'Marketing',
        'utility' => 'Utility',
      ]);

    $fields['project_url'] = BaseFieldDefinition::create('uri')
      ->setLabel(new TranslatableMarkup('Project URL'));

    $fields['trust_level'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Trust level'))
      ->setSetting('allowed_values', [
        'security' => 'Security reviewed',
        'stable' => 'Stable',
        'community' => 'Community',
      ])
      ->setDefaultValue('community');

    $fields['china_common'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Common in China'))
      ->setDefaultValue(FALSE);

    $fields['price'] = BaseFieldDefinition::create('decimal')
      ->setLabel(new TranslatableMarkup('Price'))
      ->setSetting('precision', 10)
      ->setSetting('scale', 2)
      ->setDefaultValue('0.00');

    $fields['revenue_share_percent'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Revenue share percent'))
      ->setDefaultValue(70);

    $fields['composer_package'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Composer package'))
      ->setSetting('max_length', 255);

    $fields['module_name'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Module name'))
      ->setSetting('max_length', 128);

    $fields['description'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Description'));

    $fields['license_family'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('License family'))
      ->setSetting('allowed_values', [
        'gpl' => 'GPL-2.0+',
        'dx_ral' => 'DX-RAL',
        'dual' => 'Dual (GPL adapter + DX-RAL library)',
      ])
      ->setDefaultValue('gpl');

    $fields['source_policy'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Source policy'))
      ->setSetting('allowed_values', [
        'public_framework' => 'Public framework (L0)',
        'tenant_visible' => 'Tenant-visible (L3)',
        'partner_vault' => 'Partner vault only (L2)',
      ])
      ->setDefaultValue('tenant_visible');

    $fields['dpa_required'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('DPA required for publisher'))
      ->setDefaultValue(FALSE);

    $fields['status'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Published'))
      ->setDefaultValue(TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'));

    return $fields;
  }

}
