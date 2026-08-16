<?php

declare(strict_types=1);

namespace Drupal\dx_channel\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\dx_channel\Service\ChannelAudit;
use Drupal\dx_channel\Service\ChannelAuth;
use Drupal\dx_channel\Service\ChannelEnvelope;
use Drupal\dx_channel\Service\WebhookService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * DXEP outbound webhook HTTP endpoints (DE5 deepen).
 */
final class WebhookController extends ControllerBase {

  public function __construct(
    private readonly ChannelAuth $auth,
    private readonly ChannelEnvelope $envelope,
    private readonly WebhookService $webhooks,
    private readonly ChannelAudit $audit,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('dx_channel.auth'),
      $container->get('dx_channel.envelope'),
      $container->get('dx_channel.webhook'),
      $container->get('dx_channel.audit'),
    );
  }

  /**
   * GET /api/dx/v1/webhooks
   */
  public function list(Request $request): JsonResponse {
    $requestId = $this->envelope->newRequestId();
    $denied = $this->requireScope($request, 'webhook:read', $requestId);
    if ($denied !== NULL) {
      return $denied;
    }
    $items = $this->webhooks->listEndpointsRedacted();
    return new JsonResponse(
      $this->envelope->ok(['endpoints' => $items], ['count' => count($items)], $requestId),
      200,
      $this->jsonHeaders(),
    );
  }

  /**
   * POST /api/dx/v1/webhooks
   */
  public function register(Request $request): JsonResponse {
    $requestId = $this->envelope->newRequestId();
    $denied = $this->requireScope($request, 'webhook:write', $requestId);
    if ($denied !== NULL) {
      return $denied;
    }
    $body = json_decode($request->getContent(), TRUE);
    if (!is_array($body) || empty($body['url']) || !is_string($body['url'])) {
      return new JsonResponse(
        $this->envelope->error('DX.REQ.VALIDATION', 'url is required', [
          ['field' => 'url', 'issue' => 'required'],
        ], $requestId),
        400,
        $this->jsonHeaders(),
      );
    }
    $events = $body['events'] ?? ['resource.published'];
    if (!is_array($events)) {
      $events = ['resource.published'];
    }
    $secret = isset($body['secret']) && is_string($body['secret']) ? $body['secret'] : '';
    try {
      $ep = $this->webhooks->register($body['url'], $secret, array_values(array_map('strval', $events)));
    }
    catch (\InvalidArgumentException $e) {
      return new JsonResponse(
        $this->envelope->error('DX.REQ.VALIDATION', $e->getMessage(), [
          ['field' => 'url', 'issue' => $e->getMessage()],
        ], $requestId),
        400,
        $this->jsonHeaders(),
      );
    }
    // Secret returned once on create (same pattern as channel tokens).
    return new JsonResponse(
      $this->envelope->ok($ep, [], $requestId),
      201,
      $this->jsonHeaders(),
    );
  }

  /**
   * POST /api/dx/v1/webhooks/test
   */
  public function test(Request $request): JsonResponse {
    $requestId = $this->envelope->newRequestId();
    $denied = $this->requireScope($request, 'webhook:write', $requestId);
    if ($denied !== NULL) {
      return $denied;
    }
    $result = $this->webhooks->dispatch('resource.published', [
      'type' => 'article',
      'external_id' => 'wh_http_test',
      'title' => 'Webhook HTTP test',
    ]);
    return new JsonResponse(
      $this->envelope->ok($result, [], $requestId),
      200,
      $this->jsonHeaders(),
    );
  }

  /**
   * GET /api/dx/v1/webhooks/dead-letters
   */
  public function deadLetters(Request $request): JsonResponse {
    $requestId = $this->envelope->newRequestId();
    $denied = $this->requireScope($request, 'webhook:read', $requestId);
    if ($denied !== NULL) {
      return $denied;
    }
    $limit = min(100, max(1, (int) $request->query->get('limit', 20)));
    $items = $this->webhooks->listDeadLetters($limit);
    return new JsonResponse(
      $this->envelope->ok(['dead_letters' => $items], ['count' => count($items)], $requestId),
      200,
      $this->jsonHeaders(),
    );
  }

  /**
   * DELETE /api/dx/v1/webhooks/{id}
   */
  public function revoke(Request $request, string $id): JsonResponse {
    $requestId = $this->envelope->newRequestId();
    $denied = $this->requireScope($request, 'webhook:write', $requestId);
    if ($denied !== NULL) {
      return $denied;
    }
    if (!$this->webhooks->revoke($id)) {
      return new JsonResponse(
        $this->envelope->error('DX.NOT_FOUND', 'Webhook endpoint not found', [
          ['field' => 'id', 'issue' => $id],
        ], $requestId),
        404,
        $this->jsonHeaders(),
      );
    }
    return new JsonResponse(
      $this->envelope->ok(['revoked' => $id], [], $requestId),
      200,
      $this->jsonHeaders(),
    );
  }

  protected function requireScope(Request $request, string $scope, string $requestId): ?JsonResponse {
    $token = $this->auth->authenticate($request);
    $route = $this->audit->routeFromRequest($request);
    if ($token === NULL) {
      $this->audit->record($route, '', 401, $requestId, ['scope' => $scope]);
      return new JsonResponse(
        $this->envelope->error('DX.AUTH.UNAUTHORIZED', 'Bearer token required (D10-B).', [], $requestId),
        401,
        $this->jsonHeaders(),
      );
    }
    $tokenId = (string) ($token['id'] ?? '');
    if (!$this->audit->allow($tokenId)) {
      $this->audit->record($route, $tokenId, 429, $requestId, ['scope' => $scope]);
      return new JsonResponse(
        $this->envelope->error('DX.RATE.LIMITED', 'Too many requests', [], $requestId),
        429,
        $this->jsonHeaders(),
      );
    }
    $aliases = [
      'webhook:read' => ['webhook:read', 'webhook:write', 'exchange:write', 'channel:read'],
      'webhook:write' => ['webhook:write', 'exchange:write'],
    ];
    $ok = FALSE;
    foreach ($aliases[$scope] ?? [$scope] as $candidate) {
      if ($this->auth->hasScope($token, $candidate)) {
        $ok = TRUE;
        break;
      }
    }
    if (!$ok) {
      $this->audit->record($route, $tokenId, 403, $requestId, ['scope' => $scope]);
      return new JsonResponse(
        $this->envelope->error(
          'DX.AUTH.FORBIDDEN',
          'Token scope does not allow ' . $scope,
          [['field' => 'scope', 'issue' => 'missing ' . $scope]],
          $requestId,
        ),
        403,
        $this->jsonHeaders(),
      );
    }
    $this->audit->record($route, $tokenId, 200, $requestId, ['scope' => $scope]);
    return NULL;
  }

  /**
   * @return array<string, string>
   */
  protected function jsonHeaders(): array {
    return [
      'Content-Type' => 'application/json; charset=utf-8',
      'Cache-Control' => 'private, no-store',
    ];
  }

}
