<?php

declare(strict_types=1);

namespace Drupal\dx_ecosystem\Service;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;

/**
 * OE2 partner vault / L2 gate: certified + current DPA version.
 */
final class DeveloperGate {

  public function __construct(
    protected DeveloperCertificationStore $certs,
    protected AgreementAckStore $acks,
    protected AgreementRepository $agreements,
  ) {}

  /**
   * Whether the account may access partner (L2) materials.
   */
  public function canAccessPartnerVault(AccountInterface $account): bool {
    if (!$account->isAuthenticated()) {
      return FALSE;
    }
    if ($account->hasPermission('administer dx ecosystem')) {
      return TRUE;
    }
    if (!$account->hasPermission('access dx partner vault')) {
      return FALSE;
    }
    $uid = (int) $account->id();
    $cert = $this->certs->get($uid);
    if ($cert['status'] !== DeveloperCertificationStore::STATUS_CERTIFIED) {
      return FALSE;
    }
    $dpa = $this->agreements->currentDpa();
    if ($dpa === NULL) {
      return FALSE;
    }
    if ($cert['dpa_version'] !== $dpa['version']) {
      return FALSE;
    }
    $ack = $this->acks->latestDpaForUser($uid);
    if ($ack === NULL || ($ack['version'] ?? '') !== $dpa['version']) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Route access result for partner docs.
   */
  public function partnerDocsAccess(AccountInterface $account): AccessResult {
    $allowed = $this->canAccessPartnerVault($account);
    return AccessResult::allowedIf($allowed)
      ->cachePerUser()
      ->addCacheTags(['dx_ecosystem:certs', 'dx_ecosystem:acks']);
  }

  /**
   * Human-readable gate status for UI / Drush.
   *
   * @return array{allowed: bool, status: string, reason: string, dpa_version: string}
   */
  public function explain(AccountInterface $account): array {
    $dpa = $this->agreements->currentDpa();
    $dpaVersion = $dpa['version'] ?? '';
    if (!$account->isAuthenticated()) {
      return ['allowed' => FALSE, 'status' => 'anonymous', 'reason' => 'login_required', 'dpa_version' => $dpaVersion];
    }
    if ($account->hasPermission('administer dx ecosystem')) {
      return ['allowed' => TRUE, 'status' => 'admin', 'reason' => 'administer', 'dpa_version' => $dpaVersion];
    }
    $cert = $this->certs->get((int) $account->id());
    if ($cert['status'] !== DeveloperCertificationStore::STATUS_CERTIFIED) {
      return [
        'allowed' => FALSE,
        'status' => $cert['status'],
        'reason' => 'not_certified',
        'dpa_version' => $dpaVersion,
      ];
    }
    if ($dpa === NULL || $cert['dpa_version'] !== $dpaVersion) {
      return [
        'allowed' => FALSE,
        'status' => $cert['status'],
        'reason' => 'dpa_version_mismatch',
        'dpa_version' => $dpaVersion,
      ];
    }
    $ack = $this->acks->latestDpaForUser((int) $account->id());
    if ($ack === NULL || ($ack['version'] ?? '') !== $dpaVersion) {
      return [
        'allowed' => FALSE,
        'status' => $cert['status'],
        'reason' => 'dpa_ack_missing',
        'dpa_version' => $dpaVersion,
      ];
    }
    if (!$account->hasPermission('access dx partner vault')) {
      return [
        'allowed' => FALSE,
        'status' => $cert['status'],
        'reason' => 'missing_permission',
        'dpa_version' => $dpaVersion,
      ];
    }
    return [
      'allowed' => TRUE,
      'status' => $cert['status'],
      'reason' => 'ok',
      'dpa_version' => $dpaVersion,
    ];
  }

}
