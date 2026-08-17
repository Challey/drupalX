<?php

declare(strict_types=1);

namespace Drupal\dx_ecosystem\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\dx_ecosystem\Service\AgreementAckStore;
use Drupal\dx_ecosystem\Service\AgreementRepository;
use Drush\Commands\DrushCommands;

/**
 * Drush helpers for open ecosystem OE1.
 */
final class EcosystemCommands extends DrushCommands {

  public function __construct(
    protected AgreementRepository $agreements,
    protected AgreementAckStore $acks,
    protected EntityTypeManagerInterface $entityTypeManager,
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
   * Sign current DPA for uid 1 or current CLI user context (uid 1 default).
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
    $this->logger()->success(sprintf('Signed DPA v%s for uid %d', $dpa['version'], $uid));
  }

  /**
   * Show personal registration switch and tenant_kind field presence.
   *
   * @command dx:ecosystem-status
   */
  public function status(): void {
    $cfg = $this->configFactory->get('dx_ecosystem.settings');
    $fields = \Drupal::service('entity_field.manager')->getFieldDefinitions('dx_tenant', 'dx_tenant');
    $ral = $this->agreements->currentRal();
    $dpa = $this->agreements->currentDpa();
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
    ];
    $this->io()->writeln(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  }

}
