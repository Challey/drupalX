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
   * Show cert vault status.
   *
   * @command dx:certs-status
   */
  public function status(): void {
    $this->io()->writeln(json_encode($this->vault->status(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
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
