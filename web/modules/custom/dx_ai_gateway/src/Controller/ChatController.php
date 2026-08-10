<?php

declare(strict_types=1);

namespace Drupal\dx_ai_gateway\Controller;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\dx_ai_gateway\Service\AiGateway;
use Drupal\dx_ai_gateway\Service\UsageTracker;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Chat API and page controller.
 */
class ChatController extends ControllerBase {

  public function __construct(
    protected AiGateway $aiGateway,
    protected UsageTracker $usageTracker,
    protected CsrfTokenGenerator $csrfToken,
    protected FloodInterface $flood,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('dx_ai_gateway.gateway'),
      $container->get('dx_ai_gateway.usage_tracker'),
      $container->get('csrf_token'),
      $container->get('flood'),
    );
  }

  /**
   * Full-page chat UI.
   */
  public function page(): array {
    $summary = $this->usageTracker->summary();
    return [
      '#theme' => 'dx_ai_chat_page',
      '#title' => $this->t('AI 客服'),
      '#chat' => [
        '#theme' => 'dx_ai_chat',
        '#messages' => [
          ['role' => 'assistant', 'content' => $this->t('你好，我是 DrupalX 智能客服。请问有什么可以帮您？')],
        ],
        '#endpoint' => '/dx/ai/chat',
      ],
      '#usage' => $summary,
      '#attached' => [
        'library' => ['dx_ai_gateway/chat'],
        'drupalSettings' => [
          'dxAiChat' => [
            'csrfToken' => $this->csrfToken->get('dx_ai_gateway.chat'),
          ],
        ],
      ],
    ];
  }

  /**
   * Handles chat POST requests.
   */
  public function chat(Request $request): JsonResponse {
    $token = $request->headers->get('X-CSRF-Token', '');
    if (!$this->csrfToken->validate($token, 'dx_ai_gateway.chat')) {
      return new JsonResponse(['error' => 'Invalid CSRF token.'], 403);
    }

    $floodName = 'dx_ai_gateway.chat';
    $floodId = $request->getClientIp() ?: 'unknown';
    if (!$this->flood->isAllowed($floodName, 30, 3600, $floodId)) {
      return new JsonResponse(['error' => 'Too many requests. Please try later.'], 429);
    }

    $payload = json_decode($request->getContent(), TRUE);
    if (!is_array($payload)) {
      return new JsonResponse(['error' => 'Invalid JSON.'], 400);
    }
    $message = trim((string) ($payload['message'] ?? ''));
    if ($message === '' || mb_strlen($message) > 4000) {
      return new JsonResponse(['error' => 'Message is required (max 4000 chars).'], 400);
    }

    try {
      $result = $this->aiGateway->chat($message, $payload['provider'] ?? NULL);
      $this->flood->register($floodName, 3600, $floodId);
      $summary = $this->usageTracker->summary();
      return new JsonResponse([
        'provider' => $result['provider'],
        'model' => $result['model'] ?? '',
        'reply' => $result['content'],
        'tokens' => $result['tokens'],
        'usage' => [
          'used' => $summary['tokens_used'],
          'quota' => $summary['quota'],
          'remaining' => $summary['remaining'],
        ],
      ]);
    }
    catch (\Throwable $exception) {
      return new JsonResponse(['error' => $exception->getMessage()], 502);
    }
  }

}
