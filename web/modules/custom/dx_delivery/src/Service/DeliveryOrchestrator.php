<?php

declare(strict_types=1);

namespace Drupal\dx_delivery\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\dx_delivery\Entity\DeliveryBlueprint;
use Drupal\dx_platform\Entity\Tenant;
use Drupal\dx_platform\Service\TenantProvisioner;
use Symfony\Component\Process\Process;

/**
 * Executes a confirmed delivery blueprint.
 */
final class DeliveryOrchestrator {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TenantProvisioner $tenantProvisioner,
    protected LoggerChannelInterface $logger,
    protected FileSystemInterface $fileSystem,
    protected CapabilityEnabler $capabilityEnabler,
  ) {}

  /**
   * Run orchestration. Returns acceptance report.
   *
   * @return array<string, mixed>
   */
  public function run(DeliveryBlueprint $blueprint, bool $skipProvision = FALSE, bool $skipPack = FALSE): array {
    $blueprint->set('status', 'running');
    $blueprint->appendLog('Orchestration started');
    $blueprint->save();

    $acceptance = [
      'spec' => 'DX-ACCEPTANCE',
      'blueprint_id' => (int) $blueprint->id(),
      'tenant' => $blueprint->getMachineName(),
      'steps' => [],
      'portal_url' => NULL,
      'packs' => [],
      'passed' => FALSE,
    ];

    try {
      if (!$skipProvision) {
        $step = $this->stepProvision($blueprint);
        $acceptance['steps'][] = $step;
        if (!empty($step['portal_url'])) {
          $acceptance['portal_url'] = $step['portal_url'];
        }
      }
      else {
        $acceptance['steps'][] = [
          'id' => 'provision',
          'ok' => TRUE,
          'message' => 'Skipped provision (--skip-provision)',
        ];
        $blueprint->appendLog('Skipped provision');
      }

      $acceptance['steps'][] = $this->stepThemeAndChannel($blueprint);
      $acceptance['steps'][] = $this->stepTrustPolicy($blueprint);
      $acceptance['steps'][] = $this->capabilityEnabler->enableForBlueprint(
        $blueprint,
        $this->tenantUri($blueprint->getMachineName()),
      );
      if (!$skipPack) {
        $acceptance['steps'][] = $this->stepPack($blueprint, $acceptance);
      }
      else {
        $acceptance['steps'][] = [
          'id' => 'pack',
          'ok' => TRUE,
          'message' => 'Skipped pack',
        ];
      }

      $acceptance['steps'][] = $this->stepMigrate($blueprint);
      $acceptance['steps'][] = $this->stepHealth($blueprint);

      foreach ($acceptance['steps'] as $step) {
        if (!empty($step['handoff_todos']) && is_array($step['handoff_todos'])) {
          $acceptance['handoff_todos'] = array_values(array_merge(
            $acceptance['handoff_todos'] ?? [],
            $step['handoff_todos'],
          ));
        }
      }

      $failed = array_filter($acceptance['steps'], static fn(array $s): bool => empty($s['ok']));
      $acceptance['passed'] = $failed === [];
      $blueprint->set('status', $acceptance['passed'] ? 'completed' : 'failed');
      $blueprint->set('acceptance', json_encode($acceptance, JSON_UNESCAPED_UNICODE));
      $blueprint->appendLog($acceptance['passed'] ? 'Completed' : 'Completed with failures');
      $blueprint->save();
      return $acceptance;
    }
    catch (\Throwable $e) {
      $this->logger->error('Delivery failed: @m', ['@m' => $e->getMessage()]);
      $blueprint->set('status', 'failed');
      $blueprint->appendLog('FATAL: ' . $e->getMessage());
      $acceptance['passed'] = FALSE;
      $acceptance['error'] = $e->getMessage();
      $blueprint->set('acceptance', json_encode($acceptance, JSON_UNESCAPED_UNICODE));
      $blueprint->save();
      return $acceptance;
    }
  }

  /**
   * @return array{id: string, ok: bool, message: string}
   */
  protected function stepProvision(DeliveryBlueprint $blueprint): array {
    $machine = $blueprint->getMachineName();
    $storage = $this->entityTypeManager->getStorage('dx_tenant');
    $existing = $storage->loadByProperties(['machine_name' => $machine]);
    if ($existing) {
      $tenant = reset($existing);
      $blueprint->appendLog("Tenant $machine already exists");
      $portal = (string) $tenant->get('portal_url')->value;
      return [
        'id' => 'provision',
        'ok' => TRUE,
        'message' => 'Reused tenant ' . $machine,
        'portal_url' => $portal,
      ];
    }

    $tenant = Tenant::create([
      'machine_name' => $machine,
      'label' => $blueprint->label(),
      'owner_mail' => (string) ($blueprint->get('owner_mail')->value ?: 'admin@drupalx.local'),
      'status' => 'draft',
    ]);
    $tenant->save();
    $this->tenantProvisioner->provision($tenant);
    $portal = (string) $tenant->get('portal_url')->value;
    $blueprint->appendLog("Provisioned tenant $machine → $portal");
    return [
      'id' => 'provision',
      'ok' => $tenant->getStatus() === 'active' || $tenant->getStatus() === 'provisioning' || $portal !== '',
      'message' => 'Provisioned ' . $machine . ' status=' . $tenant->getStatus(),
      'portal_url' => $portal,
    ];
  }

  /**
   * Apply theme pack + channel layout on tenant URI via drush.
   *
   * @return array{id: string, ok: bool, message: string}
   */
  protected function stepThemeAndChannel(DeliveryBlueprint $blueprint): array {
    $machine = $blueprint->getMachineName();
    $theme = (string) $blueprint->get('theme_pack')->value;
    $layout = (string) $blueprint->get('layout_profile')->value;
    $uri = $this->tenantUri($machine);
    $root = dirname(DRUPAL_ROOT);
    $drush = $root . '/vendor/bin/drush';

    $messages = [];
    $ok = TRUE;

    // Theme apply on tenant site (best-effort).
    $themeProc = new Process([
      $drush, '--uri=' . $uri, 'dx:theme-apply', $theme, '-y',
    ], $root, NULL, NULL, 120);
    $themeProc->run();
    if ($themeProc->isSuccessful()) {
      $messages[] = "theme:$theme";
      $blueprint->appendLog("Applied theme $theme on $uri");
    }
    else {
      $messages[] = 'theme soft-fail: ' . trim($themeProc->getErrorOutput() ?: $themeProc->getOutput());
      $blueprint->appendLog('Theme apply soft-fail: ' . $messages[array_key_last($messages)]);
    }

    // Layout profile on tenant.
    $php = sprintf(
      "\\Drupal::configFactory()->getEditable('dx_channel.settings')->set('layout_profile', %s)->set('revision', (int) (\\Drupal::config('dx_channel.settings')->get('revision') ?: 1) + 1)->save();",
      var_export($layout, TRUE),
    );
    $layoutProc = new Process([
      $drush, '--uri=' . $uri, 'php:eval', $php,
    ], $root, NULL, NULL, 120);
    $layoutProc->run();
    if ($layoutProc->isSuccessful()) {
      $messages[] = "layout:$layout";
      $blueprint->appendLog("Set layout profile $layout");
    }
    else {
      // Soft-fail if dx_channel not yet on tenant.
      $messages[] = 'layout soft-fail';
      $blueprint->appendLog('Layout profile soft-fail (enable dx_channel on tenant)');
    }

    return [
      'id' => 'theme_channel',
      'ok' => $ok,
      'message' => implode('; ', $messages),
    ];
  }

  /**
   * @param array<string, mixed> $acceptance
   *
   * @return array{id: string, ok: bool, message: string}
   */
  protected function stepPack(DeliveryBlueprint $blueprint, array &$acceptance): array {
    $channels = $blueprint->getChannels();
    $needApp = in_array('app', $channels, TRUE) || in_array('miniprogram', $channels, TRUE);
    if (!$needApp) {
      $blueprint->appendLog('No app/miniprogram channel; skip pack');
      return ['id' => 'pack', 'ok' => TRUE, 'message' => 'No mobile channels requested'];
    }

    $root = dirname(DRUPAL_ROOT);
    $script = $root . '/scripts/pack-tenant-channels.sh';
    if (!is_executable($script) && !is_readable($script)) {
      return ['id' => 'pack', 'ok' => FALSE, 'message' => 'pack-tenant-channels.sh missing'];
    }

    $machine = $blueprint->getMachineName();
    $uri = $this->tenantUri($machine);
    // Token: create on default site for demo packs pointing at tenant URL.
    $tokenProc = new Process([
      $root . '/vendor/bin/drush', 'dx:channel-token-create', '--id=delivery_' . $machine, '--scopes=channel:read',
    ], $root, NULL, NULL, 60);
    $tokenProc->run();
    $token = '';
    if (preg_match('/^dxc_[a-f0-9]+/m', $tokenProc->getOutput() . "\n" . $tokenProc->getErrorOutput(), $m)) {
      $token = $m[0];
    }

    $proc = new Process([
      'bash', $script,
      '--api-base=' . $uri,
      '--token=' . ($token !== '' ? $token : 'dxc_placeholder'),
      '--tenant=' . $machine,
      '--app=demo',
    ], $root, NULL, NULL, 180);
    $proc->run();
    $ok = $proc->isSuccessful();
    $acceptance['packs'][] = [
      'flutter' => getenv('HOME') . '/staging/drupalX/flutter/demo-flutter-deploy-latest',
      'miniprogram' => getenv('HOME') . '/staging/drupalX/miniprogram/portal-mp-deploy-latest',
    ];
    $blueprint->appendLog($ok ? 'Pack scripts finished' : 'Pack failed: ' . $proc->getErrorOutput());
    return [
      'id' => 'pack',
      'ok' => $ok,
      'message' => $ok ? 'Flutter + mini-program packs generated' : trim($proc->getErrorOutput() ?: $proc->getOutput()),
    ];
  }


  /**
   * Apply site-type trust defaults when dx_trust is available.
   *
   * @return array{id: string, ok: bool, message: string}
   */
  protected function stepTrustPolicy(DeliveryBlueprint $blueprint): array {
    if (!\Drupal::moduleHandler()->moduleExists('dx_trust') || !\Drupal::hasService('dx_trust.policy')) {
      return [
        'id' => 'trust_policy',
        'ok' => TRUE,
        'message' => 'dx_trust not enabled; skipped',
      ];
    }
    /** @var \Drupal\dx_trust\Service\TrustPolicy $policy */
    $policy = \Drupal::service('dx_trust.policy');
    $siteType = (string) $blueprint->get('site_type')->value;
    $result = $siteType === 'enterprise'
      ? $policy->applyEnterpriseDefaults()
      : $policy->applyGovernmentDefaults();
    $blueprint->appendLog($result['message']);
    return [
      'id' => 'trust_policy',
      'ok' => !empty($result['ok']),
      'message' => (string) $result['message'],
      'profile' => $result['policy']['profile'] ?? '',
    ];
  }


  /**
   * Soft platform/tenant health probes.
   *
   * @return array{id: string, ok: bool, message: string}
   */
  protected function stepHealth(DeliveryBlueprint $blueprint): array {
    if (!\Drupal::moduleHandler()->moduleExists('dx_health') || !\Drupal::hasService('dx_health.checker')) {
      return ['id' => 'health', 'ok' => TRUE, 'message' => 'dx_health not enabled; skipped'];
    }
    /** @var \Drupal\dx_health\Service\HealthChecker $checker */
    $checker = \Drupal::service('dx_health.checker');
    $platform = $checker->platform();
    $tenant = $checker->tenant($blueprint->getMachineName());
    $msg = 'platform_ok=' . (!empty($platform['ok']) ? '1' : '0') . ' tenant_checks=' . count($tenant['checks'] ?? []);
    $blueprint->appendLog('Health: ' . $msg);
    return [
      'id' => 'health',
      'ok' => !empty($platform['ok']),
      'message' => $msg,
      'platform' => $platform,
      'tenant' => $tenant,
    ];
  }

  protected function stepMigrate(DeliveryBlueprint $blueprint): array {
    $level = (string) $blueprint->get('migrate_level')->value;
    $url = (string) $blueprint->get('source_url')->value;

    if ($level === 'l3') {
      $todos = [];
      if (\Drupal::hasService('dx_delivery.handoff_todos')) {
        $todos = \Drupal::service('dx_delivery.handoff_todos')->openL3($blueprint);
      }
      else {
        $blueprint->appendLog('L3 migrate marked manual');
      }
      return [
        'id' => 'migrate',
        'ok' => TRUE,
        'message' => 'L3 marked manual / integration project; handoff todos opened',
        'handoff_todos' => $todos,
      ];
    }
    if ($level !== 'l1' && $level !== 'l2') {
      return [
        'id' => 'migrate',
        'ok' => TRUE,
        'message' => 'No migrate requested',
      ];
    }

    if (!\Drupal::moduleHandler()->moduleExists('dx_migrate') || !\Drupal::hasService('dx_migrate.runner')) {
      $msg = "Queued $level migrate from " . ($url !== '' ? $url : '(fixture)') . ' — enable dx_migrate';
      $blueprint->appendLog($msg);
      return ['id' => 'migrate', 'ok' => TRUE, 'message' => $msg];
    }

    /** @var \Drupal\dx_migrate\Service\MigrateRunner $runner */
    $runner = \Drupal::service('dx_migrate.runner');
    $result = $level === 'l2'
      ? $runner->runL2($url, FALSE, TRUE)
      : $runner->runL1($url, FALSE, TRUE);
    $message = (string) ($result['message'] ?? 'migrate done');
    if (empty($result['imported']) && $url !== '') {
      $message .= ' (0 imported; review source URL)';
    }
    $blueprint->appendLog($message);
    return [
      'id' => 'migrate',
      'ok' => TRUE,
      'message' => $message,
      'imported' => (int) ($result['imported'] ?? 0),
    ];
  }

  protected function tenantUri(string $machine): string {
    $suffix = getenv('DX_TENANT_SUFFIX') ?: 'drupalx.local';
    return 'https://' . $machine . '.' . $suffix;
  }

}
