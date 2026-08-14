<?php

declare(strict_types=1);

namespace Drupal\dx_ai_gateway\Controller;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\dx_ai_gateway\Service\AiGateway;
use Drupal\dx_ai_gateway\Service\ChatSession;
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
    protected ChatSession $chatSession,
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
      $container->get('dx_ai_gateway.chat_session'),
      $container->get('csrf_token'),
      $container->get('flood'),
    );
  }

  /**
   * Full-page chat UI.
   */
  public function page(): array {
    $summary = $this->usageTracker->summary();
    $config = $this->config('dx_ai_gateway.settings');
    $streamEnabled = (bool) $config->get('enable_streaming');
    return [
      '#theme' => 'dx_ai_chat_page',
      '#title' => $this->t('AI 客服'),
      '#chat' => [
        '#theme' => 'dx_ai_chat',
        '#messages' => [
          ['role' => 'assistant', 'content' => $this->t('你好，我是 DrupalX 智能客服。请问有什么可以帮您？')],
        ],
        '#endpoint' => '/dx/ai/chat',
        '#stream_endpoint' => $streamEnabled ? '/dx/ai/chat/stream' : '',
      ],
      '#usage' => $summary,
      '#attached' => [
        'library' => ['dx_ai_gateway/chat'],
        'drupalSettings' => [
          'dxAiChat' => [
            'csrfToken' => $this->csrfToken->get('dx_ai_gateway.chat'),
            'stream' => $streamEnabled,
          ],
        ],
      ],
    ];
  }

  /**
   * Handles chat POST requests (JSON, non-streaming).
   */
  public function chat(Request $request): JsonResponse {
    $guard = $this->guardRequest($request);
    if ($guard instanceof JsonResponse) {
      return $guard;
    }
    [$message, $provider, $sessionId] = $guard;

    $history = $sessionId !== '' ? $this->chatSession->getHistory($sessionId) : [];

    try {
      $result = $this->aiGateway->chat($message, $provider, $history);
      $this->flood->register('dx_ai_gateway.chat', 3600, $request->getClientIp() ?: 'unknown');
      if ($sessionId !== '') {
        $this->chatSession->append($sessionId, 'user', $message);
        $this->chatSession->append($sessionId, 'assistant', (string) $result['content']);
      }
      $summary = $this->usageTracker->summary();
      return new JsonResponse([
        'provider' => $result['provider'],
        'model' => $result['model'] ?? '',
        'reply' => $result['content'],
        'tokens' => $result['tokens'],
        'session_id' => $sessionId,
        'usage' => [
          'used' => $summary['tokens_used'],
          'quota' => $summary['quota'],
          'remaining' => $summary['remaining'],
          'quota_source' => $summary['quota_source'] ?? 'platform',
        ],
      ]);
    }
    catch (\Throwable $exception) {
      return new JsonResponse(['error' => $exception->getMessage()], 502);
    }
  }

  /**
   * Handles streaming chat via Server-Sent Events.
   */
  public function chatStream(Request $request): JsonResponse|StreamedResponse {
    $config = $this->config('dx_ai_gateway.settings');
    if (!$config->get('enable_streaming')) {
      return new JsonResponse(['error' => 'Streaming is disabled.'], 400);
    }

    $guard = $this->guardRequest($request);
    if ($guard instanceof JsonResponse) {
      return $guard;
    }
    [$message, $provider, $sessionId] = $guard;
    $history = $sessionId !== '' ? $this->chatSession->getHistory($sessionId) : [];
    $floodId = $request->getClientIp() ?: 'unknown';

    $gateway = $this->aiGateway;
    $usageTracker = $this->usageTracker;
    $chatSession = $this->chatSession;
    $flood = $this->flood;

    $response = new StreamedResponse(function () use ($gateway, $usageTracker, $chatSession, $flood, $message, $provider, $sessionId, $history, $floodId): void {
      // Disable output buffering for progressive SSE.
      while (ob_get_level() > 0) {
        ob_end_flush();
      }
      $this->emitSse('meta', [
        'session_id' => $sessionId,
        'stream' => TRUE,
      ]);

      try {
        foreach ($gateway->chatStream($message, $provider, $history) as $chunk) {
          $this->emitSse('delta', ['text' => $chunk]);
        }
        $meta = $gateway->getLastStreamMeta();
        $flood->register('dx_ai_gateway.chat', 3600, $floodId);
        if ($sessionId !== '') {
          $chatSession->append($sessionId, 'user', $message);
          $chatSession->append($sessionId, 'assistant', (string) ($meta['content'] ?? ''));
        }
        $summary = $usageTracker->summary();
        $this->emitSse('done', [
          'provider' => $meta['provider'] ?? '',
          'model' => $meta['model'] ?? '',
          'tokens' => $meta['tokens'] ?? 0,
          'reply' => $meta['content'] ?? '',
          'session_id' => $sessionId,
          'usage' => [
            'used' => $summary['tokens_used'],
            'quota' => $summary['quota'],
            'remaining' => $summary['remaining'],
            'quota_source' => $summary['quota_source'] ?? 'platform',
          ],
        ]);
      }
      catch (\Throwable $exception) {
        $this->emitSse('error', ['error' => $exception->getMessage()]);
      }
    });

    $response->headers->set('Content-Type', 'text/event-stream; charset=utf-8');
    $response->headers->set('Cache-Control', 'no-cache, no-store');
    $response->headers->set('X-Accel-Buffering', 'no');
    $response->headers->set('Connection', 'keep-alive');
    return $response;
  }

  /**
   * Clears a chat session history.
   */
  public function clearSession(Request $request): JsonResponse {
    $token = $request->headers->get('X-CSRF-Token', '');
    if (!$this->csrfToken->validate($token, 'dx_ai_gateway.chat')) {
      return new JsonResponse(['error' => 'Invalid CSRF token.'], 403);
    }
    $payload = json_decode($request->getContent(), TRUE);
    $sessionId = is_array($payload) ? (string) ($payload['session_id'] ?? '') : '';
    if ($sessionId !== '') {
      $this->chatSession->clear($sessionId);
    }
    return new JsonResponse(['cleared' => TRUE, 'session_id' => $sessionId]);
  }

  /**
   * Validates CSRF, flood, and payload.
   *
   * @return array{0: string, 1: ?string, 2: string}|\Symfony\Component\HttpFoundation\JsonResponse
   */
  protected function guardRequest(Request $request): array|JsonResponse {
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

    $provider = isset($payload['provider']) && is_string($payload['provider']) && $payload['provider'] !== ''
      ? $payload['provider']
      : NULL;

    $sessionId = trim((string) ($payload['session_id'] ?? ''));
    if ($sessionId === '' || !preg_match('/^[a-f0-9]{16,64}$/', $sessionId)) {
      $sessionId = $this->chatSession->createId();
    }

    return [$message, $provider, $sessionId];
  }

  /**
   * Emits one SSE event.
   */
  protected function emitSse(string $event, array $data): void {
    echo 'event: ' . $event . "\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    if (function_exists('flush')) {
      flush();
    }
  }

}
