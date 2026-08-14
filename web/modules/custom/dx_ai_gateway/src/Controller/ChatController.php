<?php

declare(strict_types=1);

namespace Drupal\dx_ai_gateway\Controller;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Url;
use Drupal\dx_ai_gateway\Service\AiGateway;
use Drupal\dx_ai_gateway\Service\UsageTracker;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        '#endpoint' => Url::fromRoute('dx_ai_gateway.chat_stream')->toString(),
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

  /**
   * Streams chat deltas using server-sent events.
   */
  public function stream(Request $request): StreamedResponse|JsonResponse {
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
    $history = $this->normalizeHistory($payload['history'] ?? []);
    if ($history === NULL) {
      return new JsonResponse(['error' => 'Invalid conversation history.'], 400);
    }
    $provider = is_string($payload['provider'] ?? NULL) ? $payload['provider'] : NULL;

    $response = new StreamedResponse(function () use ($message, $history, $provider, $floodName, $floodId): void {
      try {
        foreach ($this->aiGateway->chatStream($message, $history, $provider) as $event) {
          $this->sendEvent($event['type'], $event);
        }
        $this->flood->register($floodName, 3600, $floodId);
      }
      catch (\Throwable $exception) {
        $this->sendEvent('error', ['message' => $exception->getMessage()]);
      }
    });
    $response->headers->set('Content-Type', 'text/event-stream');
    $response->headers->set('Cache-Control', 'no-cache, no-transform');
    $response->headers->set('X-Accel-Buffering', 'no');
    return $response;
  }

  /**
   * Validates and limits conversation history supplied by the browser.
   *
   * @return array<int, array{role: string, content: string}>|null
   */
  protected function normalizeHistory(mixed $history): ?array {
    if (!is_array($history) || count($history) > 12) {
      return NULL;
    }
    $normalized = [];
    foreach ($history as $entry) {
      if (!is_array($entry) || !in_array($entry['role'] ?? '', ['user', 'assistant'], TRUE) || !is_string($entry['content'] ?? NULL)) {
        return NULL;
      }
      $content = trim($entry['content']);
      if ($content === '' || mb_strlen($content) > 4000) {
        return NULL;
      }
      $normalized[] = ['role' => $entry['role'], 'content' => $content];
    }
    return $normalized;
  }

  /**
   * Writes one event and flushes it to the client.
   */
  protected function sendEvent(string $event, array $data): void {
    echo 'event: ' . $event . "\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    if (ob_get_level() > 0) {
      ob_flush();
    }
    flush();
  }

}
