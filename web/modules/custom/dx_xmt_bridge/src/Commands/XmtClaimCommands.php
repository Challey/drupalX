<?php

namespace Drupal\dx_xmt_bridge\Commands;

use Drush\Commands\DrushCommands;

/**
 * Issue signed claims for XMT publisher provisioning.
 */
class XmtClaimCommands extends DrushCommands {

  /**
   * Print JSON claim + HMAC signature for a DrupalX developer.
   *
   * @param array $options
   *   Command options.
   *
   * @command dx:xmt-issue-claim
   * @option developer DrupalX developer ID.
   * @option name Company / publisher name (defaults to developer ID).
   * @option credit Unified social credit code.
   * @option website Company website URL.
   * @usage drush dx:xmt-issue-claim --developer=DX123 --name="Acme Ltd"
   */
  public function issueClaim(array $options = [
    'developer' => '',
    'name' => '',
    'credit' => '',
    'website' => '',
  ]): void {
    $developer = $options['developer'] ?: $this->io()->ask('Developer ID');
    if (!$developer) {
      throw new \InvalidArgumentException('Developer ID is required.');
    }

    $secret = \Drupal::service('settings')->get('xmt_dx_bridge_secret');
    if (!$secret) {
      $secret = getenv('XMT_DX_BRIDGE_SECRET') ?: '';
    }
    if ($secret === '') {
      throw new \RuntimeException('Set xmt_dx_bridge_secret or XMT_DX_BRIDGE_SECRET.');
    }

    $claim = [
      'publisher_name' => $options['name'] ?: $developer,
      'credit_code' => $options['credit'] ?: '',
      'website' => $options['website'] ?: '',
      'dx_developer_id' => $developer,
      'exp' => time() + 3600,
      'nonce' => bin2hex(random_bytes(8)),
    ];
    $body = json_encode($claim, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $signature = hash_hmac('sha256', json_encode($claim, JSON_UNESCAPED_UNICODE), $secret);

    $this->output()->writeln($body);
    $this->output()->writeln('');
    $this->output()->writeln('X-XMT-Signature: ' . $signature);
    $this->logger()->success('Claim issued. POST body to XMT /api/xmt/v1/dx-claim with header X-XMT-Signature.');
  }

}
