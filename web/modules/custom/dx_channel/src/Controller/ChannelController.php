<?php

declare(strict_types=1);

namespace Drupal\dx_channel\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\dx_channel\Service\AppLayoutRepository;
use Drupal\dx_channel\Service\ChannelAuth;
use Drupal\dx_channel\Service\ChannelEnvelope;
use Drupal\dx_channel\Service\SiteProjector;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * DXEP Channel HTTP endpoints.
 */
final class ChannelController extends ControllerBase {

  public function __construct(
    protected ChannelAuth $auth,
    protected ChannelEnvelope $envelope,
    protected AppLayoutRepository $appLayout,
    protected SiteProjector $siteProjector,
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
    );
  }

  /**
   * GET /api/dx/v1/channel/site
   */
  public function site(Request $request): JsonResponse {
    $requestId = $this->envelope->newRequestId();
    $denied = $this->requireChannelRead($request, $requestId);
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
    $denied = $this->requireChannelRead($request, $requestId);
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
   * @return JsonResponse|null
   */
  protected function requireChannelRead(Request $request, string $requestId): ?JsonResponse {
    $token = $this->auth->authenticate($request);
    if ($token === NULL) {
      return new JsonResponse(
        $this->envelope->error('DX.AUTH.UNAUTHORIZED', 'Bearer token required (D10-B).', [], $requestId),
        401,
        $this->jsonHeaders(),
      );
    }
    if (!$this->auth->hasScope($token, 'channel:read')) {
      return new JsonResponse(
        $this->envelope->error(
          'DX.AUTH.FORBIDDEN',
          'Token scope does not allow channel:read',
          [['field' => 'scope', 'issue' => 'missing channel:read']],
          $requestId,
        ),
        403,
        $this->jsonHeaders(),
      );
    }
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
