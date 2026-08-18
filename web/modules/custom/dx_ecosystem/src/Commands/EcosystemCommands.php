<?php

declare(strict_types=1);

namespace Drupal\dx_ecosystem\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\dx_ecosystem\Service\AgreementAckStore;
use Drupal\dx_ecosystem\Service\AgreementRepository;
use Drupal\dx_ecosystem\Service\DeveloperCertificationStore;
use Drupal\dx_ecosystem\Service\DeveloperGate;
use Drupal\dx_ecosystem\Service\PartnerDocRepository;
use Drupal\user\Entity\User;
use Drush\Commands\DrushCommands;

/**
 * Drush helpers for open ecosystem OE1 / OE2.
 */
final class EcosystemCommands extends DrushCommands {

  public function __construct(
    protected AgreementRepository $agreements,
    protected AgreementAckStore $acks,
    protected DeveloperCertificationStore $certs,
    protected DeveloperGate $gate,
    protected PartnerDocRepository $partnerDocs,
    protected ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct();
  }

  /**
   * List agreement versions.
   *
   * @command dx:ecosystem-agreements
   * @aliases dx-oe-agreements
   */
  public function listAgreements(): void {
    foreach ($this->agreements->manifest() as $id => $meta) {
      $this->io()->writeln(sprintf('%s v%s — %s', $id, $meta['version'] ?? '?', $meta['title'] ?? ''));
    }
  }

  /**
   * List partner vault docs (no access check — CLI ops).
   *
   * @command dx:ecosystem-partner-docs
   */
  public function listPartnerDocs(): void {
    foreach ($this->partnerDocs->manifest() as $id => $meta) {
      $this->io()->writeln(sprintf('%s v%s — %s [%s]', $id, $meta['version'] ?? '?', $meta['title'] ?? '', $meta['visibility'] ?? 'partner'));
    }
  }

  /**
   * Sign current DPA and move developer to pending certification.
   *
   * @command dx:ecosystem-sign-dpa
   * @option uid User id to attribute (default 1)
   */
  public function signDpa(array $options = ['uid' => 1]): void {
    $dpa = $this->agreements->currentDpa();
    if ($dpa === NULL) {
      throw new \RuntimeException('DPA missing');
    }
    $uid = (int) $options['uid'];
    $this->acks->record('dpa', $dpa['version'], ['source' => 'drush'], $uid);
    $cert = $this->certs->markPending($uid, $dpa['version'], 'Signed via Drush');
    $this->logger()->success(sprintf(
      'Signed DPA v%s for uid %d → status=%s',
      $dpa['version'],
      $uid,
      $cert['status'],
    ));
  }

  /**
   * Certify a developer for L2 partner vault.
   *
   * @command dx:ecosystem-certify
   * @option uid User id
   * @option note Review note
   */
  public function certify(array $options = ['uid' => 1, 'note' => '']): void {
    $dpa = $this->agreements->currentDpa();
    if ($dpa === NULL) {
      throw new \RuntimeException('DPA missing');
    }
    $uid = (int) $options['uid'];
    $ack = $this->acks->latestDpaForUser($uid);
    if ($ack === NULL || ($ack['version'] ?? '') !== $dpa['version']) {
      throw new \RuntimeException('User must sign current DPA before certify');
    }
    $row = $this->certs->certify($uid, $dpa['version'], (string) $options['note'], 1);
    $this->logger()->success(sprintf('Certified uid %d (DPA v%s)', $row['uid'], $row['dpa_version']));
  }

  /**
   * Revoke developer certification.
   *
   * @command dx:ecosystem-revoke
   * @option uid User id
   * @option note Review note
   */
  public function revoke(array $options = ['uid' => 1, 'note' => '']): void {
    $uid = (int) $options['uid'];
    $row = $this->certs->revoke($uid, (string) $options['note'], 1);
    $this->logger()->success(sprintf('Revoked uid %d → %s', $row['uid'], $row['status']));
  }

  /**
   * Show personal registration switch, tenant_kind, and OE2 gate status.
   *
   * @command dx:ecosystem-status
   * @option uid User id for gate explain (default 1)
   */
  public function status(array $options = ['uid' => 1]): void {
    $cfg = $this->configFactory->get('dx_ecosystem.settings');
    $fields = \Drupal::service('entity_field.manager')->getFieldDefinitions('dx_tenant', 'dx_tenant');
    $ral = $this->agreements->currentRal();
    $dpa = $this->agreements->currentDpa();
    $uid = (int) $options['uid'];
    $loaded = User::load($uid);
    $account = $loaded ?: new AnonymousUserSession();
    $payload = [
      'personal_registration_enabled' => (bool) $cfg->get('personal_registration_enabled'),
      'require_ral_on_install' => (bool) $cfg->get('require_ral_on_install'),
      'ral' => $ral ? [
        'id' => $ral['id'],
        'version' => $ral['version'],
        'title' => $ral['title'],
      ] : NULL,
      'dpa' => $dpa ? [
        'id' => $dpa['id'],
        'version' => $dpa['version'],
        'title' => $dpa['title'],
      ] : NULL,
      'tenant_kind_field' => isset($fields['tenant_kind']),
      'ack_count' => count($this->acks->listAll()),
      'cert' => $this->certs->get($uid),
      'partner_gate' => $this->gate->explain($account),
      'partner_doc_count' => count($this->partnerDocs->manifest()),
      'pending_developers' => count($this->certs->listByStatus(DeveloperCertificationStore::STATUS_PENDING)),
      'certified_developers' => count($this->certs->listByStatus(DeveloperCertificationStore::STATUS_CERTIFIED)),
    ];
    $this->io()->writeln(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  }

}
