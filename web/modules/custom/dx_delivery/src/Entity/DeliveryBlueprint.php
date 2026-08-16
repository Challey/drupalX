<?php

declare(strict_types=1);

namespace Drupal\dx_delivery\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\dx_delivery\BlueprintListBuilder;
use Drupal\views\EntityViewsData;

/**
 * Turnkey delivery blueprint.
 */
#[ContentEntityType(
  id: 'dx_blueprint',
  label: new TranslatableMarkup('Delivery blueprint'),
  label_collection: new TranslatableMarkup('Delivery blueprints'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'label',
  ],
  handlers: [
    'storage' => SqlContentEntityStorage::class,
    'list_builder' => BlueprintListBuilder::class,
    'views_data' => EntityViewsData::class,
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  links: [
    'collection' => '/admin/dx/delivery',
    'canonical' => '/admin/dx/delivery/{dx_blueprint}',
  ],
  admin_permission: 'administer dx delivery',
  base_table: 'dx_blueprint',
)]
class DeliveryBlueprint extends ContentEntityBase {

  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Label'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayConfigurable('view', TRUE);

    $fields['machine_name'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Tenant machine name'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 64);

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Status'))
      ->setRequired(TRUE)
      ->setDefaultValue('draft')
      ->setSetting('allowed_values', [
        'draft' => 'Draft',
        'confirmed' => 'Confirmed',
        'running' => 'Running',
        'completed' => 'Completed',
        'failed' => 'Failed',
      ]);

    $fields['site_type'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Site type'))
      ->setRequired(TRUE)
      ->setDefaultValue('government')
      ->setSetting('allowed_values', [
        'government' => 'Government',
        'enterprise' => 'Enterprise',
      ]);

    $fields['theme_pack'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Theme pack'))
      ->setSetting('max_length', 64)
      ->setDefaultValue('gov_steady');

    $fields['layout_profile'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('App layout profile'))
      ->setSetting('max_length', 64)
      ->setDefaultValue('gov_default');

    $fields['owner_mail'] = BaseFieldDefinition::create('email')
      ->setLabel(new TranslatableMarkup('Owner mail'))
      ->setDefaultValue('');

    $fields['source_url'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Legacy site URL'))
      ->setSetting('max_length', 512)
      ->setDefaultValue('');

    $fields['migrate_level'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Migrate level'))
      ->setDefaultValue('none')
      ->setSetting('allowed_values', [
        'none' => 'None',
        'l1' => 'L1',
        'l2' => 'L2',
        'l3' => 'L3 (manual)',
      ]);

    $fields['channels'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Channels JSON'))
      ->setDefaultValue('["web"]');

    $fields['capabilities'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Capabilities JSON'))
      ->setDefaultValue('[]');

    $fields['payload'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Blueprint payload JSON'))
      ->setDefaultValue('{}');

    $fields['acceptance'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Acceptance report JSON'))
      ->setDefaultValue('{}');

    $fields['log'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Run log'))
      ->setDefaultValue('');

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'));

    return $fields;
  }

  public function getStatus(): string {
    return (string) $this->get('status')->value;
  }

  public function getMachineName(): string {
    return (string) $this->get('machine_name')->value;
  }

  /**
   * @return array<string, mixed>
   */
  public function getPayload(): array {
    $raw = (string) $this->get('payload')->value;
    $data = json_decode($raw ?: '{}', TRUE);
    return is_array($data) ? $data : [];
  }

  /**
   * @param array<string, mixed> $payload
   */
  public function setPayload(array $payload): void {
    $this->set('payload', json_encode($payload, JSON_UNESCAPED_UNICODE));
  }

  /**
   * @return list<string>
   */
  public function getChannels(): array {
    $data = json_decode((string) $this->get('channels')->value, TRUE);
    return is_array($data) ? array_values(array_map('strval', $data)) : ['web'];
  }

  /**
   * @return list<string>
   */
  public function getCapabilities(): array {
    $data = json_decode((string) $this->get('capabilities')->value, TRUE);
    return is_array($data) ? array_values(array_map('strval', $data)) : [];
  }

  public function appendLog(string $line): void {
    $log = (string) $this->get('log')->value;
    $this->set('log', trim($log . "\n" . '[' . gmdate('c') . '] ' . $line));
  }

}
