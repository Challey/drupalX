<?php

declare(strict_types=1);

namespace Drupal\dx_delivery\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\dx_delivery\Entity\DeliveryBlueprint;
use Drupal\dx_delivery\Service\BlueprintFactory;
use Drupal\dx_delivery\Service\DeliveryOrchestrator;
use Drupal\dx_delivery\Service\TodoService;
use Drush\Commands\DrushCommands;

/**
 * Drush for turnkey delivery.
 */
final class DeliveryCommands extends DrushCommands {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected BlueprintFactory $factory,
    protected DeliveryOrchestrator $orchestrator,
    protected TodoService $todos,
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

  /**
   * Print structured acceptance report for a blueprint.
   *
   * @command dx:delivery-report
   * @param int $id Blueprint id
   * @usage dx:delivery-report 1
   */
  public function deliveryReport(int $id): void {
    $entity = $this->entityTypeManager->getStorage('dx_blueprint')->load($id);
    if (!$entity) {
      throw new \InvalidArgumentException("Blueprint $id not found");
    }
    /** @var \Drupal\dx_delivery\Entity\DeliveryBlueprint $entity */
    $acceptance = json_decode((string) $entity->get('acceptance')->value, TRUE);
    $out = [
      'id' => (int) $entity->id(),
      'label' => $entity->label(),
      'status' => $entity->getStatus(),
      'machine_name' => $entity->getMachineName(),
      'site_type' => (string) $entity->get('site_type')->value,
      'capabilities' => $entity->getCapabilities(),
      'channels' => $entity->getChannels(),
      'migrate_level' => (string) $entity->get('migrate_level')->value,
      'acceptance' => is_array($acceptance) ? $acceptance : NULL,
      'todos' => $this->todos->list((int) $entity->id()),
      'todo_counts' => $this->todos->counts((int) $entity->id()),
    ];
    $this->io()->writeln(json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  }


  /**
   * Export acceptance JSON to a file path.
   *
   * @command dx:delivery-export
   * @param int $id Blueprint id
   * @param string $path Output file path
   * @usage dx:delivery-export 1 /tmp/acceptance.json
   */
  public function deliveryExport(int $id, string $path): void {
    $entity = $this->entityTypeManager->getStorage('dx_blueprint')->load($id);
    if (!$entity) {
      throw new \InvalidArgumentException("Blueprint $id not found");
    }
    /** @var \Drupal\dx_delivery\Entity\DeliveryBlueprint $entity */
    $acceptance = json_decode((string) $entity->get('acceptance')->value, TRUE);
    $out = [
      'spec' => 'DX-ACCEPTANCE',
      'exported_at' => gmdate('c'),
      'blueprint_id' => (int) $entity->id(),
      'label' => $entity->label(),
      'status' => $entity->getStatus(),
      'machine_name' => $entity->getMachineName(),
      'acceptance' => is_array($acceptance) ? $acceptance : new \stdClass(),
      'todos' => $this->todos->list((int) $entity->id()),
      'todo_counts' => $this->todos->counts((int) $entity->id()),
      'log' => (string) $entity->get('log')->value,
    ];
    $json = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (@file_put_contents($path, $json) === FALSE) {
      throw new \RuntimeException("Cannot write $path");
    }
    $this->io()->writeln(json_encode(['ok' => TRUE, 'path' => $path, 'bytes' => strlen($json)], JSON_UNESCAPED_UNICODE));
  }

  /**
   * List delivery todos (L3 / 待补).
   *
   * @command dx:delivery-todos
   * @option blueprint Filter by blueprint id
   * @option status open|done|all
   * @usage dx:delivery-todos --blueprint=1
   */
  public function listTodos(array $options = ['blueprint' => NULL, 'status' => 'open']): void {
    $blueprint = $options['blueprint'] !== NULL && $options['blueprint'] !== ''
      ? (int) $options['blueprint']
      : NULL;
    $status = (string) ($options['status'] ?? 'open');
    if ($status === 'all') {
      $status = NULL;
    }
    $rows = $this->todos->list($blueprint, $status);
    $counts = $this->todos->counts($blueprint);
    $this->io()->writeln(json_encode([
      'ok' => TRUE,
      'counts' => $counts,
      'items' => $rows,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  }

  /**
   * Mark a delivery todo as done.
   *
   * @command dx:delivery-todo-done
   * @param int $id Todo id
   * @usage dx:delivery-todo-done 1
   */
  public function todoDone(int $id): void {
    $ok = $this->todos->markDone($id);
    $this->io()->writeln(json_encode(['ok' => $ok, 'id' => $id, 'status' => $ok ? 'done' : 'missing'], JSON_UNESCAPED_UNICODE));
    if (!$ok) {
      throw new \RuntimeException("Todo $id not found");
    }
  }

}
