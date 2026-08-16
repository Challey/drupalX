<?php

declare(strict_types=1);

namespace Drupal\dx_delivery\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\dx_delivery\Entity\DeliveryBlueprint;
use Drupal\dx_delivery\Service\BlueprintFactory;
use Drupal\dx_delivery\Service\DeliveryOrchestrator;
use Drush\Commands\DrushCommands;

/**
 * Drush for turnkey delivery.
 */
final class DeliveryCommands extends DrushCommands {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected BlueprintFactory $factory,
    protected DeliveryOrchestrator $orchestrator,
  ) {
    parent::__construct();
  }

  /**
   * Create a blueprint from chat text.
   *
   * @command dx:delivery-from-chat
   * @option machine-name Override tenant id
   * @usage drush dx:delivery-from-chat "做政府门户，要小程序"
   */
  public function fromChat(string $message, array $options = ['machine-name' => NULL]): void {
    $overrides = [];
    if (!empty($options['machine-name'])) {
      $overrides['machine_name'] = $this->factory->sanitizeMachine((string) $options['machine-name']);
    }
    $built = $this->factory->fromChat($message, $overrides);
    $entity = DeliveryBlueprint::create($built['fields']);
    $entity->setPayload($built['payload']);
    $entity->save();
    $this->logger()->success(dt('Blueprint @id created (@status)', [
      '@id' => $entity->id(),
      '@status' => $entity->getStatus(),
    ]));
    $this->io()->writeln(json_encode($built['payload'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  }

  /**
   * Run orchestration for a blueprint id.
   *
   * @command dx:delivery-run
   * @option skip-provision
   * @option skip-pack
   * @usage drush dx:delivery-run 1 --skip-pack
   */
  public function run(int $id, array $options = ['skip-provision' => FALSE, 'skip-pack' => FALSE]): void {
    $entity = $this->entityTypeManager->getStorage('dx_blueprint')->load($id);
    if (!$entity instanceof DeliveryBlueprint) {
      throw new \InvalidArgumentException("Blueprint $id not found");
    }
    $report = $this->orchestrator->run(
      $entity,
      !empty($options['skip-provision']),
      !empty($options['skip-pack']),
    );
    $this->io()->writeln(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  }

  /**
   * List recent blueprints.
   *
   * @command dx:delivery-list
   */
  public function listBlueprints(): void {
    $storage = $this->entityTypeManager->getStorage('dx_blueprint');
    $ids = $storage->getQuery()->accessCheck(FALSE)->sort('id', 'DESC')->range(0, 20)->execute();
    foreach ($storage->loadMultiple($ids) as $entity) {
      /** @var \Drupal\dx_delivery\Entity\DeliveryBlueprint $entity */
      $this->io()->writeln(sprintf(
        '%s  %s  %s  %s',
        $entity->id(),
        $entity->getStatus(),
        $entity->getMachineName(),
        $entity->label(),
      ));
    }
  }

}
