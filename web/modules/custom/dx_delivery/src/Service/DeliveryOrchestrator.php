<?php

declare(strict_types=1);

namespace Drupal\dx_delivery\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\dx_appstore\Service\AppInstaller;
use Drupal\dx_delivery\Entity\Blueprint;
use Drupal\dx_delivery\Entity\DeliveryRun;
use Drupal\dx_platform\Entity\Tenant;
use Drupal\dx_platform\Service\TenantProvisioner;
use Symfony\Component\Process\Process;

/**
 * Orchestrates turnkey delivery pipeline stages D1–D7.
 */
class DeliveryOrchestrator {

  /**
   * Pipeline stages executed in order.
   *
   * @var string[]
   */
  protected const STAGES = ['D1', 'D2', 'D3', 'D5', 'D7'];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TenantProvisioner $tenantProvisioner,
    protected AppInstaller $appInstaller,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerChannelInterface $logger,
    protected AccountProxyInterface $currentUser,
  ) {}

  /**
   * Runs the full delivery pipeline for a blueprint.
   *
   * @return array<string, mixed>
   *   Acceptance report payload.
   */
  public function run(Blueprint $blueprint): array {
    $blueprint->set('status', 'running');
    $blueprint->save();

    $checklist = [];
    $portalUrl = '';
    $tenantMachine = $blueprint->getMachineName();

    try {
      // D1 — Tenant provision.
      $portalUrl = $this->runStage($blueprint, 'D1', function () use ($blueprint, $tenantMachine): string {
        $tenant = $this->ensureTenant($blueprint, $tenantMachine);
        if ($tenant->getStatus() !== 'active') {
          $this->tenantProvisioner->provision($tenant);
        }
        $blueprint->set('tenant_machine', $tenantMachine);
        $blueprint->save();
        return (string) $tenant->get('portal_url')->value;
      });
      $checklist[] = $this->check('Tenant provisioned', TRUE, $portalUrl);

      $subdomain = $this->resolveSubdomain($tenantMachine);

      // D2 — Foundation pack / demo content.
      $contentSource = (string) $blueprint->get('content_source')->value;
      if ($contentSource === 'demo') {
        $industry = (string) ($blueprint->get('industry')->value ?: 'manufacturing');
        $this->runStage($blueprint, 'D2', function () use ($subdomain, $industry): string {
          $this->drush(['dx:portal-seed', $industry], $subdomain);
          return "Seeded {$industry} demo content.";
        });
        $checklist[] = $this->check('Demo content seeded', TRUE, $industry);
      }
      elseif ($contentSource === 'migrate') {
        $this->logStep($blueprint, 'D2', 'skipped', 'Legacy migration deferred to Phase DZ.');
        $checklist[] = $this->check('Legacy migration', FALSE, 'Manual — Phase DZ');
      }
      else {
        $this->logStep($blueprint, 'D2', 'skipped', 'Blank start — no seed content.');
        $checklist[] = $this->check('Content seed', TRUE, 'Blank start');
      }

      // D3 — Theme Studio skin.
      $skin = (string) ($blueprint->get('theme_skin')->value ?: 'portal');
      $this->runStage($blueprint, 'D3', function () use ($subdomain, $skin): string {
        $this->drush(['pm:enable', 'dx_theme', 'dx_portal_theme', '--yes'], $subdomain);
        $this->drush(['theme:enable', 'dx_portal_theme', '--yes'], $subdomain);
        $this->drush(['config:set', 'system.theme', 'default', 'dx_portal_theme', '-y'], $subdomain);
        $this->drush(['dx:theme-apply', $skin, '--yes'], $subdomain);
        return "Applied theme skin {$skin}.";
      });
      $checklist[] = $this->check('Theme applied', TRUE, $skin);

      // D5 — App Store packages.
      $appIds = $blueprint->getAppPackageIds();
      $enabledApps = [];
      if ($appIds !== []) {
        $this->runStage($blueprint, 'D5', function () use ($blueprint, $tenantMachine, $appIds, &$enabledApps): string {
          $packageStorage = $this->entityTypeManager->getStorage('dx_app_package');
          $requestStorage = $this->entityTypeManager->getStorage('dx_install_request');

          foreach ($appIds as $machineName) {
            $packages = $packageStorage->loadByProperties(['machine_name' => $machineName]);
            if (!$packages) {
              throw new \RuntimeException("App package '{$machineName}' not found.");
            }
            /** @var \Drupal\dx_appstore\Entity\AppPackage $package */
            $package = reset($packages);

            /** @var \Drupal\dx_appstore\Entity\InstallRequest $request */
            $request = $requestStorage->create([
              'app_id' => $package->id(),
              'tenant_machine' => $tenantMachine,
              'status' => 'pending',
              'requester_uid' => $this->currentUser->id(),
              'notes' => 'Turnkey delivery blueprint #' . $blueprint->id(),
            ]);
            $request->save();

            $this->appInstaller->approveAndInstall($request);
            $enabledApps[] = $machineName;
          }
          return 'Enabled: ' . implode(', ', $enabledApps);
        });
        $checklist[] = $this->check('App packages enabled', TRUE, implode(', ', $enabledApps));
      }
      else {
        $this->logStep($blueprint, 'D5', 'skipped', 'No optional apps selected.');
        $checklist[] = $this->check('App packages', TRUE, 'None selected');
      }

      // D6 channels — placeholder flags only in MVP.
      $channels = $blueprint->getChannels();
      $channelNotes = [];
      foreach ($channels as $channel => $enabled) {
        if (!$enabled || $channel === 'web') {
          continue;
        }
        $channelNotes[] = "{$channel}: planned (Phase EA)";
        $checklist[] = $this->check(ucfirst(str_replace('_', ' ', $channel)), FALSE, 'Phase EA — not yet packaged');
      }
      $checklist[] = $this->check('Web portal', TRUE, $portalUrl);

      // D7 — Acceptance report.
      $report = [
        'portal_url' => $portalUrl,
        'tenant_machine' => $tenantMachine,
        'theme_skin' => $skin,
        'checklist' => $checklist,
        'channels_pending' => $channelNotes,
        'generated_at' => date('c'),
        'status' => 'completed',
      ];

      $this->runStage($blueprint, 'D7', function () use ($report): string {
        return 'Acceptance report generated with ' . count($report['checklist']) . ' items.';
      });

      $blueprint->set('acceptance_json', json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
      $blueprint->set('status', 'completed');
      $blueprint->save();

      return $report;
    }
    catch (\Throwable $exception) {
      $blueprint->set('status', 'failed');
      $blueprint->save();
      $this->logger->error('Delivery failed for blueprint @id: @msg', [
        '@id' => $blueprint->id(),
        '@msg' => $exception->getMessage(),
      ]);
      throw $exception;
    }
  }

  /**
   * Ensures a tenant entity exists for the blueprint.
   */
  protected function ensureTenant(Blueprint $blueprint, string $machineName): Tenant {
    $storage = $this->entityTypeManager->getStorage('dx_tenant');
    $existing = $storage->loadByProperties(['machine_name' => $machineName]);
    if ($existing) {
      /** @var \Drupal\dx_platform\Entity\Tenant $tenant */
      $tenant = reset($existing);
      if ($tenant->getStatus() === 'active') {
        return $tenant;
      }
      if ($tenant->getStatus() === 'draft' || $tenant->getStatus() === 'failed') {
        return $tenant;
      }
      throw new \RuntimeException("Tenant '{$machineName}' is in status {$tenant->getStatus()}.");
    }

    /** @var \Drupal\dx_platform\Entity\Tenant $tenant */
    $tenant = $storage->create([
      'machine_name' => $machineName,
      'label' => $blueprint->label(),
      'status' => 'draft',
      'plan' => 'starter',
      'owner_mail' => $blueprint->get('owner_mail')->value ?: 'admin@drupalx.local',
    ]);
    $tenant->save();
    return $tenant;
  }

  /**
   * Runs a single pipeline stage with logging.
   *
   * @param callable(): string $callback
   *   Returns a success message.
   */
  protected function runStage(Blueprint $blueprint, string $stage, callable $callback): string {
    $run = $this->startStep($blueprint, $stage);
    try {
      $message = $callback();
      $this->finishStep($run, 'completed', $message);
      return $message;
    }
    catch (\Throwable $exception) {
      $this->finishStep($run, 'failed', $exception->getMessage());
      throw $exception;
    }
  }

  /**
   * Starts a delivery run step entity.
   */
  protected function startStep(Blueprint $blueprint, string $stage): DeliveryRun {
    $storage = $this->entityTypeManager->getStorage('dx_delivery_run');
    /** @var \Drupal\dx_delivery\Entity\DeliveryRun $run */
    $run = $storage->create([
      'blueprint_id' => $blueprint->id(),
      'stage' => $stage,
      'status' => 'running',
      'started' => time(),
      'operator_uid' => $this->currentUser->id(),
    ]);
    $run->save();
    return $run;
  }

  /**
   * Finishes a delivery run step entity.
   */
  protected function finishStep(DeliveryRun $run, string $status, string $message): void {
    $run->set('status', $status);
    $run->set('message', $message);
    $run->set('finished', time());
    $run->save();
  }

  /**
   * Logs a skipped step without executing a callback.
   */
  protected function logStep(Blueprint $blueprint, string $stage, string $status, string $message): void {
    $run = $this->startStep($blueprint, $stage);
    $this->finishStep($run, $status, $message);
  }

  /**
   * Resolves tenant subdomain for cross-site Drush.
   */
  protected function resolveSubdomain(string $tenantMachine): string {
    $storage = $this->entityTypeManager->getStorage('dx_tenant');
    $tenants = $storage->loadByProperties(['machine_name' => $tenantMachine]);
    if ($tenants) {
      /** @var \Drupal\dx_platform\Entity\Tenant $tenant */
      $tenant = reset($tenants);
      $subdomain = (string) $tenant->get('subdomain')->value;
      if ($subdomain !== '') {
        return $subdomain;
      }
    }
    $suffix = getenv('DX_TENANT_SUFFIX') ?: $this->configFactory->get('dx_platform.settings')->get('default_tenant_suffix') ?: 'drupalx.local';
    return $tenantMachine . '.' . $suffix;
  }

  /**
   * Executes a Drush command on a tenant site.
   *
   * @param string[] $args
   *   Drush arguments after the binary name.
   */
  protected function drush(array $args, string $subdomain): void {
    $drush = dirname(DRUPAL_ROOT) . '/vendor/bin/drush';
    $timeout = (int) ($this->configFactory->get('dx_platform.settings')->get('provision_timeout') ?: 600);
    $command = array_merge([$drush], $args, ['--uri=http://' . $subdomain]);
    $process = new Process($command, dirname(DRUPAL_ROOT));
    $process->setTimeout($timeout);
    $process->run();
    if (!$process->isSuccessful()) {
      throw new \RuntimeException('Drush failed (' . implode(' ', $args) . '): ' . $process->getErrorOutput() . $process->getOutput());
    }
  }

  /**
   * Builds a checklist item.
   *
   * @return array<string, mixed>
   */
  protected function check(string $label, bool $passed, string $detail = ''): array {
    return [
      'label' => $label,
      'passed' => $passed,
      'detail' => $detail,
    ];
  }

  /**
   * Returns step logs for a blueprint.
   *
   * @return \Drupal\dx_delivery\Entity\DeliveryRun[]
   */
  public function getSteps(Blueprint $blueprint): array {
    $storage = $this->entityTypeManager->getStorage('dx_delivery_run');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('blueprint_id', $blueprint->id())
      ->sort('started', 'ASC')
      ->execute();
    return $storage->loadMultiple($ids);
  }

}
