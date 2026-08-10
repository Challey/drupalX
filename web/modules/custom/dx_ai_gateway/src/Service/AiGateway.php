<?php

declare(strict_types=1);

namespace Drupal\dx_ai_gateway\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Multi-provider AI gateway with failover and usage tracking.
 */
class AiGateway {

  /**
   * Constructs an AiGateway.
   *
   * @param object|null $aiProvider
   *   Optional Drupal AI provider manager service.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected StateInterface $state,
    protected ClientInterface $httpClient,
    protected LoggerChannelInterface $logger,
    protected UsageTracker $usageTracker,
    protected ?object $aiProvider = NULL,
  ) {}

  /**
   * Returns configured provider definitions.
   */
  public function getProviders(): array {
    return $this->configFactory->get('dx_ai_gateway.settings')->get('providers') ?: [];
  }

  /**
   * Returns the default provider machine name.
   */
  public function getDefaultProvider(): string {
    return (string) ($this->configFactory->get('dx_ai_gateway.settings')->get('default_provider') ?: 'deepseek');
  }

  /**
   * Checks whether the monthly quota has been exceeded.
   */
  public function checkQuota(int $tokens = 0): bool {
    return $this->usageTracker->canConsume($tokens);
  }

  /**
   * Usage summary for UI.
   */
  public function usageSummary(): array {
    return $this->usageTracker->summary();
  }

  /**
   * Sends a chat request using the configured provider chain.
   *
   * @return array{provider: string, content: string, tokens: int, model: string}
   */
  public function chat(string $message, ?string $provider = NULL): array {
    if (!$this->checkQuota()) {
      throw new \RuntimeException('Monthly AI quota exceeded.');
    }

    $providers = $this->getProviderOrder($provider);
    $lastException = NULL;

    foreach ($providers as $providerId) {
      try {
        $response = $this->dispatchChat($providerId, $message);
        $tokens = (int) ($response['tokens'] ?? max(1, (int) ceil(strlen($message) / 4)));
        $model = (string) ($response['model'] ?? $this->getModelForProvider($providerId));
        $this->usageTracker->record($providerId, $model, $tokens, 'ok', $message);
        $response['tokens'] = $tokens;
        $response['model'] = $model;
        return $response;
      }
      catch (\Throwable $exception) {
        $lastException = $exception;
        $this->usageTracker->record($providerId, $this->getModelForProvider($providerId), 0, 'error', $message);
        $this->logger->warning('AI provider @provider failed: @message', [
          '@provider' => $providerId,
          '@message' => $exception->getMessage(),
        ]);
      }
    }

    throw new \RuntimeException('All AI providers failed: ' . ($lastException?->getMessage() ?: 'unknown'), 0, $lastException);
  }

  /**
   * Tests a single provider with a short ping prompt.
   *
   * @return array{provider: string, content: string, tokens: int, model: string}
   */
  public function testProvider(string $providerId): array {
    return $this->dispatchChat($providerId, 'Reply with exactly: ok');
  }

  /**
   * Stores an API key for a provider (State; not config export).
   */
  public function setApiKey(string $providerId, string $apiKey): void {
    $this->state->set('dx_ai_gateway.api_keys.' . $providerId, $apiKey);
  }

  /**
   * Whether a provider has an API key configured.
   */
  public function hasApiKey(string $providerId): bool {
    return (bool) $this->state->get('dx_ai_gateway.api_keys.' . $providerId);
  }

  /**
   * Loads API keys from environment variables DX_AI_{PROVIDER}_KEY.
   *
   * @return string[]
   *   Provider IDs that received a key.
   */
  public function loadKeysFromEnv(): array {
    $loaded = [];
    foreach (array_keys($this->getProviders()) as $id) {
      $env = 'DX_AI_' . strtoupper($id) . '_KEY';
      $value = getenv($env) ?: ($_ENV[$env] ?? '');
      if (is_string($value) && $value !== '') {
        $this->setApiKey($id, $value);
        $loaded[] = $id;
      }
    }
    return $loaded;
  }

  /**
   * Builds the provider attempt order.
   */
  protected function getProviderOrder(?string $preferred): array {
    $config = $this->configFactory->get('dx_ai_gateway.settings');
    $order = $config->get('failover_order') ?: array_keys($this->getProviders());
    if ($preferred) {
      $order = array_values(array_unique(array_merge([$preferred], $order)));
    }
    elseif ($config->get('default_provider')) {
      $default = $config->get('default_provider');
      $order = array_values(array_unique(array_merge([$default], $order)));
    }
    // Only keep providers that still exist in config.
    $known = array_keys($this->getProviders());
    return array_values(array_filter($order, static fn($id) => in_array($id, $known, TRUE)));
  }

