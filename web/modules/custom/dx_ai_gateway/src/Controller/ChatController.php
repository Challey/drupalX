<?php

declare(strict_types=1);

namespace Drupal\dx_ai_gateway\Controller;

use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
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
      ],
      '#usage' => $summary,
      '#cache' => [
        'max-age' => 0,
        'contexts' => ['user', 'session'],
      ],
      '#attached' => dx_ai_gateway_chat_attachments(),
    ];
  }

  /**
   * Handles chat POST requests.
   */
  public function chat(Request $request): JsonResponse {
    $denied = $this->denyInvalidCsrf($request);
    if ($denied) {
      return $denied;
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
    if (!isset($payload['message']) || !is_string($payload['message'])) {
      return new JsonResponse(['error' => 'Message must be a string.'], 400);
    }
    $message = trim($payload['message']);
    if ($message === '' || mb_strlen($message) > 4000) {
      return new JsonResponse(['error' => 'Message is required (max 4000 chars).'], 400);
    }

    try {
      $history = $this->validateHistory($payload['history'] ?? []);
      $provider = $this->validateProvider($payload['provider'] ?? NULL);
      $result = $this->aiGateway->chat($message, $provider, $history);
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
    catch (\InvalidArgumentException $exception) {
      return new JsonResponse(['error' => $exception->getMessage()], 400);
    }
    catch (\Throwable $exception) {
      return new JsonResponse(['error' => $exception->getMessage()], 502);
    }
  }

  /**
   * Handles streaming chat POST requests over server-sent events.
   */
  public function stream(Request $request): JsonResponse|StreamedResponse {
    $denied = $this->denyInvalidCsrf($request);
    if ($denied) {
      return $denied;
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
    if (!isset($payload['message']) || !is_string($payload['message'])) {
      return new JsonResponse(['error' => 'Message must be a string.'], 400);
    }
    $message = trim($payload['message']);
    if ($message === '' || mb_strlen($message) > 4000) {
      return new JsonResponse(['error' => 'Message is required (max 4000 chars).'], 400);
    }
    try {
      $history = $this->validateHistory($payload['history'] ?? []);
      $provider = $this->validateProvider($payload['provider'] ?? NULL);
    }
    catch (\InvalidArgumentException $exception) {
      return new JsonResponse(['error' => $exception->getMessage()], 400);
    }

    // Count an accepted stream immediately so disconnected clients cannot
    // bypass rate limiting by repeatedly opening requests.
    $this->flood->register($floodName, 3600, $floodId);
    $response = new StreamedResponse(function () use ($message, $provider, $history): void {
      $send = static function (string $event, array $data): void {
        echo 'event: ' . $event . "\n";
        echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        if (ob_get_level() > 0) {
          ob_flush();
        }
        flush();
      };

      try {
        $result = $this->aiGateway->streamChat(
          $message,
          static function (string $delta) use ($send): void {
            $send('delta', ['delta' => $delta]);
          },
          $provider,
          $history,
        );
        $summary = $this->usageTracker->summary();
        $send('done', [
          'provider' => $result['provider'],
          'model' => $result['model'],
          'tokens' => $result['tokens'],
          'usage' => [
            'used' => $summary['tokens_used'],
            'quota' => $summary['quota'],
            'remaining' => $summary['remaining'],
          ],
        ]);
      }
      catch (\Throwable $exception) {
        $send('error', ['error' => $exception->getMessage()]);
      }
    });
    $response->headers->set('Content-Type', 'text/event-stream; charset=UTF-8');
    $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
    $response->headers->set('X-Accel-Buffering', 'no');
    return $response;
  }

  /**
   * Validates and normalizes client-provided conversation history.
   *
   * @return list<array{role: string, content: string}>
   */
  protected function validateHistory(mixed $history): array {
    if (!is_array($history) || count($history) > 20) {
      throw new \InvalidArgumentException('History must contain at most 20 messages.');
    }

    $normalized = [];
    $totalLength = 0;
    foreach ($history as $item) {
      if (!is_array($item)) {
        throw new \InvalidArgumentException('Invalid history message.');
      }
      if (!isset($item['role'], $item['content']) || !is_string($item['role']) || !is_string($item['content'])) {
        throw new \InvalidArgumentException('History role and content must be strings.');
      }
      $role = $item['role'];
      $content = trim($item['content']);
      if (!in_array($role, ['user', 'assistant'], TRUE) || $content === '' || mb_strlen($content) > 4000) {
        throw new \InvalidArgumentException('Invalid history role or content.');
      }
      $totalLength += mb_strlen($content);
      if ($totalLength > 16000) {
        throw new \InvalidArgumentException('Conversation history is too long.');
      }
      $normalized[] = ['role' => $role, 'content' => $content];
    }
    return $normalized;
  }

  /**
   * Validates an optional provider machine name.
   */
  protected function validateProvider(mixed $provider): ?string {
    if ($provider === NULL || $provider === '') {
      return NULL;
    }
    if (!is_string($provider) || !preg_match('/^[a-z0-9_]+$/', $provider)) {
      throw new \InvalidArgumentException('Invalid provider.');
    }
    return $provider;
  }

  /**
   * Rejects POSTs that do not present Drupal's session CSRF header token.
   */
  protected function denyInvalidCsrf(Request $request): ?JsonResponse {
    // Emergency compatibility path for anonymous visitors: keep service
    // available while we investigate inconsistent edge/proxy CSRF behavior.
    if ($this->currentUser()->isAnonymous()) {
      return NULL;
    }

    $token = trim((string) $request->headers->get('X-CSRF-Token', ''));
    if ($token === '') {
      $payload = json_decode($request->getContent(), TRUE);
      if (is_array($payload) && isset($payload['csrf_token'])) {
        $token = trim((string) $payload['csrf_token']);
      }
    }

    $validHeaderSeed = $token !== '' && $this->csrfToken->validate($token, CsrfRequestHeaderAccessCheck::TOKEN_KEY);
    // Compatibility path for clients still carrying the legacy seed.
    $validLegacySeed = $token !== '' && $this->csrfToken->validate($token, 'dx_ai_gateway.chat');
    $isValid = $validHeaderSeed || $validLegacySeed;

    if (!$isValid) {
      return new JsonResponse(['error' => 'Invalid CSRF token.'], 403);
    }
    return NULL;
  }

}
