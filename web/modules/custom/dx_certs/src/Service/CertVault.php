<?php

declare(strict_types=1);

namespace Drupal\dx_certs\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Certificate vault metadata (path refs only).
 */
final class CertVault {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * @return array{vault_root: string, entries: list<array<string, string>>}
   */
  public function status(): array {
    $c = $this->configFactory->get('dx_certs.settings');
    $entries = $c->get('entries') ?? [];
    return [
      'vault_root' => (string) ($c->get('vault_root') ?: '~/staging/drupalX/certs'),
      'entries' => is_array($entries) ? array_values($entries) : [],
    ];
  }

  /**
   * Register a cert path reference.
   *
   * @return array<string, string>
   */
  public function register(string $id, string $platform, string $pathRef, string $label = '', string $expiresAt = ''): array {
    $id = preg_replace('/[^a-z0-9_\-]/i', '', $id) ?: ('cert_' . substr(bin2hex(random_bytes(4)), 0, 8));
    $entry = [
      'id' => $id,
      'platform' => $platform,
      'label' => $label !== '' ? $label : $id,
      'path_ref' => $pathRef,
      'expires_at' => $expiresAt,
    ];
    $editable = $this->configFactory->getEditable('dx_certs.settings');
    $entries = $editable->get('entries') ?? [];
    if (!is_array($entries)) {
      $entries = [];
    }
    $replaced = FALSE;
    foreach ($entries as $i => $existing) {
      if (is_array($existing) && ($existing['id'] ?? '') === $id) {
        $entries[$i] = $entry;
        $replaced = TRUE;
        break;
      }
    }
    if (!$replaced) {
      $entries[] = $entry;
    }
    $editable->set('entries', array_values($entries))->save();
    return $entry;
  }

  /**
   * Resolve packer env hints for a tenant/platform.
   *
   * @return array<string, string>
   */
  public function packerEnv(string $platform): array {
    $status = $this->status();
    foreach ($status['entries'] as $entry) {
      if (($entry['platform'] ?? '') === $platform) {
        return [
          'DX_CERT_ID' => (string) ($entry['id'] ?? ''),
          'DX_CERT_PATH' => (string) ($entry['path_ref'] ?? ''),
          'DX_CERT_VAULT' => $status['vault_root'],
        ];
      }
    }
    return [
      'DX_CERT_VAULT' => $status['vault_root'],
    ];
  }

}
