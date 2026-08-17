<?php

declare(strict_types=1);

namespace Drupal\dx_ecosystem\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Stores DPA / DX-RAL acknowledgments for audit (OE1 / O3-A).
 */
final class AgreementAckStore {

  public function __construct(
    protected KeyValueFactoryInterface $keyValueFactory,
    protected AccountProxyInterface $currentUser,
    protected TimeInterface $time,
  ) {}

  /**
   * @param array<string, mixed> $context
   *   Optional keys: tenant_machine, app_id, request_id, source.
   *
   * @return array<string, mixed>
   */
  public function record(string $agreementId, string $version, array $context = [], ?int $uid = NULL): array {
    $uid = $uid ?? (int) $this->currentUser->id();
    $entry = [
      'agreement_id' => $agreementId,
      'version' => $version,
      'uid' => $uid,
      'created' => $this->time->getRequestTime(),
      'context' => $context,
    ];
    $key = $this->makeKey($agreementId, $version, $uid, $context);
    $this->store()->set($key, $entry);
    return $entry;
  }

  /**
   * @return array<string, mixed>|null
   */
  public function latestDpaForUser(?int $uid = NULL): ?array {
    $uid = $uid ?? (int) $this->currentUser->id();
    $best = NULL;
    foreach ($this->store()->getAll() as $row) {
      if (($row['agreement_id'] ?? '') !== 'dpa') {
        continue;
      }
      if ((int) ($row['uid'] ?? 0) !== $uid) {
        continue;
      }
      if ($best === NULL || (int) $row['created'] > (int) $best['created']) {
        $best = $row;
      }
    }
    return $best;
  }

  /**
   * @return list<array<string, mixed>>
   */
  public function listAll(): array {
    $all = array_values($this->store()->getAll());
    usort($all, static fn(array $a, array $b): int => ((int) $b['created']) <=> ((int) $a['created']));
    return $all;
  }

  protected function store(): \Drupal\Core\KeyValueStore\KeyValueStoreInterface {
    return $this->keyValueFactory->get('dx_ecosystem.acks');
  }

  /**
   * @param array<string, mixed> $context
   */
  protected function makeKey(string $agreementId, string $version, int $uid, array $context): string {
    $tenant = (string) ($context['tenant_machine'] ?? '');
    $app = (string) ($context['app_id'] ?? '');
    $req = (string) ($context['request_id'] ?? '');
    return implode(':', [$agreementId, $version, (string) $uid, $tenant, $app, $req, (string) $this->time->getRequestTime()]);
  }

}
