<?php

declare(strict_types=1);

namespace Drupal\dx_health\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Platform / tenant health probes for acceptance.
 */
final class HealthChecker {

  public function __construct(
    private readonly EntityTypeManagerInterface $etm,
    private readonly ModuleHandlerInterface $modules,
  ) {}

  /**
   * @return array{ok: bool, checks: list<array{id: string, ok: bool, message: string}>}
   */
  public function platform(): array {
    $checks = [];
    foreach ([
      'dx_delivery', 'dx_channel', 'dx_platform', 'dx_theme',
      'dx_trust', 'dx_health', 'dx_migrate',
    ] as $mod) {
      $ok = $this->modules->moduleExists($mod);
      $checks[] = [
        'id' => 'module:' . $mod,
        'ok' => $ok,
        'message' => $ok ? 'enabled' : 'missing',
        'critical' => in_array($mod, ['dx_platform', 'dx_channel'], TRUE),
      ];
    }
    $checks[] = [
      'id' => 'php_version',
      'ok' => version_compare(PHP_VERSION, '8.2.0', '>='),
      'message' => PHP_VERSION,
      'critical' => TRUE,
    ];
    $drush = dirname(DRUPAL_ROOT) . '/vendor/bin/drush';
    $checks[] = [
      'id' => 'drush',
      'ok' => is_readable($drush),
      'message' => is_readable($drush) ? 'present' : 'missing',
      'critical' => TRUE,
    ];

    foreach (['/deliver', '/admin/dx/channel/audit'] as $path) {
      $probe = $this->probePath($path);
      $probe['critical'] = TRUE;
      $checks[] = $probe;
    }

    $failed = FALSE;
    foreach ($checks as $c) {
      if (!empty($c['critical']) && empty($c['ok'])) {
        $failed = TRUE;
        break;
      }
    }
    return ['ok' => !$failed, 'checks' => $checks];
  }

  /**
   * @return array{ok: bool, tenant: string, checks: list<array{id: string, ok: bool, message: string}>}
   */
  public function tenant(string $machine): array {
    $checks = [];
    $storage = $this->etm->getStorage('dx_tenant');
    $found = $storage->loadByProperties(['machine_name' => $machine]);
    $tenant = $found ? reset($found) : NULL;
    $checks[] = [
      'id' => 'tenant_exists',
      'ok' => (bool) $tenant,
      'message' => $tenant ? 'found' : 'not found (ok if skip-provision)',
    ];
    if ($tenant) {
      $status = method_exists($tenant, 'getStatus') ? (string) $tenant->getStatus() : (string) ($tenant->get('status')->value ?? '');
      $checks[] = [
        'id' => 'tenant_status',
        'ok' => $status !== '',
        'message' => $status !== '' ? $status : 'empty',
      ];
      $portal = (string) ($tenant->get('portal_url')->value ?? '');
      $checks[] = [
        'id' => 'portal_url',
        'ok' => $portal !== '',
        'message' => $portal !== '' ? $portal : 'empty',
      ];
    }
    return ['ok' => TRUE, 'tenant' => $machine, 'checks' => $checks];
  }

  /**
   * @return array{id: string, ok: bool, message: string}
   */
  protected function probePath(string $path): array {
    try {
      $request = \Symfony\Component\HttpFoundation\Request::create($path);
      $response = \Drupal::service('http_kernel')->handle($request);
      $code = $response->getStatusCode();
      $ok = $code < 500 && $code !== 404;
      return [
        'id' => 'http:' . $path,
        'ok' => $ok,
        'message' => 'status=' . $code,
      ];
    }
    catch (\Throwable $e) {
      return [
        'id' => 'http:' . $path,
        'ok' => FALSE,
        'message' => $e->getMessage(),
      ];
    }
  }

}
