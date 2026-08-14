<?php

declare(strict_types=1);

namespace Drupal\dx_appstore\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\dx_appstore\Entity\AppPackage;
use Drupal\dx_appstore\Entity\InstallRequest;

/**
 * Whitelist-gated module enablement for App Store installs.
 */
class ModuleInstallGate {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ModuleExtensionList $moduleList,
    protected ModuleInstallerInterface $moduleInstaller,
    protected ModuleHandlerInterface $moduleHandler,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerChannelInterface $logger,
  ) {}

  /**
   * Returns configured extra allow-list module names.
   *
   * @return string[]
   */
  public function configWhitelist(): array {
    $list = $this->configFactory->get('dx_appstore.settings')->get('allowed_modules') ?: [];
    return array_values(array_filter(array_map('strval', $list)));
  }

  /**
   * Whether a module machine name is allowed to be enabled via App Store.
   */
  public function isWhitelisted(string $moduleName): bool {
    $moduleName = trim($moduleName);
    if ($moduleName === '') {
      return FALSE;
    }
    if (in_array($moduleName, $this->configWhitelist(), TRUE)) {
      return TRUE;
    }
    $packages = $this->entityTypeManager->getStorage('dx_app_package')
      ->loadByProperties(['module_name' => $moduleName, 'status' => 1]);
    return (bool) $packages;
  }

  /**
   * Validates an app package can be installed on this codebase.
   *
   * @throws \RuntimeException
   */
  public function assertInstallable(AppPackage $package): void {
    $module = trim((string) $package->get('module_name')->value);
    if ($module === '') {
      throw new \RuntimeException('App package has no module_name.');
    }
    if (!$this->isWhitelisted($module)) {
      throw new \RuntimeException("Module {$module} is not on the App Store whitelist.");
    }
    if (!$this->moduleList->exists($module)) {
      throw new \RuntimeException("Module {$module} is not present in the codebase. Add it via Composer on the platform first.");
    }
    $trust = (string) $package->get('trust_level')->value;
    $allowCommunity = (bool) $this->configFactory->get('dx_appstore.settings')->get('allow_community_install');
    if ($trust === 'community' && !$allowCommunity) {
      throw new \RuntimeException("Community-trust app {$module} cannot be auto-installed. Enable allow_community_install or raise trust level.");
    }
    $composer = trim((string) $package->get('composer_package')->value);
    if ($composer !== '' && !$this->composerPackagePresent($composer)) {
      throw new \RuntimeException("Composer package {$composer} is not locked in composer.lock.");
    }
  }

  /**
   * Approves a pending install request.
   */
  public function approve(InstallRequest $request, string $notes = ''): void {
    if ($request->get('status')->value !== 'pending') {
      throw new \RuntimeException('Only pending requests can be approved.');
    }
    $request->set('status', 'approved');
    if ($notes !== '') {
      $existing = (string) $request->get('notes')->value;
      $request->set('notes', trim($existing . "\n[approved] " . $notes));
    }
    $request->save();
  }

  /**
   * Rejects a pending/approved request.
   */
  public function reject(InstallRequest $request, string $notes = ''): void {
    if (!in_array($request->get('status')->value, ['pending', 'approved'], TRUE)) {
      throw new \RuntimeException('Only pending/approved requests can be rejected.');
    }
    $request->set('status', 'rejected');
    if ($notes !== '') {
      $existing = (string) $request->get('notes')->value;
      $request->set('notes', trim($existing . "\n[rejected] " . $notes));
    }
    $request->save();
  }

  /**
   * Enables the module for an approved request (whitelist + presence checks).
   */
  public function install(InstallRequest $request): void {
    if ($request->get('status')->value !== 'approved') {
      throw new \RuntimeException('Only approved requests can be installed.');
    }
    $app = $request->get('app_id')->entity;
    if (!$app instanceof AppPackage) {
      throw new \RuntimeException('Install request has no app package.');
    }
    $this->assertInstallable($app);
    $module = (string) $app->get('module_name')->value;
    if (!$this->moduleHandler->moduleExists($module)) {
      $this->moduleInstaller->install([$module], TRUE);
    }
    $request->set('status', 'installed');
    $request->save();
    $this->logger->notice('Installed module @module for tenant @tenant via App Store request @id.', [
      '@module' => $module,
      '@tenant' => $request->get('tenant_machine')->value,
      '@id' => $request->id(),
    ]);
  }

  /**
   * Checks composer.lock for a package name.
   */
  protected function composerPackagePresent(string $packageName): bool {
    $roots = [
      dirname(DRUPAL_ROOT) . '/composer.lock',
      DRUPAL_ROOT . '/../composer.lock',
      DRUPAL_ROOT . '/composer.lock',
    ];
    $foundLock = FALSE;
    foreach ($roots as $lock) {
      if (!is_readable($lock)) {
        continue;
      }
      $foundLock = TRUE;
      $json = json_decode((string) file_get_contents($lock), TRUE);
      if (!is_array($json)) {
        continue;
      }
      foreach (['packages', 'packages-dev'] as $section) {
        foreach ($json[$section] ?? [] as $pkg) {
          if (($pkg['name'] ?? '') === $packageName) {
            return TRUE;
          }
        }
      }
    }
    // If no lock file is readable (dev sandbox), do not block install.
    return !$foundLock;
  }

}
