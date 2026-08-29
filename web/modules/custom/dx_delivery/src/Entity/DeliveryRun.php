<?php

declare(strict_types=1);

namespace Drupal\dx_delivery\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use Drupal\dx_delivery\DeliveryRunAccessControlHandler;
use Drupal\views\EntityViewsData;

/**
 * Defines a single delivery pipeline step log entry.
 */
#[ContentEntityType(
  id: 'dx_delivery_run',
  label: new TranslatableMarkup('Delivery run step'),
  label_collection: new TranslatableMarkup('Delivery run steps'),
  label_singular: new TranslatableMarkup('delivery run step'),
  label_plural: new TranslatableMarkup('delivery run steps'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
  ],
  handlers: [
    'storage' => SqlContentEntityStorage::class,
    'access' => DeliveryRunAccessControlHandler::class,
    'views_data' => EntityViewsData::class,
  ],
  admin_permission: 'administer dx delivery',
  base_table: 'dx_delivery_run',
  label_count: [
    'singular' => '@count delivery step',
    'plural' => '@count delivery steps',
  ],
)]
class DeliveryRun extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['blueprint_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Blueprint'))
      ->setSetting('target_type', 'dx_blueprint')
      ->setRequired(TRUE);

    $fields['stage'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Stage'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 8);

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Status'))
      ->setRequired(TRUE)
      ->setSetting('allowed_values', [
        'pending' => 'Pending',
        'running' => 'Running',
        'completed' => 'Completed',
        'failed' => 'Failed',
        'skipped' => 'Skipped',
      ])
      ->setDefaultValue('pending');

    $fields['message'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Message'));

    $fields['started'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Started'));

    $fields['finished'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Finished'));

    $fields['operator_uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Operator'))
      ->setSetting('target_type', 'user');

    return $fields;
  }

}
