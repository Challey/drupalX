<?php

declare(strict_types=1);

namespace Drupal\dx_certs\Commands;

use Drupal\dx_certs\Service\CertVault;
use Drush\Commands\DrushCommands;

/**
 * Certificate vault Drush.
 */
final class CertCommands extends DrushCommands {

  public function __construct(
    private readonly CertVault $vault,
  ) {
    parent::__construct();
  }

  /**
   * Show cert vault status (with readiness probes).
   *
   * @command dx:certs-status
   */
  public function status(): void {
    $this->io()->writeln(json_encode($this->vault->status(TRUE), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  }

  /**
   * Probe registered cert path readiness.
   *
   * @command dx:certs-check
   * @option id Limit to one cert id
   */
  public function check(array $options = ['id' => '']): void {
    $id = trim((string) ($options['id'] ?? ''));
    $result = $this->vault->check($id !== '' ? $id : NULL);
    $this->io()->writeln(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    if (empty($result['ok'])) {
      throw new \RuntimeException('Certificate readiness check failed');
    }
  }

  /**
   * Register a certificate path reference.
   *
   * @command dx:certs-register
   * @option platform ios|android|wechat
   * @option label Human label
   * @option expires Expiry ISO date
   * @param string $id Cert id
   * @param string $pathRef Filesystem or secret-manager reference
   */
  public function register(string $id, string $pathRef, array $options = [
    'platform' => 'android',
    'label' => '',
    'expires' => '',
  ]): void {
    $entry = $this->vault->register(
      $id,
      (string) $options['platform'],
      $pathRef,
      (string) $options['label'],
      (string) $options['expires'],
    );
    $this->io()->writeln(json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  }

  /**
   * Revoke a certificate path reference.
   *
   * @command dx:certs-revoke
   * @param string $id Cert id
   */
  public function revoke(string $id): void {
    $ok = $this->vault->revoke($id);
    $this->io()->writeln(json_encode(['ok' => $ok, 'revoked' => $id], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    if (!$ok) {
      throw new \RuntimeException('Cert id not found');
    }
  }

  /**
   * Print packer env hints for a platform.
   *
   * @command dx:certs-packer-env
   * @param string $platform ios|android|wechat
   */
  public function packerEnv(string $platform = 'android'): void {
    $env = $this->vault->packerEnv($platform);
    foreach ($env as $k => $v) {
      $this->io()->writeln($k . '=' . $v);
    }
  }

}
