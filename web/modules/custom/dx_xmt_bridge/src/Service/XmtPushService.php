<?php

namespace Drupal\dx_xmt_bridge\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\node\NodeInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;

/**
 * Push signed trusted content from DrupalX to XMT.
 */
class XmtPushService {

  public function __construct(
    protected Settings $settings,
    protected StateInterface $state,
    protected ClientInterface $httpClient,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Whether auto-push is enabled and configured.
   */
  public function isAutoPushEnabled(): bool {
    if (!$this->settings->get('xmt_auto_push', FALSE)) {
      return FALSE;
    }
    return $this->getSecret() !== '';
  }

  /**
   * Returns the shared bridge secret or empty string.
   */
  public function getSecret(): string {
    $secret = $this->settings->get('xmt_dx_bridge_secret');
    if (!$secret) {
      $secret = getenv('XMT_DX_BRIDGE_SECRET') ?: '';
    }
    return (string) $secret;
  }

  /**
   * Resolve approved DrupalX developer ID for this site.
   */
  public function getDeveloperId(): ?string {
    $direct = $this->settings->get('xmt_developer_id');
    if (is_string($direct) && $direct !== '') {
      return $direct;
    }

    $stateId = $this->state->get('dx_xmt_bridge.developer_id');
    if (is_string($stateId) && $stateId !== '') {
      return $stateId;
    }

    $map = $this->settings->get('xmt_developer_map');
    if (is_array($map)) {
      $sitePath = \Drupal::getContainer()->getParameter('site.path');
      $siteKey = basename($sitePath);
      if (!empty($map[$siteKey]) && is_string($map[$siteKey])) {
        return $map[$siteKey];
      }
      if (!empty($map['default']) && is_string($map['default'])) {
        return $map['default'];
      }
    }

    return NULL;
  }

  /**
   * Build trusted-content payload from a dx_media node.
   */
  public function buildPayloadFromNode(NodeInterface $node): ?array {
    if ($node->bundle() !== 'dx_media' || !$node->isPublished()) {
      return NULL;
    }

    $developer = $this->getDeveloperId();
    if (!$developer) {
      $this->logger()->warning('XMT auto-push skipped: no approved dx_developer_id mapping for node @nid.', [
        '@nid' => $node->id(),
      ]);
      return NULL;
    }

    $title = $node->label() ?? '';
    $body = '';
    if ($node->hasField('body') && !$node->get('body')->isEmpty()) {
      $body = (string) $node->get('body')->value;
    }
    if ($title === '' || $body === '') {
      $this->logger()->warning('XMT auto-push skipped: dx_media @nid missing title or body.', [
        '@nid' => $node->id(),
      ]);
      return NULL;
    }

    $payload = [
      'title' => $title,
      'body' => $body,
      'dx_developer_id' => $developer,
      'exp' => time() + 3600,
      'nonce' => bin2hex(random_bytes(8)),
      'external_id' => 'dx-media-' . $node->id(),
    ];

    if ($node->hasField('field_dx_domain') && !$node->get('field_dx_domain')->isEmpty()) {
      $payload['domain'] = (string) $node->get('field_dx_domain')->value;
    }

    return $payload;
  }

  /**
   * Push a dx_media node to XMT (no-op when disabled or misconfigured).
   */
  public function pushNode(NodeInterface $node): bool {
    if (!$this->isAutoPushEnabled()) {
      return FALSE;
    }

    $payload = $this->buildPayloadFromNode($node);
    if ($payload === NULL) {
      return FALSE;
    }

    return $this->pushPayload($payload);
  }

  /**
   * POST signed JSON payload to XMT trusted-content endpoint.
   */
  public function pushPayload(array $payload): bool {
    $secret = $this->getSecret();
    if ($secret === '') {
      $this->logger()->warning('XMT push skipped: bridge secret not configured.');
      return FALSE;
    }

    $endpoint = (string) ($this->settings->get('xmt_endpoint') ?: 'http://127.0.0.1/api/xmt/v1/trusted-content');
    $host = (string) ($this->settings->get('xmt_host') ?: 'xmt.wsl');

    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $signature = hash_hmac('sha256', $body, $secret);

    try {
      $response = $this->httpClient->request('POST', $endpoint, [
        RequestOptions::BODY => $body,
        RequestOptions::HEADERS => [
          'Content-Type' => 'application/json',
          'X-XMT-Signature' => $signature,
          'Host' => $host,
        ],
        RequestOptions::HTTP_ERRORS => FALSE,
        RequestOptions::TIMEOUT => 30,
      ]);
    }
    catch (\Throwable $e) {
      $this->logger()->warning('XMT push failed (network): @msg', ['@msg' => $e->getMessage()]);
      return FALSE;
    }

    $code = $response->getStatusCode();
    if ($code >= 400) {
      $this->logger()->warning('XMT push failed HTTP @code: @body', [
        '@code' => $code,
        '@body' => (string) $response->getBody(),
      ]);
      return FALSE;
    }

    $this->logger()->info('XMT trusted content pushed for external_id @id (HTTP @code).', [
      '@id' => $payload['external_id'] ?? 'unknown',
      '@code' => $code,
    ]);
    return TRUE;
  }

  /**
   * Simulate push for a node ID (returns payload + would-send info).
   */
  public function simulateNode(int $nid): array {
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node instanceof NodeInterface) {
      throw new \InvalidArgumentException("Node $nid not found.");
    }

    $payload = $this->buildPayloadFromNode($node);
    return [
      'enabled' => $this->isAutoPushEnabled(),
      'developer_id' => $this->getDeveloperId(),
      'endpoint' => $this->settings->get('xmt_endpoint') ?: 'http://127.0.0.1/api/xmt/v1/trusted-content',
      'host' => $this->settings->get('xmt_host') ?: 'xmt.wsl',
      'payload' => $payload,
    ];
  }

  protected function logger() {
    return $this->loggerFactory->get('dx_xmt_bridge');
  }

}
