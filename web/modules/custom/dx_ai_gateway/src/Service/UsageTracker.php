<?php

declare(strict_types=1);

namespace Drupal\dx_ai_gateway\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\State\StateInterface;

/**
 * Tracks AI token usage by calendar month.
 */
class UsageTracker {

  public function __construct(
    protected Connection $database,
    protected StateInterface $state,
    protected ConfigFactoryInterface $configFactory,
    protected AccountProxyInterface $currentUser,
  ) {}

  /**
   * Returns current billing period (YYYY-MM).
   */
  public function currentPeriod(): string {
    return date('Y-m');
  }

  /**
   * Tokens used in the current (or given) period.
   */
  public function tokensUsed(?string $period = NULL): int {
    $period = $period ?: $this->currentPeriod();
    return (int) $this->state->get($this->stateKey($period), 0);
  }

  /**
   * Effective monthly quota (tenant override or platform default).
   */
  public function monthlyQuota(): int {
    $tenantConfig = $this->configFactory->get('dx_tenant.settings');
    if ($tenantConfig->get('ai_quota_override')) {
      return max(0, (int) $tenantConfig->get('ai_quota_monthly'));
    }

    $platformQuota = $this->configFactory
      ->get('dx_ai_gateway.settings')
      ->get('monthly_quota');
    return $platformQuota === NULL ? 100000 : max(0, (int) $platformQuota);
  }

  /**
   * Whether another call with $tokens would stay under quota.
   */
  public function canConsume(int $tokens = 0): bool {
    // A request always consumes tokens, even before the exact provider usage is
    // known. Requiring at least one remaining token also makes a zero quota an
    // effective tenant-level kill switch.
    return ($this->tokensUsed() + max(1, $tokens)) <= $this->monthlyQuota();
  }

  /**
   * Remaining tokens this period.
   */
  public function remaining(): int {
    return max(0, $this->monthlyQuota() - $this->tokensUsed());
  }

  /**
   * Records a successful or failed call.
   */
  public function record(string $provider, string $model, int $tokens, string $status, string $messagePreview = ''): void {
    $period = $this->currentPeriod();
    if ($status === 'ok' && $tokens > 0) {
      $this->state->set($this->stateKey($period), $this->tokensUsed($period) + $tokens);
    }

    $this->database->insert('dx_ai_usage')
      ->fields([
        'uid' => (int) $this->currentUser->id(),
        'provider' => mb_substr($provider, 0, 64),
        'model' => mb_substr($model, 0, 128),
        'tokens' => max(0, $tokens),
        'status' => mb_substr($status, 0, 32),
        'message_preview' => mb_substr($messagePreview, 0, 255),
        'created' => \Drupal::time()->getRequestTime(),
        'period' => $period,
      ])
      ->execute();
  }

  /**
   * Recent usage rows for dashboards.
   *
   * @return array<int, object>
   */
  public function recent(int $limit = 20): array {
    if (!$this->database->schema()->tableExists('dx_ai_usage')) {
      return [];
    }
    return $this->database->select('dx_ai_usage', 'u')
      ->fields('u')
      ->orderBy('id', 'DESC')
      ->range(0, $limit)
      ->execute()
      ->fetchAll();
  }

  /**
   * Summary for the current period.
   */
  public function summary(?string $period = NULL): array {
    $period = $period ?: $this->currentPeriod();
    $calls = 0;
    $ok = 0;
    if ($this->database->schema()->tableExists('dx_ai_usage')) {
      $calls = (int) $this->database->select('dx_ai_usage', 'u')
        ->condition('period', $period)
        ->countQuery()
        ->execute()
        ->fetchField();
      $ok = (int) $this->database->select('dx_ai_usage', 'u')
        ->condition('period', $period)
        ->condition('status', 'ok')
        ->countQuery()
        ->execute()
        ->fetchField();
    }
    return [
      'period' => $period,
      'tokens_used' => $this->tokensUsed($period),
      'quota' => $this->monthlyQuota(),
      'remaining' => max(0, $this->monthlyQuota() - $this->tokensUsed($period)),
      'calls' => $calls,
      'ok_calls' => $ok,
    ];
  }

  /**
   * State key for a period.
   */
  protected function stateKey(string $period): string {
    return 'dx_ai_gateway.tokens_used.' . $period;
  }

}
