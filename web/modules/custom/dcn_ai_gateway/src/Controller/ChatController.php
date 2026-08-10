<?php

declare(strict_types=1);

namespace Drupal\dcn_ai_gateway\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\dcn_ai_gateway\Service\AiGateway;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * JSON chat endpoint controller.
 */
class ChatController extends ControllerBase {

  /**
   * Constructs a ChatController.
   */
  public function __construct(
    protected AiGateway $aiGateway,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('dcn_ai_gateway.gateway'),
    );
  }

  /**
   * Handles chat POST requests.
   */
  public function chat(Request $request): JsonResponse {
    $payload = json_decode($request->getContent(), TRUE);
    $message = trim((string) ($payload['message'] ?? ''));

    if ($message === '') {
      return new JsonResponse(['error' => 'Message is required.'], 400);
    }

    try {
      $result = $this->aiGateway->chat($message, $payload['provider'] ?? NULL);
      return new JsonResponse([
        'provider' => $result['provider'],
        'reply' => $result['content'],
        'tokens' => $result['tokens'],
      ]);
    }
    catch (\Throwable $exception) {
      return new JsonResponse(['error' => $exception->getMessage()], 502);
    }
  }

}
