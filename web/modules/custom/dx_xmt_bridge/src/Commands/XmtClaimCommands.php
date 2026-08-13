<?php

namespace Drupal\dx_xmt_bridge\Commands;

use Drush\Commands\DrushCommands;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;

/**
 * Issue signed claims and push trusted content to XMT.
 */
class XmtClaimCommands extends DrushCommands {

  /**
   * Returns the shared bridge secret.
   */
  protected function getSecret(): string {
    $secret = \Drupal::service('settings')->get('xmt_dx_bridge_secret');
    if (!$secret) {
      $secret = getenv('XMT_DX_BRIDGE_SECRET') ?: '';
    }
    if ($secret === '') {
      throw new \RuntimeException('Set xmt_dx_bridge_secret or XMT_DX_BRIDGE_SECRET.');
    }
    return (string) $secret;
  }

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

    $secret = $this->getSecret();

    $claim = [
      'publisher_name' => $options['name'] ?: $developer,
      'credit_code' => $options['credit'] ?: '',
      'website' => $options['website'] ?: '',
      'dx_developer_id' => $developer,
      'exp' => time() + 3600,
      'nonce' => bin2hex(random_bytes(8)),
    ];
    $body = json_encode($claim, JSON_UNESCAPED_UNICODE);
    $signature = hash_hmac('sha256', $body, $secret);

    $this->output()->writeln($body);
    $this->output()->writeln('');
    $this->output()->writeln('X-XMT-Signature: ' . $signature);
    $this->logger()->success('Claim issued. POST body to XMT /api/xmt/v1/dx-claim with header X-XMT-Signature.');
  }

  /**
   * Push signed trusted content to XMT.
   *
   * @param array $options
   *   Command options.
   *
   * @command dx:xmt-push-content
   * @option developer DrupalX developer ID.
   * @option title Article title.
   * @option body Article body HTML.
   * @option source-url Original source URL (fallback idempotency key).
   * @option external-id External content ID (preferred idempotency key).
   * @option domain Content domain tag.
   * @option endpoint XMT trusted-content URL.
   * @option host Host header for local vhost routing.
   * @usage drush dx:xmt-push-content --developer=dx-hm-100 --title="Title" --body="<p>Body</p>" --external-id=demo-1
   */
  public function pushContent(array $options = [
    'developer' => '',
    'title' => '',
    'body' => '',
    'source-url' => '',
    'external-id' => '',
    'domain' => '',
    'endpoint' => 'http://127.0.0.1/api/xmt/v1/trusted-content',
    'host' => 'xmt.wsl',
  ]): void {
    $developer = $options['developer'] ?: $this->io()->ask('Developer ID');
    if (!$developer) {
      throw new \InvalidArgumentException('Developer ID is required.');
    }
    if ($options['title'] === '' || $options['body'] === '') {
      throw new \InvalidArgumentException('Both --title and --body are required.');
    }

    $secret = $this->getSecret();

    $payload = [
      'title' => $options['title'],
      'body' => $options['body'],
      'dx_developer_id' => $developer,
      'exp' => time() + 3600,
      'nonce' => bin2hex(random_bytes(8)),
    ];
    if ($options['source-url'] !== '') {
      $payload['source_url'] = $options['source-url'];
    }
    if ($options['external-id'] !== '') {
      $payload['external_id'] = $options['external-id'];
    }
    if ($options['domain'] !== '') {
      $payload['domain'] = $options['domain'];
    }

    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $signature = hash_hmac('sha256', $body, $secret);

    $this->output()->writeln($body);
    $this->output()->writeln('');
    $this->output()->writeln('X-XMT-Signature: ' . $signature);

    $client = new Client(['http_errors' => FALSE, 'timeout' => 30]);
    $response = $client->post($options['endpoint'], [
      RequestOptions::BODY => $body,
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
        'X-XMT-Signature' => $signature,
        'Host' => $options['host'],
      ],
    ]);

    $this->output()->writeln('');
    $this->output()->writeln('HTTP ' . $response->getStatusCode());
    $this->output()->writeln((string) $response->getBody());

    if ($response->getStatusCode() >= 400) {
      throw new \RuntimeException('Push failed with HTTP ' . $response->getStatusCode());
    }
    $this->logger()->success('Trusted content pushed to XMT.');
  }

}