  /**
   * Dispatches a chat request to a single provider.
   */
  protected function dispatchChat(string $providerId, string $message): array {
    if ($this->aiProvider && method_exists($this->aiProvider, 'createInstance')) {
      try {
        return $this->chatViaAiModule($providerId, $message);
      }
      catch (\Throwable $exception) {
        $this->logger->notice('AI module path failed for @p, falling back to HTTP: @m', [
          '@p' => $providerId,
          '@m' => $exception->getMessage(),
        ]);
      }
    }

    return $this->chatViaHttp($providerId, $message);
  }

  /**
   * Uses the Drupal AI module provider manager when available.
   */
  protected function chatViaAiModule(string $providerId, string $message): array {
    $instance = $this->aiProvider->createInstance($providerId);
    if (!method_exists($instance, 'chat')) {
      throw new \RuntimeException('AI provider does not support chat().');
    }
    $messages = $this->buildMessages($message);
    $result = $instance->chat($messages);
    return [
      'provider' => $providerId,
      'content' => is_array($result) ? (string) ($result['content'] ?? json_encode($result)) : (string) $result,
      'tokens' => is_array($result) ? (int) ($result['tokens'] ?? 0) : 0,
      'model' => $this->getModelForProvider($providerId),
    ];
  }

  /**
   * Performs an OpenAI-compatible HTTP chat completion request.
   */
  protected function chatViaHttp(string $providerId, string $message): array {
    $providers = $this->getProviders();
    if (empty($providers[$providerId]['base_url'])) {
      throw new \InvalidArgumentException("Unknown provider: {$providerId}");
    }

    $apiKey = $this->state->get('dx_ai_gateway.api_keys.' . $providerId);
    if (!$apiKey) {
      throw new \RuntimeException("API key not configured for provider: {$providerId}");
    }

    $model = $this->getModelForProvider($providerId);
    $baseUrl = rtrim((string) $providers[$providerId]['base_url'], '/');
    $response = $this->httpClient->request('POST', $baseUrl . '/chat/completions', [
      'headers' => [
        'Authorization' => 'Bearer ' . $apiKey,
        'Content-Type' => 'application/json',
      ],
      'json' => [
        'model' => $model,
        'messages' => $this->buildMessages($message),
        'temperature' => 0.7,
      ],
      'timeout' => 60,
    ]);

    $parsed = $this->parseChatResponse($providerId, $response);
    $parsed['model'] = $model;
    return $parsed;
  }

  /**
   * Builds chat messages including optional system prompt.
   *
   * @return list<array{role: string, content: string}>
   */
  protected function buildMessages(string $message): array {
    $messages = [];
    $system = trim((string) ($this->configFactory->get('dx_ai_gateway.settings')->get('system_prompt') ?: ''));
    if ($system !== '') {
      $messages[] = ['role' => 'system', 'content' => $system];
    }
    $messages[] = ['role' => 'user', 'content' => $message];
    return $messages;
  }

  /**
   * Parses an OpenAI-compatible chat completion response.
   */
  protected function parseChatResponse(string $providerId, ResponseInterface $response): array {
    $body = json_decode((string) $response->getBody(), TRUE);
    if (!is_array($body)) {
      throw new \RuntimeException('Invalid AI response payload.');
    }
    if (!empty($body['error'])) {
      $err = is_array($body['error']) ? ($body['error']['message'] ?? json_encode($body['error'])) : (string) $body['error'];
      throw new \RuntimeException('Provider error: ' . $err);
    }

    $content = $body['choices'][0]['message']['content'] ?? '';
    $tokens = (int) ($body['usage']['total_tokens'] ?? 0);

    return [
      'provider' => $providerId,
      'content' => (string) $content,
      'tokens' => $tokens,
    ];
  }

  /**
   * Returns model name for a provider (config override or default).
   */
  public function getModelForProvider(string $providerId): string {
    $providers = $this->getProviders();
    if (!empty($providers[$providerId]['model'])) {
      return (string) $providers[$providerId]['model'];
    }
    return match ($providerId) {
      'deepseek' => 'deepseek-chat',
      'qwen' => 'qwen-plus',
      'zhipu' => 'glm-4',
      default => 'gpt-4o-mini',
    };
  }

}
