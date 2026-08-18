<?php

declare(strict_types=1);

namespace Drupal\dx_appstore\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\dx_appstore\Entity\AppPackage;
use Drupal\dx_appstore\Entity\InstallRequest;
use Drupal\dx_appstore\Service\AppInstaller;
use Drupal\user\Entity\User;
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
      $entity->set('license_family', $app['license_family'] ?? 'gpl');
      $entity->set('source_policy', $app['source_policy'] ?? 'tenant_visible');
      $entity->set('dpa_required', (bool) ($app['dpa_required'] ?? FALSE));
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
   * @option accept-dx-ral
   *   Record DX-RAL acceptance for CLI/ops installs.
   * @usage drush dx:appstore-approve 1
   * @usage drush dx:appstore-approve 1 --accept-dx-ral
   */
  public function approveRequest(int $request_id, array $options = ['accept-dx-ral' => FALSE]): void {
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
      $result = $this->appInstaller->approveAndInstall($request, (bool) $options['accept-dx-ral']);
      $this->logger()->success(sprintf('Request #%d approved and %s installed on %s.', $request_id, $result['module'], $result['tenant']));
    }
    catch (\Throwable $e) {
      $this->logger()->error('Approve failed: ' . $e->getMessage());
    }
  }

  /**
   * Run security and lock validation on curated packages.
   *
   * @command dx:appstore-audit
   * @usage drush dx:appstore-audit
   */
  public function auditPackages(): void {
    $composerLockPath = dirname(DRUPAL_ROOT) . '/composer.lock';
    if (!is_readable($composerLockPath)) {
      $this->logger()->error('composer.lock not found.');
      return;
    }

    $lockData = json_decode(file_get_contents($composerLockPath), TRUE);
    $installedPackages = [];
    foreach (array_merge($lockData['packages'] ?? [], $lockData['packages-dev'] ?? []) as $pkg) {
      $installedPackages[$pkg['name']] = $pkg['version'];
    }

    $storage = $this->entityTypeManager->getStorage('dx_app_package');
    $ids = $storage->getQuery()->accessCheck(FALSE)->execute();
    $packages = $storage->loadMultiple($ids);

    $rows = [];
    foreach ($packages as $p) {
      /** @var \Drupal\dx_appstore\Entity\AppPackage $p */
      $composerName = (string) $p->get('composer_package')->value;
      $trust = (string) $p->get('trust_level')->value;
      $status = isset($installedPackages[$composerName]) ? 'Locked (' . $installedPackages[$composerName] . ')' : 'Not in lockfile';

      $rows[] = [
        $p->label(),
        $composerName ?: '-',
        $trust,
        $status,
      ];
    }

    $this->io()->table(['App Label', 'Composer Package', 'Trust Level', 'Lock Status'], $rows);
  }

  /**
   * Build an L3 source zip for a license (requires DX-RAL on the license).
   *
   * @command dx:appstore-source-bundle
   * @param int $license_id
   * @option dest Write zip to this path (default: temp file)
   * @option uid Acting user (CLI default 1)
   */
  public function sourceBundle(int $license_id, array $options = ['dest' => NULL, 'uid' => 1]): void {
    $license = $this->entityTypeManager->getStorage('dx_license')->load($license_id);
    if (!$license) {
      throw new \InvalidArgumentException("License #{$license_id} not found");
    }
    $account = User::load((int) $options['uid']);
    if (!$account) {
      throw new \RuntimeException('Acting user not found');
    }
    /** @var \Drupal\dx_appstore\Service\SourceBundleService $bundles */
    $bundles = \Drupal::service('dx_appstore.source_bundle');
    $built = $bundles->buildZip($license, $account);
    $dest = $options['dest'] ? (string) $options['dest'] : $built['path'];
    if ($dest !== $built['path']) {
      if (!@copy($built['path'], $dest)) {
        throw new \RuntimeException('Cannot write ' . $dest);
      }
      @unlink($built['path']);
    }
    $this->io()->writeln(json_encode([
      'ok' => TRUE,
      'path' => $dest,
      'bytes' => $built['bytes'],
      'module' => $built['module'],
      'tenant' => $built['tenant'],
    ], JSON_UNESCAPED_UNICODE));
  }

  /**
   * Show recent L3 source download audit entries.
   *
   * @command dx:appstore-source-audit
   */
  public function sourceAudit(): void {
    /** @var \Drupal\dx_appstore\Service\SourceBundleService $bundles */
    $bundles = \Drupal::service('dx_appstore.source_bundle');
    $this->io()->writeln(json_encode($bundles->auditLog(20), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  }

}


