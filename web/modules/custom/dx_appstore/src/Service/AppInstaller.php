<?php

declare(strict_types=1);

namespace Drupal\dx_appstore\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\dx_appstore\Entity\InstallRequest;
use Symfony\Component\Process\Process;

/**
 * Service to execute approved App Store installations for tenants.
 */
class AppInstaller {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerChannelInterface $logger,
  ) {}

  /**
   * Approves an install request and executes the module enablement for the tenant site.
   */
  public function approveAndInstall(InstallRequest $request): array {
    /** @var \Drupal\dx_appstore\Entity\AppPackage|null $app */
    $app = $request->get('app_id')->entity;
    if (!$app) {
      throw new \InvalidArgumentException('Associated app package not found.');
    }

    $moduleName = trim((string) $app->get('module_name')->value);
    if ($moduleName === '') {
      throw new \InvalidArgumentException('Module name is not defined on the app package.');
    }

    // Trust policy gate (EB): block community / disallowed tiers for gov defaults.
    if (\Drupal::moduleHandler()->moduleExists('dx_trust') && \Drupal::hasService('dx_trust.policy')) {
      $trustLevel = (string) ($app->get('trust_level')->value ?: 'community');
      /** @var \Drupal\dx_trust\Service\TrustPolicy $policy */
      $policy = \Drupal::service('dx_trust.policy');
      $eval = $policy->evaluate($trustLevel);
      if (empty($eval['allowed'])) {
        $request->set('status', 'rejected');
        $request->save();
        throw new \RuntimeException('Trust policy rejected install: ' . ($eval['reason'] ?? 'denied'));
      }
    }

    $tenantMachine = trim((string) $request->get('tenant_machine')->value);
    if ($tenantMachine === '') {
      throw new \InvalidArgumentException('Tenant machine name is missing.');
    }

    // Verify tenant exists and is active.
    $tenantStorage = $this->entityTypeManager->getStorage('dx_tenant');
    $tenants = $tenantStorage->loadByProperties(['machine_name' => $tenantMachine]);
    if (!$tenants) {
      throw new \RuntimeException("Tenant '{$tenantMachine}' not found on the platform.");
    }

    /** @var \Drupal\dx_platform\Entity\Tenant $tenant */
    $tenant = reset($tenants);
    $subdomain = (string) $tenant->get('subdomain')->value;
    if ($subdomain === '') {
      $suffix = getenv('DX_TENANT_SUFFIX') ?: 'drupalx.local';
      $subdomain = $tenantMachine . '.' . $suffix;
    }

    $request->set('status', 'approved');
    $request->save();

    // Execute drush pm:enable on the tenant site URI.
    $drush = dirname(DRUPAL_ROOT) . '/vendor/bin/drush';
    $cwd = dirname(DRUPAL_ROOT);
    $uriOpt = '--uri=http://' . $subdomain;

    $process = new Process([
      $drush,
      'pm:enable',
      $moduleName,
      '--yes',
      $uriOpt,
    ], $cwd);

    $timeout = 180;
    $process->setTimeout($timeout);
    $process->run();

    if (!$process->isSuccessful()) {
      $err = $process->getErrorOutput() . "\n" . $process->getOutput();
      $this->logger->error('Failed to install @mod on tenant @t: @err', [
        '@mod' => $moduleName,
        '@t' => $tenantMachine,
        '@err' => $err,
      ]);
      throw new \RuntimeException("Failed to enable module {$moduleName} on {$subdomain}: {$err}");
    }

    $request->set('status', 'installed');
    $request->save();

    // Automatically create License and Revenue Share records if price > 0.
    $price = (float) ($app->get('price')->value ?: 0);
    if ($price > 0) {
      try {
        $licenseStorage = $this->entityTypeManager->getStorage('dx_license');
        /** @var \Drupal\dx_appstore\Entity\License $license */
        $license = $licenseStorage->create([
          'app_id' => $app->id(),
          'tenant_machine' => $tenantMachine,
          'status' => 'active',
          'amount' => $price,
          'created' => time(),
        ]);
        $license->save();

        $sharePercent = (int) ($app->get('revenue_share_percent')->value ?: 70);
        $shareAmount = round($price * ($sharePercent / 100), 2);

        $revStorage = $this->entityTypeManager->getStorage('dx_revenue_share');
        $revShare = $revStorage->create([
          'license_id' => $license->id(),
          'developer_uid' => $request->get('requester_uid')->target_id ?: 1,
          'amount' => $shareAmount,
          'share_percent' => $sharePercent,
          'status' => 'pending',
        ]);
        $revShare->save();
      }
      catch (\Throwable $e) {
        $this->logger->warning('Failed to generate license/revshare: @msg', ['@msg' => $e->getMessage()]);
      }
    }

    $this->logger->info('Successfully installed @mod on tenant @t', [
      '@mod' => $moduleName,
      '@t' => $tenantMachine,
    ]);

    return [
      'status' => 'installed',
      'tenant' => $tenantMachine,
      'module' => $moduleName,
      'output' => $process->getOutput(),
    ];
  }

}
