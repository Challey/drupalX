<?php

declare(strict_types=1);

namespace Drupal\dx_ai_gateway\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Tracks AI token usage by calendar month.
 */
class UsageTracker {

  public function __construct(
    protected Connection $database,
    protected ConfigFactoryInterface $configFactory,
    protected AccountProxyInterface $currentUser,
    protected LockBackendInterface $lock,
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
    if (!$this->database->schema()->tableExists('dx_ai_usage')) {
      return 0;
    }
    $query = $this->database->select('dx_ai_usage', 'u')
      ->condition('period', $period);
    $query->addExpression('COALESCE(SUM(tokens), 0)', 'total');
    return (int) $query
      ->execute()
      ->fetchField();
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
    return ($this->tokensUsed() + $this->reservedTokens() + max(1, $tokens)) <= $this->monthlyQuota();
  }

  /**
   * Remaining tokens this period.
   */
  public function remaining(): int {
    return max(0, $this->monthlyQuota() - $this->tokensUsed() - $this->reservedTokens());
  }

  /**
   * Atomically reserves estimated input and bounded output tokens.
   *
   * @return array{id: string, period: string, tokens: int, max_output: int}|null
   *   Reservation details, or NULL when the quota cannot fit the input.
   */
  public function reserve(int $inputTokens, int $desiredOutput = 2048): ?array {
    $period = $this->currentPeriod();
    if (!$this->acquireMutationLock($period)) {
      throw new \RuntimeException('AI quota is busy. Please retry.');
    }
    try {
      $this->deleteExpiredReservations();
      $remaining = max(
        0,
        $this->monthlyQuota() - $this->tokensUsed($period) - $this->reservedTokens($period),
      );
      $maxOutput = min(max(1, $desiredOutput), $remaining - max(1, $inputTokens));
      if ($maxOutput < 1) {
        return NULL;
      }
      $tokens = max(1, $inputTokens) + $maxOutput;
      $id = bin2hex(random_bytes(16));
      $this->database->insert('dx_ai_quota_reservation')
        ->fields([
          'id' => $id,
          'period' => $period,
          'tokens' => $tokens,
          'expires' => time() + 600,
        ])
        ->execute();
      return [
        'id' => $id,
        'period' => $period,
        'tokens' => $tokens,
        'max_output' => $maxOutput,
      ];
    }
    finally {
      $this->lock->release($this->lockKey($period));
    }
  }

  /**
   * Atomically converts a reservation into a final usage row.
   */
  public function complete(
    string $id,
    string $period,
    string $provider,
    string $model,
    int $tokens,
    string $status,
    string $messagePreview = '',
  ): void {
    if (!$this->acquireMutationLock($period)) {
      throw new \RuntimeException('AI quota settlement is busy. Please retry.');
    }
    $transaction = $this->database->startTransaction();
    try {
      $reservedTokens = (int) $this->database
        ->select('dx_ai_quota_reservation', 'r')
        ->fields('r', ['tokens'])
        ->condition('id', $id)
        ->condition('period', $period)
        ->forUpdate()
        ->execute()
        ->fetchField();
      if ($reservedTokens < 1) {
        throw new \RuntimeException('AI quota reservation is missing or expired.');
      }
      $tokens = min(max(0, $tokens), $reservedTokens);
      $this->record($provider, $model, $tokens, $status, $messagePreview, $period);
      $this->database->delete('dx_ai_quota_reservation')
        ->condition('id', $id)
        ->execute();
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      $this->lock->release($this->lockKey($period));
      throw $exception;
    }
    try {
      // Commit before releasing the application-level quota lock.
      unset($transaction);
    }
    finally {
      $this->lock->release($this->lockKey($period));
    }
  }

  /**
   * Releases a reservation that produced no billable response.
   */
  public function cancel(string $id, string $period): void {
    if (!$this->acquireMutationLock($period)) {
      // Expiry still guarantees eventual recovery if immediate cleanup fails.
      return;
    }
    try {
      $this->database->delete('dx_ai_quota_reservation')
        ->condition('id', $id)
        ->execute();
    }
    finally {
      $this->lock->release($this->lockKey($period));
    }
  }

  /**
   * Records a successful or failed call.
   */
  public function record(
    string $provider,
    string $model,
    int $tokens,
    string $status,
    string $messagePreview = '',
    ?string $period = NULL,
  ): void {
    $period = $period ?: $this->currentPeriod();
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
      'remaining' => max(
        0,
        $this->monthlyQuota() - $this->tokensUsed($period) - $this->reservedTokens($period),
      ),
      'calls' => $calls,
      'ok_calls' => $ok,
    ];
  }

  /**
   * Lock name for quota mutations in the current billing period.
   */
  protected function lockKey(string $period): string {
    return 'dx_ai_gateway.quota.' . $period;
  }

  /**
   * Acquires the short-lived lock used for State read-modify-write cycles.
   */
  protected function acquireMutationLock(string $period): bool {
    $key = $this->lockKey($period);
    if ($this->lock->acquire($key, 10.0)) {
      return TRUE;
    }
    $this->lock->wait($key, 2);
    return $this->lock->acquire($key, 10.0);
  }

  /**
   * Returns active reserved tokens for a billing period.
   */
  protected function reservedTokens(?string $period = NULL): int {
    if (!$this->database->schema()->tableExists('dx_ai_quota_reservation')) {
      return 0;
    }
    $period = $period ?: $this->currentPeriod();
    $query = $this->database->select('dx_ai_quota_reservation', 'r')
      ->condition('period', $period)
      ->condition('expires', time(), '>');
    $query->addExpression('COALESCE(SUM(tokens), 0)', 'total');
    return (int) $query
      ->execute()
      ->fetchField();
  }

  /**
   * Reclaims reservations left behind by interrupted workers.
   */
  protected function deleteExpiredReservations(): void {
    $this->database->delete('dx_ai_quota_reservation')
      ->condition('expires', time(), '<=')
      ->execute();
  }

}
