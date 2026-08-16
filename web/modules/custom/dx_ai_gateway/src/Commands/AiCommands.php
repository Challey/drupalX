<?php

declare(strict_types=1);

namespace Drupal\dx_ai_gateway\Commands;

use Drupal\dx_ai_gateway\Service\AiGateway;
use Drupal\dx_ai_gateway\Service\UsageTracker;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for AI gateway.
 */
class AiCommands extends DrushCommands {

  public function __construct(
    protected AiGateway $aiGateway,
    protected UsageTracker $usageTracker,
  ) {
    parent::__construct();
  }

  /**
   * Check inherited DX_AI_{PROVIDER}_KEY environment variables.
   *
   * @command dx:ai-keys-from-env
   * @usage dx:ai-keys-from-env
   */
  public function keysFromEnv(): void {
    $loaded = $this->aiGateway->loadKeysFromEnv();
    if (!$loaded) {
      $this->logger()->warning('No DX_AI_*_KEY env vars found.');
      return;
    }
    $this->logger()->success('Platform environment keys available for: ' . implode(', ', $loaded));
  }

  /**
   * Test an AI provider connection.
   *
   * @command dx:ai-test
   * @param string $provider
   *   Provider machine name (default: configured default).
   * @usage dx:ai-test deepseek
   * @usage dx:ai-test drupal_ai
   */
  public function test(string $provider = ''): void {
    $provider = $provider !== '' ? $provider : $this->aiGateway->getDefaultProvider();
    try {
      $result = $this->aiGateway->testProvider($provider);
      $this->io()->success(sprintf(
        '%s / %s → %s (tokens=%d)',
        $result['provider'],
        $result['model'] ?? '',
        mb_substr((string) $result['content'], 0, 160),
        (int) ($result['tokens'] ?? 0)
      ));
    }
    catch (\Throwable $e) {
      $this->logger()->error($e->getMessage());
    }
  }

  /**
   * Show AI usage summary.
   *
   * @command dx:ai-usage
   */
  public function usage(): void {
    $s = $this->usageTracker->summary();
    $this->io()->table(
      ['Period', 'Used', 'Quota', 'Remaining', 'Calls', 'OK'],
      [[$s['period'], $s['tokens_used'], $s['quota'], $s['remaining'], $s['calls'], $s['ok_calls']]]
    );
  }

  /**
   * Show AI provider key readiness (no secrets).
   *
   * @command dx:ai-status
   */
  public function status(): void {
    $config = \Drupal::config('dx_ai_gateway.settings');
    $providers = $config->get('providers') ?? [];
    $default = $this->aiGateway->getDefaultProvider();
    $rows = [];
    $ready = 0;
    if (is_array($providers)) {
      foreach ($providers as $id => $meta) {
        $has = $this->aiGateway->hasApiKey((string) $id);
        if ($has) {
          $ready++;
        }
        $rows[] = [
          'id' => (string) $id,
          'label' => (string) ($meta['label'] ?? $id),
          'configured' => $has,
          'source' => $this->aiGateway->getApiKeySource((string) $id),
          'default' => (string) $id === $default,
        ];
      }
    }
    $this->io()->writeln(json_encode([
      'default_provider' => $default,
      'ready_count' => $ready,
      'providers' => $rows,
      'hint' => $ready === 0 ? 'Set DX_AI_{PROVIDER}_KEY or configure keys at /admin/dx/ai' : 'ok',
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  }

}
