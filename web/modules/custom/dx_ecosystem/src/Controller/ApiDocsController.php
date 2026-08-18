<?php

declare(strict_types=1);

namespace Drupal\dx_ecosystem\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\dx_ecosystem\Service\PublicTreePublisher;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public DXEP API documentation (OE3).
 */
final class ApiDocsController extends ControllerBase {

  public function __construct(
    protected PublicTreePublisher $publisher,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('dx_ecosystem.public_tree'));
  }

  /**
   * Swagger UI for DXEP v1.
   */
  public function docs(): array {
    $spec = Url::fromRoute('dx_ecosystem.openapi_yaml')->toString();
    return [
      '#theme' => 'dx_api_docs',
      '#spec_url' => $spec,
      '#title' => $this->t('DrupalX DXEP API'),
      '#attached' => [
        'library' => ['dx_ecosystem/api_docs'],
        'drupalSettings' => [
          'dxEcosystem' => ['specUrl' => $spec],
        ],
      ],
      '#cache' => ['max-age' => 300],
    ];
  }

  /**
   * Serves the committed OpenAPI document.
   */
  public function openapi(): Response {
    try {
      $yaml = $this->publisher->openapiContents();
    }
    catch (\RuntimeException $e) {
      throw new NotFoundHttpException($e->getMessage());
    }
    return new Response($yaml, 200, [
      'Content-Type' => 'application/yaml; charset=UTF-8',
      'Cache-Control' => 'public, max-age=300',
    ]);
  }

}
