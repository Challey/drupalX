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
  public function approveAndInstall(InstallRequest $request, bool $forceRal = FALSE): array {
    /** @var \Drupal\dx_appstore\Entity\AppPackage|null $app */
    $app = $request->get('app_id')->entity;
    if (!$app) {
      throw new \InvalidArgumentException('Associated app package not found.');
    }

    $moduleName = trim((string) $app->get('module_name')->value);
    if ($moduleName === '') {
      throw new \InvalidArgumentException('Module name is not defined on the app package.');
    }

    $this->assertRalAccepted($request, $forceRal);

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

    // O6-A: block personal tenants while product switch is off.
    $tenantMachine = trim((string) $request->get('tenant_machine')->value);
    if ($tenantMachine === '') {
      throw new \InvalidArgumentException('Tenant machine name is missing.');
    }

    $tenantStorage = $this->entityTypeManager->getStorage('dx_tenant');
    $tenants = $tenantStorage->loadByProperties(['machine_name' => $tenantMachine]);
    if (!$tenants) {
      throw new \RuntimeException("Tenant '{$tenantMachine}' not found on the platform.");
    }

    /** @var \Drupal\dx_platform\Entity\Tenant $tenant */
    $tenant = reset($tenants);
    $kind = 'enterprise';
    if ($tenant->hasField('tenant_kind') && !$tenant->get('tenant_kind')->isEmpty()) {
      $kind = (string) $tenant->get('tenant_kind')->value;
    }
    if ($kind === 'personal') {
      $personalEnabled = FALSE;
      if (\Drupal::moduleHandler()->moduleExists('dx_ecosystem')) {
        $personalEnabled = (bool) $this->configFactory->get('dx_ecosystem.settings')->get('personal_registration_enabled');
      }
      if (!$personalEnabled) {
        throw new \RuntimeException('Personal tenants are disabled (O6-A). Enable dx_ecosystem.settings.personal_registration_enabled to open Wave P.');
      }
    }

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

    // Always create a license record so agreement_version is auditable (OE1).
    $price = (float) ($app->get('price')->value ?: 0);
    $ralVersion = (string) ($request->get('ral_version')->value ?: '1.0');
    $licenseFamily = (string) ($app->get('license_family')->value ?? 'gpl');
    $sourcePolicy = (string) ($app->get('source_policy')->value ?? 'tenant_visible');
    $licenseId = NULL;
    try {
      $licenseStorage = $this->entityTypeManager->getStorage('dx_license');
      /** @var \Drupal\dx_appstore\Entity\License $license */
      $license = $licenseStorage->create([
        'app_id' => $app->id(),
        'tenant_machine' => $tenantMachine,
        'status' => 'active',
        'amount' => $price,
        'agreement_version' => $ralVersion,
        'license_family' => $licenseFamily,
        'source_policy' => $sourcePolicy,
        'created' => time(),
      ]);
      $license->save();
      $licenseId = $license->id();

      if ($price > 0) {
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
    }
    catch (\Throwable $e) {
      $this->logger->warning('Failed to generate license/revshare: @msg', ['@msg' => $e->getMessage()]);
    }

    $this->logger->info('Successfully installed @mod on tenant @t', [
      '@mod' => $moduleName,
      '@t' => $tenantMachine,
    ]);

    return [
      'status' => 'installed',
      'tenant' => $tenantMachine,
      'module' => $moduleName,
      'license_id' => $licenseId,
      'agreement_version' => $ralVersion,
      'output' => $process->getOutput(),
    ];
  }

  /**
   * Ensures DX-RAL was accepted on the request (O3-A).
   */
  protected function assertRalAccepted(InstallRequest $request, bool $forceRal): void {
    $required = TRUE;
    if (\Drupal::moduleHandler()->moduleExists('dx_ecosystem')) {
      $required = (bool) $this->configFactory->get('dx_ecosystem.settings')->get('require_ral_on_install');
    }
    if (!$required) {
      return;
    }
    $accepted = (bool) ($request->get('ral_accepted')->value ?? FALSE);
    if ($forceRal && !$accepted) {
      $version = '1.0';
      if (\Drupal::hasService('dx_ecosystem.agreements')) {
        $ral = \Drupal::service('dx_ecosystem.agreements')->currentRal();
        if ($ral) {
          $version = $ral['version'];
        }
      }
      $request->set('ral_accepted', TRUE);
      $request->set('ral_version', $version);
      $request->set('ral_accepted_at', time());
      $request->set('ral_accepter_uid', 1);
      $request->save();
      $accepted = TRUE;
    }
    if (!$accepted) {
      throw new \RuntimeException('DX-RAL acknowledgment required before install (accept on request form or pass --accept-dx-ral).');
    }
  }

}
