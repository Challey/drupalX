<?php

declare(strict_types=1);

namespace Drupal\dx_delivery\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\dx_delivery\Service\DeliveryOrchestrator;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for turnkey delivery orchestration.
 */
class DeliveryCommands extends DrushCommands {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected DeliveryOrchestrator $orchestrator,
  ) {
    parent::__construct();
  }

  /**
   * Execute a turnkey delivery pipeline for a blueprint.
   *
   * @param string $blueprintId
   *   Blueprint entity ID or machine name.
   *
   * @command dx:delivery-run
   * @aliases dx-dr
   * @usage drush dx:delivery-run 1
   * @usage drush dx:delivery-run gov-demo
   */
  public function deliveryRun(string $blueprintId): void {
    $blueprint = $this->loadBlueprint($blueprintId);
    if (!$blueprint) {
      $this->logger()->error('Blueprint not found: @id', ['@id' => $blueprintId]);
      return;
    }

    $this->io()->note('Running turnkey delivery for ' . $blueprint->label() . '…');
    try {
      $report = $this->orchestrator->run($blueprint);
      $this->io()->success('Delivery completed.');
      $this->io()->writeln('Portal: ' . ($report['portal_url'] ?? 'n/a'));
    }
    catch (\Throwable $e) {
      $this->logger()->error($e->getMessage());
    }
  }

  /**
   * Show delivery pipeline status for a blueprint.
   *
   * @param string $blueprintId
   *   Blueprint entity ID or machine name.
   *
   * @command dx:delivery-status
   * @aliases dx-ds
   * @usage drush dx:delivery-status 1
   */
  public function deliveryStatus(string $blueprintId): void {
    $blueprint = $this->loadBlueprint($blueprintId);
    if (!$blueprint) {
      $this->logger()->error('Blueprint not found: @id', ['@id' => $blueprintId]);
      return;
    }

    $steps = $this->orchestrator->getSteps($blueprint);
    $rows = [];
    foreach ($steps as $step) {
      $rows[] = [
        $step->get('stage')->value,
        $step->get('status')->value,
        mb_substr((string) $step->get('message')->value, 0, 80),
      ];
    }

    $this->io()->title('Blueprint: ' . $blueprint->label() . ' [' . $blueprint->getStatus() . ']');
    $this->io()->table(['Stage', 'Status', 'Message'], $rows);
  }

  /**
   * Loads a blueprint by numeric ID or machine name.
   */
  protected function loadBlueprint(string $id): ?\Drupal\dx_delivery\Entity\Blueprint {
    $storage = $this->entityTypeManager->getStorage('dx_blueprint');
    if (ctype_digit($id)) {
      $entity = $storage->load((int) $id);
      return $entity instanceof \Drupal\dx_delivery\Entity\Blueprint ? $entity : NULL;
    }
    $matches = $storage->loadByProperties(['machine_name' => $id]);
    $entity = $matches ? reset($matches) : NULL;
    return $entity instanceof \Drupal\dx_delivery\Entity\Blueprint ? $entity : NULL;
  }

}
