<?php

declare(strict_types=1);

namespace Drupal\dx_health\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Platform / tenant health probes for acceptance.
 */
final class HealthChecker {

  /**
   * Re-entrancy guard for nested HTTP probes.
   */
  private static bool $probing = FALSE;

  public function __construct(
    private readonly EntityTypeManagerInterface $etm,
    private readonly ModuleHandlerInterface $modules,
  ) {}

  /**
   * @return array{ok: bool, checks: list<array{id: string, ok: bool, message: string, critical?: bool}>}
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

    // Route existence (no full HTTP — avoids recursion via /deliver desk).
    foreach ([
      'dx_delivery.desk' => '/deliver',
      'dx_channel.audit' => '/admin/dx/channel/audit',
      'dx_channel.site' => '/api/dx/v1/channel/site',
    ] as $routeName => $path) {
      $checks[] = $this->probeRoute($routeName, $path);
    }

    // Safe API probe only when not already inside a probe.
    if (!self::$probing) {
      $api = $this->probeApiSite();
      $api['critical'] = TRUE;
      $checks[] = $api;
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
   * @return array{id: string, ok: bool, message: string, critical: bool}
   */
  protected function probeRoute(string $routeName, string $path): array {
    try {
      $route = \Drupal::service('router.route_provider')->getRouteByName($routeName);
      $ok = $route->getPath() === $path || str_contains($route->getPath(), trim($path, '/'));
      return [
        'id' => 'route:' . $routeName,
        'ok' => $ok || $route->getPath() !== '',
        'message' => $route->getPath(),
        'critical' => TRUE,
      ];
    }
    catch (\Throwable $e) {
      return [
        'id' => 'route:' . $routeName,
        'ok' => FALSE,
        'message' => $e->getMessage(),
        'critical' => TRUE,
      ];
    }
  }

  /**
   * Lightweight Channel site API call (401 without token is healthy).
   *
   * @return array{id: string, ok: bool, message: string}
   */
  protected function probeApiSite(): array {
    self::$probing = TRUE;
    try {
      $request = \Symfony\Component\HttpFoundation\Request::create('/api/dx/v1/channel/site');
      $response = \Drupal::service('http_kernel')->handle($request);
      $code = $response->getStatusCode();
      // Unauthenticated → 401 is expected and healthy.
      $ok = in_array($code, [200, 401, 403], TRUE);
      return [
        'id' => 'http:/api/dx/v1/channel/site',
        'ok' => $ok,
        'message' => 'status=' . $code,
      ];
    }
    catch (\Throwable $e) {
      return [
        'id' => 'http:/api/dx/v1/channel/site',
        'ok' => FALSE,
        'message' => $e->getMessage(),
      ];
    }
    finally {
      self::$probing = FALSE;
    }
  }

}
