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

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected StateInterface $state,
    protected ClientInterface $httpClient,
    protected LoggerChannelInterface $logger,
    protected UsageTracker $usageTracker,
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
    return $this->runChat($message, $provider, $history);
  }

  /**
   * Executes a non-streaming request with an atomic quota reservation.
   */
  protected function runChat(string $message, ?string $provider, array $history): array {
    $reservation = $this->usageTracker->reserve(
      $this->estimateTokens($this->buildMessages($message, $history)),
    );
    if ($reservation === NULL) {
      throw new \RuntimeException('Monthly AI quota exceeded.');
    }
    try {
      $providers = $this->getProviderOrder($provider);
      $lastException = NULL;

      foreach ($providers as $providerId) {
        try {
          $response = $this->dispatchChat($providerId, $message, $history, $reservation['max_output']);
          $tokens = (int) ($response['tokens'] ?? 0);
          if ($tokens <= 0) {
            $tokens = $this->estimateTokens($this->buildMessages($message, $history))
              + $this->estimateTextTokens((string) $response['content']);
          }
          $model = (string) ($response['model'] ?? $this->getModelForProvider($providerId));
          $this->usageTracker->record($providerId, $model, $tokens, 'ok', $message, $reservation['period']);
          $response['tokens'] = $tokens;
          $response['model'] = $model;
          return $response;
        }
        catch (\Throwable $exception) {
          $lastException = $exception;
          $this->usageTracker->record($providerId, $this->getModelForProvider($providerId), 0, 'error', $message, $reservation['period']);
          $this->logger->warning('AI provider @provider failed: @message', [
            '@provider' => $providerId,
            '@message' => $exception->getMessage(),
          ]);
        }
      }

      throw new \RuntimeException('All AI providers failed: ' . ($lastException?->getMessage() ?: 'unknown'), 0, $lastException);
    }
    finally {
      $this->usageTracker->settle($reservation['id'], $reservation['period']);
    }
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
    return $this->runStreamingChat($message, $onDelta, $provider, $history);
  }

  /**
   * Executes a streaming request with an atomic quota reservation.
   */
  protected function runStreamingChat(string $message, callable $onDelta, ?string $provider, array $history): array {
    $reservation = $this->usageTracker->reserve(
      $this->estimateTokens($this->buildMessages($message, $history)),
    );
    if ($reservation === NULL) {
      throw new \RuntimeException('Monthly AI quota exceeded.');
    }
    try {
      $lastException = NULL;
      foreach ($this->getProviderOrder($provider) as $providerId) {
        $emitted = FALSE;
        $partialContent = '';
        try {
          $response = $this->streamViaHttp(
            $providerId,
            $message,
            $history,
            $reservation['max_output'],
            static function (string $delta) use ($onDelta, &$emitted, &$partialContent): void {
              $emitted = TRUE;
              $partialContent .= $delta;
              $onDelta($delta);
            },
          );
          $tokens = (int) ($response['tokens'] ?? 0);
          if ($tokens <= 0) {
            $tokens = $this->estimateTokens($this->buildMessages($message, $history))
              + $this->estimateTextTokens((string) $response['content']);
          }
          $model = (string) ($response['model'] ?? $this->getModelForProvider($providerId));
          $this->usageTracker->record($providerId, $model, $tokens, 'ok', $message, $reservation['period']);
          $response['tokens'] = $tokens;
          $response['model'] = $model;
          return $response;
        }
        catch (\Throwable $exception) {
          $lastException = $exception;
          if ($emitted) {
            $actualTokens = $this->estimateTokens($this->buildMessages($message, $history))
              + $this->estimateTextTokens($partialContent);
            $this->usageTracker->record(
              $providerId,
              $this->getModelForProvider($providerId),
              $actualTokens,
              'partial',
              $message,
              $reservation['period'],
            );
          }
          else {
            $this->usageTracker->record(
              $providerId,
              $this->getModelForProvider($providerId),
              0,
              'error',
              $message,
              $reservation['period'],
            );
          }
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
    finally {
      $this->usageTracker->settle($reservation['id'], $reservation['period']);
    }
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
   * Lists providers inheriting DX_AI_{PROVIDER}_KEY from the environment.
   *
   * @return string[]
   *   Provider IDs with a platform environment key.
   */
  public function loadKeysFromEnv(): array {
    $loaded = [];
    foreach (array_keys($this->getProviders()) as $id) {
      $env = 'DX_AI_' . strtoupper($id) . '_KEY';
      $value = getenv($env) ?: ($_ENV[$env] ?? '');
      if (is_string($value) && $value !== '') {
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
  protected function dispatchChat(string $providerId, string $message, array $history = [], int $maxOutputTokens = 2048): array {
    return $this->chatViaHttp($providerId, $message, $history, $maxOutputTokens);
  }

  /**
   * Performs an OpenAI-compatible HTTP chat completion request.
   */
  protected function chatViaHttp(string $providerId, string $message, array $history = [], int $maxOutputTokens = 2048): array {
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
        'max_tokens' => $maxOutputTokens,
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
  protected function streamViaHttp(string $providerId, string $message, array $history, int $maxOutputTokens, callable $onDelta): array {
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
        'max_tokens' => $maxOutputTokens,
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
    $consumeFrame = function (string $frame) use ($providerId, $onDelta, &$content, &$tokens, &$done): void {
      $dataLines = [];
      foreach (preg_split('/\r\n|\r|\n/', $frame) ?: [] as $line) {
        if (str_starts_with($line, 'data:')) {
          $dataLines[] = ltrim(substr($line, 5));
        }
      }
      if ($dataLines === []) {
        return;
      }
      $data = trim(implode("\n", $dataLines));
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
      // Normalize all complete line endings while retaining a trailing CR
      // that may be the first byte of a CRLF split across network chunks.
      $trailingCr = str_ends_with($buffer, "\r");
      if ($trailingCr) {
        $buffer = substr($buffer, 0, -1);
      }
      $buffer = (preg_replace('/\r\n?/', "\n", $buffer) ?? $buffer)
        . ($trailingCr ? "\r" : '');
      while (($offset = strpos($buffer, "\n\n")) !== FALSE) {
        $frame = substr($buffer, 0, $offset);
        $buffer = substr($buffer, $offset + 2);
        $consumeFrame($frame);
        if ($done) {
          break;
        }
      }
    }
    if (!$done && trim($buffer) !== '') {
      $consumeFrame(preg_replace('/\r\n?/', "\n", $buffer) ?? $buffer);
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
   * Estimates a worst-case token reservation for provider messages.
   */
  protected function estimateTokens(array $messages): int {
    $tokens = 0;
    foreach ($messages as $message) {
      $tokens += 16 + $this->estimateTextTokens((string) ($message['content'] ?? ''));
    }
    return max(1, $tokens);
  }

  /**
   * Uses UTF-8 byte length as a conservative tokenizer-independent bound.
   */
  protected function estimateTextTokens(string $text): int {
    return max(1, strlen($text));
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
