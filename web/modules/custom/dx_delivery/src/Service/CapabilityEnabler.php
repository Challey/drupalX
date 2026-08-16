<?php

declare(strict_types=1);

namespace Drupal\dx_delivery\Service;

use Drupal\dx_delivery\Entity\DeliveryBlueprint;
use Symfony\Component\Process\Process;

/**
 * Maps blueprint capabilities to platform modules and enables them on tenant.
 */
final class CapabilityEnabler {

  /**
   * Capability → module machine names (must exist in codebase).
   *
   * @return array<string, array{modules: list<string>, label: string}>
   */
  public function map(): array {
    return [
      'commerce' => [
        'modules' => ['dx_payment'],
        'label' => '商城/支付',
      ],
      'opinion' => [
        'modules' => ['dx_opinion'],
        'label' => '舆情监控',
      ],
      'ai_chat' => [
        'modules' => ['dx_ai_gateway'],
        'label' => 'AI 客服',
      ],
      'oss' => [
        'modules' => ['dx_oss'],
        'label' => '对象存储',
      ],
    ];
  }

  /**
   * Enable capability modules on a tenant URI.
   *
   * @return array{id: string, ok: bool, message: string, enabled: list<string>, skipped: list<string>}
   */
  public function enableForBlueprint(DeliveryBlueprint $blueprint, string $tenantUri): array {
    $caps = $blueprint->getCapabilities();
    if ($caps === []) {
      return [
        'id' => 'capabilities',
        'ok' => TRUE,
        'message' => 'No capabilities selected',
        'enabled' => [],
        'skipped' => [],
      ];
    }

    $map = $this->map();
    $root = dirname(DRUPAL_ROOT);
    $drush = $root . '/vendor/bin/drush';
    $enabled = [];
    $skipped = [];
    $errors = [];

    foreach ($caps as $cap) {
      if (!isset($map[$cap])) {
        $skipped[] = $cap . '(unknown)';
        continue;
      }
      foreach ($map[$cap]['modules'] as $module) {
        $info = $root . '/web/modules/custom/' . $module . '/' . $module . '.info.yml';
        if (!is_readable($info)) {
          $skipped[] = $module . '(missing)';
          continue;
        }
        $proc = new Process([
          $drush,
          '--uri=' . $tenantUri,
          'pm:enable',
          $module,
          '-y',
        ], $root, NULL, NULL, 180);
        $proc->run();
        if ($proc->isSuccessful()) {
          $enabled[] = $module;
          $blueprint->appendLog("Enabled capability module $module ({$map[$cap]['label']})");
        }
        else {
          // Soft-fail: record and continue (tenant may lack deps).
          $errors[] = $module;
          $blueprint->appendLog('Capability enable soft-fail ' . $module . ': ' . trim($proc->getErrorOutput() ?: $proc->getOutput()));
        }
      }
    }

    return [
      'id' => 'capabilities',
      'ok' => TRUE,
      'message' => 'enabled=[' . implode(',', $enabled) . '] skipped=[' . implode(',', $skipped) . '] soft_fail=[' . implode(',', $errors) . ']',
      'enabled' => $enabled,
      'skipped' => $skipped,
    ];
  }

}
