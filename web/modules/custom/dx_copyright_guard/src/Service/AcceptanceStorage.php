<?php

declare(strict_types=1);

namespace Drupal\dx_copyright_guard\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Stores and queries copyright/confidentiality agreement acceptances.
 */
final class AcceptanceStorage {

  public function __construct(
    private readonly Connection $database,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly RequestStack $requestStack,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Current agreement version.
   */
  public function getVersion(): string {
    $version = (string) ($this->configFactory->get('dx_copyright_guard.settings')->get('agreement_version') ?? '');
    return $version !== '' ? $version : '1.0';
  }

  /**
   * Whether a user accepted the current agreement version.
   */
  public function hasAccepted(int $uid): bool {
    if ($uid <= 0) {
      return FALSE;
    }
    $count = $this->database->select('dx_copyright_acceptance', 'a')
      ->condition('uid', $uid)
      ->condition('agreement_version', $this->getVersion())
      ->countQuery()
      ->execute()
      ->fetchField();
    return (int) $count > 0;
  }

  /**
   * Records an acceptance (idempotent per user + version).
   */
  public function record(int $uid, string $path): void {
    if ($uid <= 0) {
      return;
    }
    $request = $this->requestStack->getCurrentRequest();
    $ip = $request?->getClientIp() ?? '';
    $userAgent = substr((string) ($request?->headers->get('User-Agent') ?? ''), 0, 512);
    $this->database->merge('dx_copyright_acceptance')
      ->keys([
        'uid' => $uid,
        'agreement_version' => $this->getVersion(),
      ])
      ->fields([
        'accepted_at' => $this->time->getRequestTime(),
        'ip' => substr($ip, 0, 45),
        'user_agent' => $userAgent,
        'path' => substr($path, 0, 255),
      ])
      ->execute();
  }

  /**
   * Returns recent acceptance rows.
   *
   * @return array<int, object>
   *   Acceptance records, newest first.
   */
  public function getRecent(int $limit = 500): array {
    return $this->database->select('dx_copyright_acceptance', 'a')
      ->fields('a')
      ->orderBy('accepted_at', 'DESC')
      ->range(0, $limit)
      ->execute()
      ->fetchAll();
  }

}
