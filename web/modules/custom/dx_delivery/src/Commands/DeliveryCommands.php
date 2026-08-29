<?php

declare(strict_types=1);

namespace Drupal\dx_delivery\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\dx_delivery\Entity\DeliveryBlueprint;
use Drupal\dx_delivery\Service\AcceptanceReportBuilder;
use Drupal\dx_delivery\Service\BlueprintFactory;
use Drupal\dx_delivery\Service\DeliveryOrchestrator;
use Drupal\dx_delivery\Service\HandoffTodoService;
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

  /**
   * Print structured acceptance report for a blueprint.
   *
   * @command dx:delivery-report
   * @param int $id Blueprint id
   * @usage dx:delivery-report 1
   */
  public function deliveryReport(int $id): void {
    $entity = $this->loadBlueprint($id);
    $acceptance = $this->acceptanceOf($entity);
    $out = [
      'id' => (int) $entity->id(),
      'label' => $entity->label(),
      'status' => $entity->getStatus(),
      'machine_name' => $entity->getMachineName(),
      'site_type' => (string) $entity->get('site_type')->value,
      'capabilities' => $entity->getCapabilities(),
      'channels' => $entity->getChannels(),
      'migrate_level' => (string) $entity->get('migrate_level')->value,
      'acceptance' => $acceptance,
      'spec_version' => AcceptanceReportBuilder::SPEC_VERSION,
      'deliverables' => AcceptanceReportBuilder::deliverables($acceptance, $this->reportContext($acceptance)),
      'todo_stats' => HandoffTodoService::stats($this->todosOf($acceptance)),
    ];
    $this->io()->writeln(json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  }

  /**
   * Export acceptance JSON v3 to a file path.
   *
   * v3 keeps every v2 key and adds the deliverables block (ops handbook, API
   * docs, certificate vault, L3 source bundle) plus the L3 todo list with SLA
   * fields. Nothing is dropped silently: an unavailable deliverable is emitted
   * with status=missing so the file always parses the same way.
   *
   * @command dx:delivery-export
   * @param int $id Blueprint id
   * @param string $path Output file path
   * @usage dx:delivery-export 1 /tmp/acceptance.json
   */
  public function deliveryExport(int $id, string $path): void {
    $entity = $this->loadBlueprint($id);
    $acceptance = $this->acceptanceOf($entity);
    $out = AcceptanceReportBuilder::export($acceptance, $this->blueprintMeta($entity), $this->reportContext($acceptance));
    $json = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (@file_put_contents($path, $json) === FALSE) {
      throw new \RuntimeException("Cannot write $path");
    }
    $deliverables = $out['deliverables'];
    $this->io()->writeln(json_encode([
      'ok' => TRUE,
      'path' => $path,
      'bytes' => strlen($json),
      'spec_version' => AcceptanceReportBuilder::SPEC_VERSION,
      'deliverables_total' => $deliverables['total'],
      'deliverables_ok' => $deliverables['ok'],
      'deliverables_missing' => $deliverables['missing'],
      'todo_stats' => $out['handoff_todos']['stats'],
    ], JSON_UNESCAPED_UNICODE));
  }

  /**
   * Mark L3 handoff todos complete, one at a time or as a batch.
   *
   * Single mode keeps the historical contract (unknown id = hard error, nothing
   * saved). Batch mode is idempotent: already signed todos come back as
   * "skipped" instead of failing the run, so the same command can be replayed
   * from a cron or an operator shell.
   *
   * @command dx:delivery-todo-done
   * @param int $id Blueprint id
   * @param string $todo_id Todo id (e.g. l3-integration); omit when using --batch
   * @option batch Comma separated todo ids to sign off in one run
   * @option owner SLA owner stored on the targeted todos
   * @option due SLA due date stored on the targeted todos (YYYY-MM-DD)
   * @option note Remark stored on the targeted todos
   * @option sla-only Only write the SLA fields, keep every todo's status
   * @usage drush dx:delivery-todo-done 1 l3-integration
   * @usage drush dx:delivery-todo-done 1 --batch=l3-integration,l3-acceptance --owner=ops --due=2026-09-15 --note=现场已联调
   * @usage drush dx:delivery-todo-done 1 --batch=l3-integration --owner=liang --sla-only
   */
  public function todoDone(int $id, string $todo_id = '', array $options = [
    'batch' => NULL,
    'owner' => NULL,
    'due' => NULL,
    'note' => NULL,
    'sla-only' => FALSE,
  ]): void {
    $entity = $this->loadBlueprint($id);
    /** @var \Drupal\dx_delivery\Service\HandoffTodoService $svc */
    $svc = \Drupal::service('dx_delivery.handoff_todos');

    $batchIds = HandoffTodoService::parseIdList($options['batch'] ?? NULL);
    $isBatch = $batchIds !== [];
    if (!$isBatch) {
      if ($todo_id === '') {
        throw new \InvalidArgumentException("Provide a todo id or --batch=<id1,id2>");
      }
      $batchIds = [$todo_id];
    }
    $slaGiven = array_filter([
      'owner' => isset($options['owner']) ? trim((string) $options['owner']) : '',
      'due' => isset($options['due']) ? trim((string) $options['due']) : '',
      'remark' => isset($options['note']) ? trim((string) $options['note']) : '',
    ], static fn (string $value): bool => $value !== '');

    $todos = $svc->listFromBlueprint($entity);
    $mode = !empty($options['sla-only']) ? 'sla' : ($isBatch ? 'batch' : 'single');

    if ($mode === 'sla') {
      $result = $svc->applySla($todos, $batchIds, $slaGiven);
      $completed = [];
      $skipped = [];
      $unknown = $result['unknown'];
      $todos = $result['todos'];
      if ($slaGiven !== [] && $result['updated'] !== []) {
        $svc->saveOnBlueprint($entity, $todos);
      }
    }
    else {
      $result = $svc->completeBatch($todos, $batchIds);
      $todos = $result['todos'];
      $completed = $result['completed'];
      $skipped = $result['skipped'];
      $unknown = $result['unknown'];
      if ($slaGiven !== []) {
        $sla = $svc->applySla($todos, $batchIds, $slaGiven);
        $todos = $sla['todos'];
        $unknown = array_values(array_unique(array_merge($unknown, $sla['unknown'])));
      }
      if (!$isBatch && $unknown !== []) {
        // Historical single mode: refuse and change nothing.
        throw new \InvalidArgumentException('Unknown handoff todo: ' . $todo_id);
      }
      if ($completed !== [] || $skipped !== [] || $slaGiven !== []) {
        $svc->saveOnBlueprint($entity, $todos);
      }
    }

    $out = [
      'ok' => $unknown === [],
      'blueprint_id' => (int) $entity->id(),
      'mode' => $mode,
      'todo_id' => $isBatch ? NULL : $todo_id,
      'requested' => $batchIds,
      'completed' => $completed,
      'skipped' => $skipped,
      'unknown' => $unknown,
      'sla' => (object) $slaGiven,
      'todo_stats' => HandoffTodoService::stats($todos),
      'todos' => $todos,
    ];
    $this->io()->writeln(json_encode($out, JSON_UNESCAPED_UNICODE));

    if ($isBatch && $unknown !== []) {
      throw new \RuntimeException('Unknown handoff todos: ' . implode(',', $unknown));
    }
  }

  /**
   * Load a blueprint or fail the same way for every subcommand.
   */
  protected function loadBlueprint(int $id): DeliveryBlueprint {
    $entity = $this->entityTypeManager->getStorage('dx_blueprint')->load($id);
    if (!$entity instanceof DeliveryBlueprint) {
      throw new \InvalidArgumentException("Blueprint $id not found");
    }
    return $entity;
  }

  /**
   * @return array<string, mixed>
   */
  protected function acceptanceOf(DeliveryBlueprint $entity): array {
    $acceptance = json_decode((string) $entity->get('acceptance')->value, TRUE);
    return is_array($acceptance) ? $acceptance : [];
  }

  /**
   * @param array<string, mixed> $acceptance
   *
   * @return list<array<string, mixed>>
   */
  protected function todosOf(array $acceptance): array {
    $todos = $acceptance['handoff_todos'] ?? [];
    return is_array($todos) ? array_values($todos) : [];
  }

  /**
   * @return array<string, mixed>
   */
  protected function blueprintMeta(DeliveryBlueprint $entity): array {
    return [
      'blueprint_id' => (int) $entity->id(),
      'label' => (string) $entity->label(),
      'status' => $entity->getStatus(),
      'machine_name' => $entity->getMachineName(),
      'site_type' => (string) $entity->get('site_type')->value,
      'channels' => $entity->getChannels(),
      'capabilities' => $entity->getCapabilities(),
      'migrate_level' => (string) $entity->get('migrate_level')->value,
      'log' => (string) $entity->get('log')->value,
    ];
  }

  /**
   * Context for the deliverables block: repo root plus live route checks.
   *
   * @param array<string, mixed> $acceptance
   *
   * @return array<string, mixed>
   */
  protected function reportContext(array $acceptance): array {
    return AcceptanceReportBuilder::context($acceptance, $this->resolveSitePaths(AcceptanceReportBuilder::sitePaths($acceptance)));
  }

  /**
   * Which of the given site paths actually resolve to a route.
   *
   * @param list<string> $paths
   *
   * @return array<string, bool>
   */
  protected function resolveSitePaths(array $paths): array {
    if ($paths === [] || !\Drupal::hasService('router.no_cache_routes')) {
      return [];
    }
    $resolved = [];
    foreach ($paths as $path) {
      try {
        \Drupal::service('router.no_cache_routes')->match($path);
        $resolved[$path] = TRUE;
      }
      catch (\Throwable) {
        $resolved[$path] = FALSE;
      }
    }
    return $resolved;
  }

}
