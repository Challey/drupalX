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
  public function chat(string $message, ?string $provider = NULL, array $history = []): array {
    if (!$this->checkQuota()) {
      throw new \RuntimeException('Monthly AI quota exceeded.');
    }

    $providers = $this->getProviderOrder($provider);
    $lastException = NULL;

    foreach ($providers as $providerId) {
      try {
        $response = $this->dispatchChat($providerId, $message, $history);
        $tokens = (int) ($response['tokens'] ?? 0);
        if ($tokens <= 0) {
          $tokens = max(1, (int) ceil((strlen($message) + strlen($response['content'])) / 4));
        }
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
   * Streams a chat response using OpenAI-compatible server-sent chunks.
   *
   * @param callable(string): void $onDelta
   *   Receives each text delta as it arrives.
   *
   * @return array{provider: string, content: string, tokens: int, model: string}
   */
  public function streamChat(string $message, callable $onDelta, ?string $provider = NULL, array $history = []): array {
    if (!$this->checkQuota()) {
      throw new \RuntimeException('Monthly AI quota exceeded.');
    }

    $lastException = NULL;
    foreach ($this->getProviderOrder($provider) as $providerId) {
      $emitted = FALSE;
      try {
        $response = $this->streamViaHttp(
          $providerId,
          $message,
          $history,
          static function (string $delta) use ($onDelta, &$emitted): void {
            $emitted = TRUE;
            $onDelta($delta);
          },
        );
        $tokens = (int) ($response['tokens'] ?? 0);
        if ($tokens <= 0) {
          $tokens = max(1, (int) ceil((strlen($message) + strlen($response['content'])) / 4));
        }
        $model = (string) ($response['model'] ?? $this->getModelForProvider($providerId));
        $this->usageTracker->record($providerId, $model, $tokens, 'ok', $message);
        $response['tokens'] = $tokens;
        $response['model'] = $model;
        return $response;
      }
      catch (\Throwable $exception) {
        $lastException = $exception;
        $this->usageTracker->record($providerId, $this->getModelForProvider($providerId), 0, 'error', $message);
        $this->logger->warning('Streaming AI provider @provider failed: @message', [
          '@provider' => $providerId,
          '@message' => $exception->getMessage(),
        ]);
        // Once text was emitted, switching providers would concatenate two
        // unrelated answers in the client.
        if ($emitted) {
          throw $exception;
        }
      }
    }

    throw new \RuntimeException('All AI providers failed: ' . ($lastException?->getMessage() ?: 'unknown'), 0, $lastException);
  }

  /**
   * Stores an API key for a provider (State; not config export).
   */
  public function setApiKey(string $providerId, string $apiKey): void {
    $this->state->set('dx_ai_gateway.api_keys.' . $providerId, $apiKey);
  }

  /**
   * Deletes the site-specific API key override for a provider.
   */
  public function clearApiKey(string $providerId): void {
    $this->state->delete('dx_ai_gateway.api_keys.' . $providerId);
  }

  /**
   * Whether a provider has an effective API key configured.
   */
  public function hasApiKey(string $providerId): bool {
    return $this->getApiKey($providerId) !== '';
  }

  /**
   * Describes where the effective API key comes from.
   */
  public function getApiKeySource(string $providerId): string {
    if ($this->getSiteApiKey($providerId) !== '') {
      return 'site';
    }
    return $this->getEnvironmentApiKey($providerId) !== '' ? 'environment' : 'none';
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
  protected function dispatchChat(string $providerId, string $message, array $history = []): array {
    if ($this->aiProvider && method_exists($this->aiProvider, 'createInstance')) {
      try {
        return $this->chatViaAiModule($providerId, $message, $history);
      }
      catch (\Throwable $exception) {
        $this->logger->notice('AI module path failed for @p, falling back to HTTP: @m', [
          '@p' => $providerId,
          '@m' => $exception->getMessage(),
        ]);
      }
    }

    return $this->chatViaHttp($providerId, $message, $history);
  }

  /**
   * Uses the Drupal AI module provider manager when available.
   */
  protected function chatViaAiModule(string $providerId, string $message, array $history = []): array {
    $instance = $this->aiProvider->createInstance($providerId);
    if (!method_exists($instance, 'chat')) {
      throw new \RuntimeException('AI provider does not support chat().');
    }
    $messages = $this->buildMessages($message, $history);
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
  protected function chatViaHttp(string $providerId, string $message, array $history = []): array {
    $providers = $this->getProviders();
    if (empty($providers[$providerId]['base_url'])) {
      throw new \InvalidArgumentException("Unknown provider: {$providerId}");
    }

    $apiKey = $this->getApiKey($providerId);
    if ($apiKey === '') {
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
        'messages' => $this->buildMessages($message, $history),
        'temperature' => 0.7,
      ],
      'timeout' => 60,
    ]);

    $parsed = $this->parseChatResponse($providerId, $response);
    $parsed['model'] = $model;
    return $parsed;
  }

  /**
   * Performs a streaming OpenAI-compatible chat completion request.
   */
  protected function streamViaHttp(string $providerId, string $message, array $history, callable $onDelta): array {
    $providers = $this->getProviders();
    if (empty($providers[$providerId]['base_url'])) {
      throw new \InvalidArgumentException("Unknown provider: {$providerId}");
    }

    $apiKey = $this->getApiKey($providerId);
    if ($apiKey === '') {
      throw new \RuntimeException("API key not configured for provider: {$providerId}");
    }

    $model = $this->getModelForProvider($providerId);
    $baseUrl = rtrim((string) $providers[$providerId]['base_url'], '/');
    $response = $this->httpClient->request('POST', $baseUrl . '/chat/completions', [
      'headers' => [
        'Authorization' => 'Bearer ' . $apiKey,
        'Accept' => 'text/event-stream',
        'Content-Type' => 'application/json',
      ],
      'json' => [
        'model' => $model,
        'messages' => $this->buildMessages($message, $history),
        'temperature' => 0.7,
        'stream' => TRUE,
      ],
      'stream' => TRUE,
      'timeout' => 60,
    ]);

    $body = $response->getBody();
    $buffer = '';
    $content = '';
    $tokens = 0;
    $done = FALSE;
    $consumeLine = function (string $line) use ($providerId, $onDelta, &$content, &$tokens, &$done): void {
      $line = trim($line);
      if (!str_starts_with($line, 'data:')) {
        return;
      }
      $data = trim(substr($line, 5));
      if ($data === '[DONE]') {
        $done = TRUE;
        return;
      }
      $event = json_decode($data, TRUE);
      if (!is_array($event)) {
        return;
      }
      if (!empty($event['error'])) {
        $error = is_array($event['error'])
          ? ($event['error']['message'] ?? json_encode($event['error']))
          : (string) $event['error'];
        throw new \RuntimeException('Provider error: ' . $error);
      }
      $delta = $event['choices'][0]['delta']['content'] ?? '';
      if (is_string($delta) && $delta !== '') {
        $content .= $delta;
        $onDelta($delta);
      }
      if (isset($event['usage']['total_tokens'])) {
        $tokens = (int) $event['usage']['total_tokens'];
      }
    };

    while (!$body->eof() && !$done) {
      $buffer .= $body->read(8192);
      while (($newline = strpos($buffer, "\n")) !== FALSE) {
        $line = rtrim(substr($buffer, 0, $newline), "\r");
        $buffer = substr($buffer, $newline + 1);
        $consumeLine($line);
        if ($done) {
          break;
        }
      }
    }
    if (!$done && trim($buffer) !== '') {
      $consumeLine($buffer);
    }
    if ($content === '') {
      throw new \RuntimeException("Provider returned an empty streaming response: {$providerId}");
    }

    return [
      'provider' => $providerId,
      'content' => $content,
      'tokens' => $tokens,
      'model' => $model,
    ];
  }

  /**
   * Builds chat messages including optional system prompt.
   *
   * @return list<array{role: string, content: string}>
   */
  protected function buildMessages(string $message, array $history = []): array {
    $messages = [];
    $system = trim((string) ($this->configFactory->get('dx_ai_gateway.settings')->get('system_prompt') ?: ''));
    if ($system !== '') {
      $messages[] = ['role' => 'system', 'content' => $system];
    }
    foreach ($history as $item) {
      if (is_array($item) && isset($item['role'], $item['content'])) {
        $messages[] = [
          'role' => (string) $item['role'],
          'content' => (string) $item['content'],
        ];
      }
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

  /**
   * Returns a site override, falling back to the shared environment key.
   */
  protected function getApiKey(string $providerId): string {
    $siteKey = $this->getSiteApiKey($providerId);
    return $siteKey !== '' ? $siteKey : $this->getEnvironmentApiKey($providerId);
  }

  /**
   * Returns the API key stored in this site's isolated State storage.
   */
  protected function getSiteApiKey(string $providerId): string {
    return trim((string) $this->state->get('dx_ai_gateway.api_keys.' . $providerId, ''));
  }

  /**
   * Returns the platform-wide API key supplied through the environment.
   */
  protected function getEnvironmentApiKey(string $providerId): string {
    $name = 'DX_AI_' . strtoupper($providerId) . '_KEY';
    $value = getenv($name);
    if ($value === FALSE) {
      $value = $_ENV[$name] ?? '';
    }
    return is_string($value) ? trim($value) : '';
  }

}
