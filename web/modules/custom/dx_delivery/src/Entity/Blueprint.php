<?php

declare(strict_types=1);

namespace Drupal\dx_delivery\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use Drupal\dx_delivery\BlueprintAccessControlHandler;
use Drupal\dx_delivery\BlueprintListBuilder;
use Drupal\dx_delivery\Form\BlueprintDeleteForm;
use Drupal\dx_delivery\Form\BlueprintExecuteConfirmForm;
use Drupal\views\EntityViewsData;

/**
 * Defines the delivery blueprint entity.
 */
#[ContentEntityType(
  id: 'dx_blueprint',
  label: new TranslatableMarkup('Delivery blueprint'),
  label_collection: new TranslatableMarkup('Delivery blueprints'),
  label_singular: new TranslatableMarkup('delivery blueprint'),
  label_plural: new TranslatableMarkup('delivery blueprints'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'label',
  ],
  handlers: [
    'storage' => SqlContentEntityStorage::class,
    'access' => BlueprintAccessControlHandler::class,
    'list_builder' => BlueprintListBuilder::class,
    'views_data' => EntityViewsData::class,
    'form' => [
      'delete' => BlueprintDeleteForm::class,
      'execute' => BlueprintExecuteConfirmForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  links: [
    'collection' => '/admin/dx/delivery',
    'canonical' => '/admin/dx/delivery/{dx_blueprint}',
    'delete-form' => '/admin/dx/delivery/{dx_blueprint}/delete',
  ],
  admin_permission: 'administer dx delivery',
  base_table: 'dx_blueprint',
  label_count: [
    'singular' => '@count delivery blueprint',
    'plural' => '@count delivery blueprints',
  ],
)]
class Blueprint extends ContentEntityBase {

  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['machine_name'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Machine name'))
      ->setDescription(new TranslatableMarkup('Unique tenant identifier for this delivery.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 64)
      ->addConstraint('UniqueField')
      ->setDisplayConfigurable('view', TRUE);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Label'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayConfigurable('view', TRUE);

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
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['site_type'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Site type'))
      ->setRequired(TRUE)
      ->setSetting('allowed_values', [
        'government' => 'Government portal',
        'enterprise' => 'Enterprise portal',
        'industry' => 'Industry site',
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['theme_skin'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Theme skin'))
      ->setSetting('max_length', 64)
      ->setDisplayConfigurable('view', TRUE);

    $fields['content_source'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Content source'))
      ->setDefaultValue('demo')
      ->setSetting('allowed_values', [
        'blank' => 'Blank start',
        'demo' => 'Industry demo pack',
        'migrate' => 'Legacy site (manual)',
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['industry'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Industry'))
      ->setSetting('allowed_values', [
        'manufacturing' => 'Manufacturing',
        'retail' => 'Retail',
        'services' => 'Services',
      ])
      ->setDefaultValue('manufacturing')
      ->setDisplayConfigurable('view', TRUE);

    $fields['app_packages'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('App packages (JSON)'))
      ->setDisplayConfigurable('view', FALSE);

    $fields['channels'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Channels (JSON)'))
      ->setDisplayConfigurable('view', FALSE);

    $fields['tenant_machine'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Tenant machine name'))
      ->setSetting('max_length', 64)
      ->setDisplayConfigurable('view', TRUE);

    $fields['owner_mail'] = BaseFieldDefinition::create('email')
      ->setLabel(new TranslatableMarkup('Owner email'))
      ->setDisplayConfigurable('view', TRUE);

    $fields['blueprint_json'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Blueprint JSON'))
      ->setDisplayConfigurable('view', FALSE);

    $fields['acceptance_json'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Acceptance report JSON'))
      ->setDisplayConfigurable('view', FALSE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'))
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
   * Gets the lifecycle status.
   */
  public function getStatus(): string {
    return (string) $this->get('status')->value;
  }

  /**
   * Decodes app package machine names from JSON storage.
   *
   * @return string[]
   */
  public function getAppPackageIds(): array {
    $raw = (string) $this->get('app_packages')->value;
    if ($raw === '') {
      return [];
    }
    $decoded = json_decode($raw, TRUE);
    return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
  }

  /**
   * Decodes channel flags from JSON storage.
   *
   * @return array<string, bool>
   */
  public function getChannels(): array {
    $raw = (string) $this->get('channels')->value;
    if ($raw === '') {
      return ['web' => TRUE];
    }
    $decoded = json_decode($raw, TRUE);
    return is_array($decoded) ? $decoded : ['web' => TRUE];
  }

  /**
   * Decodes the acceptance report.
   *
   * @return array<string, mixed>
   */
  public function getAcceptanceReport(): array {
    $raw = (string) $this->get('acceptance_json')->value;
    if ($raw === '') {
      return [];
    }
    $decoded = json_decode($raw, TRUE);
    return is_array($decoded) ? $decoded : [];
  }

  /**
   * Machine name existence callback for forms.
   */
  public static function loadByMachineName(string $machine_name): bool {
    $storage = \Drupal::entityTypeManager()->getStorage('dx_blueprint');
    $matches = $storage->loadByProperties(['machine_name' => $machine_name]);
    return (bool) $matches;
  }

}
