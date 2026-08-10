<?php

declare(strict_types=1);

namespace Drupal\dx_appstore\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\dx_appstore\Entity\AppPackage;
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

}
