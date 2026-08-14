<?php

declare(strict_types=1);

namespace Drupal\dx_appstore\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\dx_appstore\Entity\AppPackage;
use Drupal\dx_appstore\Entity\InstallRequest;
use Drupal\dx_appstore\Service\AppInstaller;
use Drush\Commands\DrushCommands;
use Symfony\Component\Yaml\Yaml;

/**
 * Drush commands for the App Store.
 */
class AppStoreCommands extends DrushCommands {

  /**
   * Constructs AppStoreCommands.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ?AppInstaller $appInstaller = NULL,
  ) {
    parent::__construct();
  }

  /**
   * Seed the app catalog from catalog.yml.
   *
   * @command dx:appstore-seed
   * @aliases dx-as,dcn-as
   * @usage drush dx:appstore-seed
   */
  public function appstoreSeed(): void {
    $path = DRUPAL_ROOT . '/modules/custom/dx_appstore/data/catalog.yml';
    if (!is_readable($path)) {
      throw new \RuntimeException('Catalog file not found: ' . $path);
    }

    $catalog = Yaml::parseFile($path);
    $apps = $catalog['apps'] ?? [];
    $created = 0;
    $updated = 0;

    $storage = $this->entityTypeManager->getStorage('dx_app_package');

    foreach ($apps as $app) {
      $existing = $storage->loadByProperties(['machine_name' => $app['machine_name']]);
      if ($existing) {
        $entity = reset($existing);
        $updated++;
      }
      else {
        $entity = AppPackage::create(['machine_name' => $app['machine_name']]);
        $created++;
      }

      /** @var \Drupal\dx_appstore\Entity\AppPackage $entity */
      $entity->set('label', $app['label']);
      $entity->set('category', $app['category']);
      $entity->set('project_url', $app['project_url'] ?? '');
      $entity->set('trust_level', $app['trust_level'] ?? 'community');
      $entity->set('china_common', (bool) ($app['china_common'] ?? FALSE));
      $entity->set('price', $app['price'] ?? '0.00');
      $entity->set('revenue_share_percent', (int) ($app['revenue_share_percent'] ?? 70));
      $entity->set('composer_package', $app['composer_package'] ?? '');
      $entity->set('module_name', $app['module_name'] ?? '');
      $entity->set('description', $app['description'] ?? '');
      $entity->set('status', TRUE);
      $entity->save();
    }

    $this->logger()->success(sprintf('Catalog seeded: %d created, %d updated.', $created, $updated));
  }

  /**
   * Process and approve an install request by ID.
   *
   * @command dx:appstore-approve
   * @param int $request_id
   *   The install request ID.
   * @usage drush dx:appstore-approve 1
   */
  public function approveRequest(int $request_id): void {
    if (!$this->appInstaller) {
      $this->logger()->error('App installer service not initialized.');
      return;
    }

    $storage = $this->entityTypeManager->getStorage('dx_install_request');
    /** @var \Drupal\dx_appstore\Entity\InstallRequest|null $request */
    $request = $storage->load($request_id);
    if (!$request) {
      $this->logger()->error("Install request #{$request_id} not found.");
      return;
    }

    try {
      $result = $this->appInstaller->approveAndInstall($request);
      $this->logger()->success(sprintf('Request #%d approved and %s installed on %s.', $request_id, $result['module'], $result['tenant']));
    }
    catch (\Throwable $e) {
      $this->logger()->error('Approve failed: ' . $e->getMessage());
    }
  }

  /**
   * List pending install requests.
   *
   * @command dx:appstore-requests
   * @usage drush dx:appstore-requests
   */
  public function listRequests(): void {
    $storage = $this->entityTypeManager->getStorage('dx_install_request');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->sort('id', 'DESC')
      ->range(0, 50)
      ->execute();

    if (!$ids) {
      $this->io()->note('No install requests found.');
      return;
    }

    $requests = $storage->loadMultiple($ids);
    $rows = [];
    foreach ($requests as $req) {
      /** @var \Drupal\dx_appstore\Entity\InstallRequest $req */
      $app = $req->get('app_id')->entity;
      $rows[] = [
        $req->id(),
        $app ? $app->label() : '-',
        $req->get('tenant_machine')->value,
        $req->get('status')->value,
        date('Y-m-d H:i', (int) $req->get('created')->value),
      ];
    }

    $this->io()->table(['ID', 'App', 'Tenant', 'Status', 'Created'], $rows);
  }

}

