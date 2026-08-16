<?php

declare(strict_types=1);

namespace Drupal\dx_channel\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\dx_channel\Service\ChannelAuth;
use Drupal\dx_channel\Service\ChannelEnvelope;
use Drupal\dx_channel\Service\ChannelAudit;
use Drupal\dx_channel\Service\ExchangeService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * DXEP Exchange HTTP endpoints (DE4).
 */
final class ExchangeController extends ControllerBase {

  public function __construct(
    private readonly ChannelAuth $auth,
    private readonly ChannelEnvelope $envelope,
    private readonly ExchangeService $exchange,
    private readonly ChannelAudit $audit,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('dx_channel.auth'),
      $container->get('dx_channel.envelope'),
      $container->get('dx_channel.exchange'),
      $container->get('dx_channel.audit'),
    );
  }

  /**
   * GET /api/dx/v1/exchange/changes
   */
  public function changes(Request $request): JsonResponse {
    $requestId = $this->envelope->newRequestId();
    $denied = $this->requireScope($request, 'exchange:read', $requestId);
    if ($denied !== NULL) {
      return $denied;
    }
    $since = $request->query->get('updated_since');
    $limit = (int) $request->query->get('limit', 100);
    $items = $this->exchange->changes(is_string($since) ? $since : NULL, $limit);
    return new JsonResponse(
      $this->envelope->ok(['changes' => $items], ['count' => count($items)], $requestId),
      200,
      $this->jsonHeaders(),
    );
  }

  /**
   * POST /api/dx/v1/exchange/push
   */
  public function push(Request $request): JsonResponse {
    $requestId = $this->envelope->newRequestId();
    $denied = $this->requireScope($request, 'exchange:write', $requestId);
    if ($denied !== NULL) {
      return $denied;
    }
    $body = json_decode($request->getContent(), TRUE);
    if (!is_array($body)) {
      return new JsonResponse(
        $this->envelope->error('DX.REQ.VALIDATION', 'Invalid JSON body', [], $requestId),
        400,
        $this->jsonHeaders(),
      );
    }
    $resources = $body['resources'] ?? $body;
    if (!is_array($resources)) {
      return new JsonResponse(
        $this->envelope->error('DX.REQ.VALIDATION', 'resources must be array', [], $requestId),
        400,
        $this->jsonHeaders(),
      );
    }
    $dryRun = filter_var($request->query->get('dry_run', FALSE), FILTER_VALIDATE_BOOLEAN);
    $review = filter_var($request->query->get('review', FALSE), FILTER_VALIDATE_BOOLEAN);
    $result = $this->exchange->push(array_values($resources), $dryRun, $review);
    $code = !empty($result['ok']) ? 200 : 400;
    if (!empty($result['ok']) && !empty($result['failed'])) {
      // Partial success style.
      $code = 200;
    }
    return new JsonResponse(
      $this->envelope->ok($result, [], $requestId),
      $code,
      $this->jsonHeaders(),
    );
  }

  /**
   * GET /api/dx/v1/exchange/packages
   */
  public function packages(Request $request): JsonResponse {
    $requestId = $this->envelope->newRequestId();
    $denied = $this->requireScope($request, 'exchange:read', $requestId);
    if ($denied !== NULL) {
      return $denied;
    }
    $list = $this->exchange->listPackages();
    return new JsonResponse(
      $this->envelope->ok(['packages' => $list], ['count' => count($list)], $requestId),
      200,
      $this->jsonHeaders(),
    );
  }

  /**
   * POST /api/dx/v1/exchange/packages
   */
  public function packageCreate(Request $request): JsonResponse {
    $requestId = $this->envelope->newRequestId();
    $denied = $this->requireScope($request, 'exchange:write', $requestId);
    if ($denied !== NULL) {
      return $denied;
    }
    $body = json_decode($request->getContent(), TRUE);
    if (!is_array($body)) {
      return new JsonResponse(
        $this->envelope->error('DX.REQ.VALIDATION', 'Invalid JSON body', [], $requestId),
        400,
        $this->jsonHeaders(),
      );
    }
    $result = $this->exchange->register($body);
    if (empty($result['ok'])) {
      return new JsonResponse(
        $this->envelope->error(
          'DX.EXCHANGE.PACKAGE_INVALID',
          'Package registration failed',
          $result['issues'] ?? [],
          $requestId,
        ),
        400,
        $this->jsonHeaders(),
      );
    }
    return new JsonResponse(
      $this->envelope->ok($result['package'] ?? [], [], $requestId),
      200,
      $this->jsonHeaders(),
    );
  }

  /**
   * GET /api/dx/v1/exchange/packages/{package_id}
   */
  public function packageGet(Request $request, string $package_id): JsonResponse {
    $requestId = $this->envelope->newRequestId();
    $denied = $this->requireScope($request, 'exchange:read', $requestId);
    if ($denied !== NULL) {
      return $denied;
    }
    $pkg = $this->exchange->getPackage($package_id);
    if ($pkg === NULL) {
      return new JsonResponse(
        $this->envelope->error('DX.RES.NOT_FOUND', 'Package not found', [], $requestId),
        404,
        $this->jsonHeaders(),
      );
    }
    return new JsonResponse(
      $this->envelope->ok([
        'package_id' => $pkg['package_id'] ?? $package_id,
        'status' => $pkg['status'] ?? '',
        'created_at' => $pkg['created_at'] ?? '',
        'manifest' => $pkg['manifest'] ?? [],
        'resource_count' => is_array($pkg['resources'] ?? NULL) ? count($pkg['resources']) : 0,
        'report' => $pkg['report'] ?? NULL,
      ], [], $requestId),
      200,
      $this->jsonHeaders(),
    );
  }

  /**
   * POST /api/dx/v1/exchange/packages/{package_id}/apply
   */
  public function packageApply(Request $request, string $package_id): JsonResponse {
    $requestId = $this->envelope->newRequestId();
    $denied = $this->requireScope($request, 'exchange:write', $requestId);
    if ($denied !== NULL) {
      return $denied;
    }
    $dryRun = filter_var($request->query->get('dry_run', FALSE), FILTER_VALIDATE_BOOLEAN);
    $result = $this->exchange->apply($package_id, $dryRun);
    if (isset($result['report']['error']) && $result['report']['error'] === 'package not found') {
      return new JsonResponse(
        $this->envelope->error('DX.RES.NOT_FOUND', 'Package not found', [], $requestId),
        404,
        $this->jsonHeaders(),
      );
    }
    $meta = [];
    if (!empty($result['failed'])) {
      $meta['code'] = 'DX.EXCHANGE.APPLY_PARTIAL';
    }
    return new JsonResponse(
      $this->envelope->ok($result, $meta, $requestId),
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
    // Accept exchange:* or broader write/read aliases used by ingest tokens.
    $aliases = [
      'exchange:read' => ['exchange:read', 'exchange:write', 'channel:read', 'ingest:write'],
      'exchange:write' => ['exchange:write', 'ingest:write'],
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
