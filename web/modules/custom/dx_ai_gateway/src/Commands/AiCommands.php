<?php

declare(strict_types=1);

namespace Drupal\dx_ai_gateway\Commands;

use Drupal\dx_ai_gateway\Service\AiGateway;
use Drupal\dx_ai_gateway\Service\KnowledgeBase;
use Drupal\dx_ai_gateway\Service\UsageTracker;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for AI gateway.
 */
class AiCommands extends DrushCommands {

  public function __construct(
    protected AiGateway $aiGateway,
    protected UsageTracker $usageTracker,
    protected KnowledgeBase $knowledgeBase,
  ) {
    parent::__construct();
  }

  /**
   * Load API keys from DX_AI_{PROVIDER}_KEY environment variables.
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
    $this->logger()->success('Loaded keys for: ' . implode(', ', $loaded));
  }

  /**
   * Test an AI provider connection.
   *
   * @command dx:ai-test
   * @param string $provider
   *   Provider machine name (default: configured default).
   * @usage dx:ai-test deepseek
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
      ['Period', 'Used', 'Quota', 'Remaining', 'Source', 'Calls', 'OK'],
      [[$s['period'], $s['tokens_used'], $s['quota'], $s['remaining'], $s['quota_source'] ?? 'platform', $s['calls'], $s['ok_calls']]]
    );
  }

  /**
   * Preview knowledge-base context injected into the system prompt.
   *
   * @command dx:ai-knowledge
   */
  public function knowledge(): void {
    if (!$this->knowledgeBase->isEnabled()) {
      $this->logger()->warning('Knowledge base injection is disabled in AI gateway settings.');
      return;
    }
    $context = $this->knowledgeBase->buildContext();
    if ($context === '') {
      $this->logger()->warning('No product/company knowledge found.');
      return;
    }
    $this->io()->writeln($context);
  }

}
