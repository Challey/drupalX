<?php

declare(strict_types=1);

namespace Drupal\dx_channel\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\dx_channel\Service\AppLayoutRepository;
use Drupal\dx_channel\Service\ChannelAuth;
use Drupal\dx_channel\Service\ChannelEnvelope;
use Drupal\dx_channel\Service\ContentProjector;
use Drupal\dx_channel\Service\ChannelAudit;
use Drupal\dx_channel\Service\IngestService;
use Drupal\dx_channel\Service\SiteProjector;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * DXEP Channel + Ingest HTTP endpoints.
 */
final class ChannelController extends ControllerBase {

  public function __construct(
    protected ChannelAuth $auth,
    protected ChannelEnvelope $envelope,
    protected AppLayoutRepository $appLayout,
    protected SiteProjector $siteProjector,
    protected ContentProjector $contentProjector,
    protected IngestService $ingest,
    protected ChannelAudit $audit,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('dx_channel.auth'),
      $container->get('dx_channel.envelope'),
      $container->get('dx_channel.app_layout'),
      $container->get('dx_channel.site_projector'),
      $container->get('dx_channel.content_projector'),
      $container->get('dx_channel.ingest'),
      $container->get('dx_channel.audit'),
    );
  }

  /**
   * GET /api/dx/v1/channel/site
   */
  public function site(Request $request): JsonResponse {
    $requestId = $this->envelope->newRequestId();
    $denied = $this->requireScope($request, 'channel:read', $requestId);
    if ($denied !== NULL) {
      return $denied;
    }

    return new JsonResponse(
      $this->envelope->ok($this->siteProjector->project(), [], $requestId),
      200,
      $this->jsonHeaders(),
    );
  }

  /**
   * GET /api/dx/v1/channel/app-layout
   */
  public function appLayout(Request $request): Response {
    $requestId = $this->envelope->newRequestId();
    $denied = $this->requireScope($request, 'channel:read', $requestId);
    if ($denied !== NULL) {
      return $denied;
    }

    $revision = $this->appLayout->getRevision();
    $since = $request->query->get('since_revision');
    if ($since !== NULL && $since !== '' && (int) $since === $revision) {
      return new Response('', 304, [
        'X-DX-Layout-Revision' => (string) $revision,
        'X-DX-Request-Id' => $requestId,
      ]);
    }

    $layout = $this->appLayout->getLayout();
    return new JsonResponse(
      $this->envelope->ok($layout, [
        'revision' => $revision,
      ], $requestId),
      200,
      $this->jsonHeaders() + [
        'X-DX-Layout-Revision' => (string) $revision,
        'ETag' => '"' . ($layout['checksum'] ?? $revision) . '"',
        'Cache-Control' => 'private, max-age=60',
      ],
    );
  }

  /**
   * GET /api/dx/v1/channel/contents
   */
  public function contents(Request $request): JsonResponse {
    $requestId = $this->envelope->newRequestId();
    $denied = $this->requireScope($request, 'channel:read', $requestId);
    if ($denied !== NULL) {
      return $denied;
    }

    $type = (string) ($request->query->get('type') ?: 'article');
    $page = max(1, (int) $request->query->get('page', 1));
    $pageSize = min(100, max(1, (int) $request->query->get('page_size', 20)));
    $result = $this->contentProjector->list($type, $page, $pageSize, FALSE);

    return new JsonResponse(
      $this->envelope->ok($result['items'], [
        'page' => $page,
        'page_size' => $pageSize,
        'total' => $result['total'],
      ], $requestId),
      200,
      $this->jsonHeaders() + ['Cache-Control' => 'private, max-age=60'],
    );
  }

  /**
   * GET /api/dx/v1/channel/contents/{id}
   */
  public function content(Request $request, string $id): JsonResponse {
    $requestId = $this->envelope->newRequestId();
    $denied = $this->requireScope($request, 'channel:read', $requestId);
    if ($denied !== NULL) {
      return $denied;
    }

    $item = $this->contentProjector->getByDxId($id, TRUE);
    if ($item === NULL) {
      return new JsonResponse(
        $this->envelope->error('DX.RES.NOT_FOUND', 'Content not found', [], $requestId),
        404,
        $this->jsonHeaders(),
      );
    }

    return new JsonResponse(
      $this->envelope->ok($item, [], $requestId),
      200,
      $this->jsonHeaders() + ['Cache-Control' => 'private, max-age=60'],
    );
  }

  /**
   * GET /api/dx/v1/channel/products
   */
  public function products(Request $request): JsonResponse {
    $requestId = $this->envelope->newRequestId();
    $denied = $this->requireScope($request, 'channel:read', $requestId);
    if ($denied !== NULL) {
      return $denied;
    }

    $page = max(1, (int) $request->query->get('page', 1));
    $pageSize = min(100, max(1, (int) $request->query->get('page_size', 20)));
    $result = $this->contentProjector->list('product', $page, $pageSize, FALSE);

    return new JsonResponse(
      $this->envelope->ok($result['items'], [
        'page' => $page,
        'page_size' => $pageSize,
        'total' => $result['total'],
      ], $requestId),
      200,
      $this->jsonHeaders() + ['Cache-Control' => 'private, max-age=60'],
    );
  }

  /**
   * PUT /api/dx/v1/ingest/resources/{type}/{external_id}
   */
  public function ingestUpsert(Request $request, string $type, string $external_id): JsonResponse {
    $requestId = $this->envelope->newRequestId();
    $denied = $this->requireScope($request, 'ingest:write', $requestId);
    if ($denied !== NULL) {
      return $denied;
    }

    $payload = json_decode($request->getContent(), TRUE);
    if (!is_array($payload)) {
      return new JsonResponse(
        $this->envelope->error('DX.REQ.VALIDATION', 'Invalid JSON body', [], $requestId),
        400,
        $this->jsonHeaders(),
      );
    }

    $dryRun = filter_var($request->query->get('dry_run', FALSE), FILTER_VALIDATE_BOOLEAN);
    $review = filter_var($request->query->get('review', FALSE), FILTER_VALIDATE_BOOLEAN);
    $result = $this->ingest->upsert($type, $external_id, $payload, $dryRun, $review);
    if (empty($result['ok'])) {
      return new JsonResponse(
        $this->envelope->error(
          'DX.REQ.VALIDATION',
          'Ingest validation failed',
          $result['issues'] ?? [],
          $requestId,
        ),
        400,
        $this->jsonHeaders(),
      );
    }

    return new JsonResponse(
      $this->envelope->ok($result['resource'] ?? $result, [
        'dry_run' => !empty($result['dry_run']),
      ], $requestId),
      200,
      $this->jsonHeaders(),
    );
  }

  /**
   * @return JsonResponse|null
   */
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
    if (!$this->auth->hasScope($token, $scope)) {
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
