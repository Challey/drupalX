<?php

declare(strict_types=1);

namespace Drupal\dcn_appstore\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use Drupal\dcn_appstore\AppStoreAccessControlHandler;
use Drupal\dcn_appstore\AppPackageListBuilder;
use Drupal\dcn_appstore\Form\AppPackageDeleteForm;
use Drupal\dcn_appstore\Form\AppPackageForm;
use Drupal\views\EntityViewsData;

/**
 * Defines the app package entity.
 */
#[ContentEntityType(
  id: 'dcn_app_package',
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
    'collection' => '/admin/dcn/appstore/packages',
    'add-form' => '/admin/dcn/appstore/packages/add',
    'edit-form' => '/admin/dcn/appstore/packages/{dcn_app_package}/edit',
    'delete-form' => '/admin/dcn/appstore/packages/{dcn_app_package}/delete',
    'canonical' => '/admin/dcn/appstore/packages/{dcn_app_package}',
  ],
  admin_permission: 'administer dcn appstore',
  base_table: 'dcn_app_package',
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
