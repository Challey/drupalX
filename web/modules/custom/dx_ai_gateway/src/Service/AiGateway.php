<?php

declare(strict_types=1);

namespace Drupal\dx_ai_gateway\Service;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\StreamedChatMessageIteratorInterface;
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
    protected ?AiProviderPluginManager $aiProviderManager,
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
    $providers = $this->getAttemptOrder($provider);
    $inputTokens = $this->estimateTokens($this->buildMessages($message, $history));
    $reservation = $this->usageTracker->reserve(
      $inputTokens * max(1, count($providers)),
    );
    if ($reservation === NULL) {
      throw new \RuntimeException('Monthly AI quota exceeded.');
    }
    $providers = array_slice($providers, 0, max(1, min(count($providers), $reservation['max_output'])));
    $attemptOutputTokens = intdiv($reservation['max_output'], max(1, count($providers)));
    $consumedTokens = 0;
    $finalized = FALSE;
    try {
      $lastException = NULL;

      foreach ($providers as $providerId) {
        try {
          $response = $this->dispatchChat($providerId, $message, $history, $attemptOutputTokens);
          $tokens = (int) ($response['tokens'] ?? 0);
          if ($tokens <= 0) {
            $tokens = $inputTokens + min(
              $this->estimateTextTokens((string) $response['content']),
              $attemptOutputTokens,
            );
          }
          $tokens = min($tokens, $inputTokens + $attemptOutputTokens);
          $tokens += $consumedTokens;
          $model = (string) ($response['model'] ?? $this->getModelForAttempt($providerId));
          $this->usageTracker->complete(
            $reservation['id'],
            $reservation['period'],
            (string) ($response['provider'] ?? $providerId),
            $model,
            $tokens,
            'ok',
            $message,
          );
          $finalized = TRUE;
          $response['tokens'] = $tokens;
          $response['model'] = $model;
          return $response;
        }
        catch (\Throwable $exception) {
          $lastException = $exception;
          $consumedTokens += $inputTokens + $attemptOutputTokens;
          $this->logger->warning('AI provider @provider failed: @message', [
            '@provider' => $providerId,
            '@message' => $exception->getMessage(),
          ]);
        }
      }

      if ($consumedTokens > 0) {
        $lastProviderId = (string) (end($providers) ?: 'unknown');
        $this->usageTracker->complete(
          $reservation['id'],
          $reservation['period'],
          $this->getProviderForAttempt($lastProviderId),
          $this->getModelForAttempt($lastProviderId),
          $consumedTokens,
          'failed',
          $message,
        );
        $finalized = TRUE;
      }
      throw new \RuntimeException('All AI providers failed: ' . ($lastException?->getMessage() ?: 'unknown'), 0, $lastException);
    }
    finally {
      if (!$finalized) {
        $this->usageTracker->cancel($reservation['id'], $reservation['period']);
      }
    }
  }

  /**
   * Tests a single provider with a short ping prompt.
   *
   * @return array{provider: string, content: string, tokens: int, model: string}
   */
  public function testProvider(string $providerId): array {
    if ($providerId === 'drupal_ai') {
      return $this->chatViaAiProviderManager('Reply with exactly: ok', [], 128);
    }
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
    $providers = $this->getAttemptOrder($provider);
    $inputTokens = $this->estimateTokens($this->buildMessages($message, $history));
    $reservation = $this->usageTracker->reserve(
      $inputTokens * max(1, count($providers)),
    );
    if ($reservation === NULL) {
      throw new \RuntimeException('Monthly AI quota exceeded.');
    }
    $providers = array_slice($providers, 0, max(1, min(count($providers), $reservation['max_output'])));
    $attemptOutputTokens = intdiv($reservation['max_output'], max(1, count($providers)));
    $consumedTokens = 0;
    $finalized = FALSE;
    try {
      $lastException = NULL;
      foreach ($providers as $providerId) {
        $emitted = FALSE;
        $partialContent = '';
        try {
          $response = $this->streamAttempt(
            $providerId,
            $message,
            $history,
            $attemptOutputTokens,
            static function (string $delta) use ($onDelta, &$emitted, &$partialContent): void {
              $emitted = TRUE;
              $partialContent .= $delta;
              $onDelta($delta);
            },
          );
          $tokens = (int) ($response['tokens'] ?? 0);
          if ($tokens <= 0) {
            $tokens = $inputTokens + min(
              $this->estimateTextTokens((string) $response['content']),
              $attemptOutputTokens,
            );
          }
          $tokens = min($tokens, $inputTokens + $attemptOutputTokens);
          $tokens += $consumedTokens;
          $model = (string) ($response['model'] ?? $this->getModelForAttempt($providerId));
          $this->usageTracker->complete(
            $reservation['id'],
            $reservation['period'],
            (string) ($response['provider'] ?? $providerId),
            $model,
            $tokens,
            'ok',
            $message,
          );
          $finalized = TRUE;
          $response['tokens'] = $tokens;
          $response['model'] = $model;
          return $response;
        }
        catch (\Throwable $exception) {
          $lastException = $exception;
          if ($emitted) {
            $actualTokens = $consumedTokens + $inputTokens + min(
              $this->estimateTextTokens($partialContent),
              $attemptOutputTokens,
            );
            $this->usageTracker->complete(
              $reservation['id'],
              $reservation['period'],
              $this->getProviderForAttempt($providerId),
              $this->getModelForAttempt($providerId),
              $actualTokens,
              'partial',
              $message,
            );
            $finalized = TRUE;
          }
          else {
            $consumedTokens += $inputTokens + $attemptOutputTokens;
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

      if ($consumedTokens > 0) {
        $lastProviderId = (string) (end($providers) ?: 'unknown');
        $this->usageTracker->complete(
          $reservation['id'],
          $reservation['period'],
          $this->getProviderForAttempt($lastProviderId),
          $this->getModelForAttempt($lastProviderId),
          $consumedTokens,
          'failed',
          $message,
        );
        $finalized = TRUE;
      }
      throw new \RuntimeException('All AI providers failed: ' . ($lastException?->getMessage() ?: 'unknown'), 0, $lastException);
    }
    finally {
      if (!$finalized) {
        $this->usageTracker->cancel($reservation['id'], $reservation['period']);
      }
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
   * Builds the full attempt order, preferring Drupal AI when enabled.
   */
  protected function getAttemptOrder(?string $preferred): array {
    $order = $this->getProviderOrder($preferred);
    if ($this->hasConfiguredAiProvider()) {
      array_unshift($order, '__drupal_ai__');
    }
    // Four 60-second attempts remain safely below quota reservation expiry.
    return array_slice(array_values(array_unique($order)), 0, 4);
  }

  /**
   * Checks that the optional manager and its selected model are available.
   */
  protected function hasConfiguredAiProvider(): bool {
    $config = $this->configFactory->get('dx_ai_gateway.settings');
    if ($this->aiProviderManager === NULL || !$config->get('use_ai_provider')) {
      return FALSE;
    }
    $selection = $config->get('ai_provider') ?: [];
    if (!empty($selection['use_default'])) {
      $default = $this->aiProviderManager->getDefaultProviderForOperationType('chat') ?: [];
      return !empty($default['provider_id']) && !empty($default['model_id']);
    }
    return !empty($selection['provider']) && !empty($selection['model']);
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
    $available = array_values(array_filter($order, static fn($id) => in_array($id, $known, TRUE)));
    return $available;
  }

  /**
   * Dispatches a chat request to a single provider.
   */
  protected function dispatchChat(string $providerId, string $message, array $history = [], int $maxOutputTokens = 2048): array {
    if ($providerId === '__drupal_ai__') {
      return $this->chatViaAiProviderManager($message, $history, $maxOutputTokens);
    }
    return $this->chatViaHttp($providerId, $message, $history, $maxOutputTokens);
  }

  /**
   * Dispatches a streaming attempt through Drupal AI or direct HTTP.
   */
  protected function streamAttempt(
    string $providerId,
    string $message,
    array $history,
    int $maxOutputTokens,
    callable $onDelta,
  ): array {
    if ($providerId === '__drupal_ai__') {
      return $this->streamViaAiProviderManager($message, $history, $maxOutputTokens, $onDelta);
    }
    return $this->streamViaHttp($providerId, $message, $history, $maxOutputTokens, $onDelta);
  }

  /**
   * Performs a normalized chat call through Drupal AI 1.4.
   */
  protected function chatViaAiProviderManager(string $message, array $history, int $maxOutputTokens): array {
    $selection = $this->getAiProviderSelection();
    $provider = $this->getAiProviderManager()->createInstance($selection['provider']);
    $provider->setConfiguration($this->getAiProviderConfiguration(
      $provider,
      $selection['model'],
      $selection['config'],
      $maxOutputTokens,
    ));
    $output = $provider->chat(
      $this->buildAiChatInput($message, $history),
      $selection['model'],
      ['dx_ai_gateway'],
    );
    $normalized = $output->getNormalized();
    if (!$normalized instanceof ChatMessage) {
      throw new \RuntimeException('Drupal AI provider returned an unexpected chat response.');
    }

    return [
      'provider' => 'ai:' . $selection['provider'],
      'content' => $normalized->getText(),
      'tokens' => (int) ($output->getTokenUsage()->total ?? 0),
      'model' => $selection['model'],
    ];
  }

  /**
   * Streams a normalized chat call through Drupal AI 1.4.
   */
  protected function streamViaAiProviderManager(
    string $message,
    array $history,
    int $maxOutputTokens,
    callable $onDelta,
  ): array {
    $selection = $this->getAiProviderSelection();
    $provider = $this->getAiProviderManager()->createInstance($selection['provider']);
    $provider->setConfiguration($this->getAiProviderConfiguration(
      $provider,
      $selection['model'],
      $selection['config'],
      $maxOutputTokens,
    ));
    $input = $this->buildAiChatInput($message, $history);
    $input->setStreamedOutput(TRUE);
    $output = $provider->chat($input, $selection['model'], ['dx_ai_gateway']);
    $stream = $output->getNormalized();
    if (!$stream instanceof StreamedChatMessageIteratorInterface) {
      throw new \RuntimeException('Drupal AI provider does not support streamed chat output.');
    }

    $content = '';
    $tokens = 0;
    foreach ($stream as $chunk) {
      $delta = $chunk->getText();
      if ($delta !== '') {
        $content .= $delta;
        $onDelta($delta);
      }
      $tokens = max($tokens, (int) ($chunk->getTotalTokenUsage() ?? 0));
    }
    if ($content === '') {
      throw new \RuntimeException('Drupal AI provider returned an empty streaming response.');
    }
    if ($tokens === 0) {
      $tokens = (int) ($stream->reconstructChatOutput()->getTokenUsage()->total ?? 0);
    }

    return [
      'provider' => 'ai:' . $selection['provider'],
      'content' => $content,
      'tokens' => $tokens,
      'model' => $selection['model'],
    ];
  }

  /**
   * Resolves the configured or site-wide default Drupal AI provider.
   *
   * @return array{provider: string, model: string, config: array}
   */
  protected function getAiProviderSelection(): array {
    $manager = $this->getAiProviderManager();
    $selection = $this->configFactory
      ->get('dx_ai_gateway.settings')
      ->get('ai_provider') ?: [];
    if (!empty($selection['use_default'])) {
      $default = $manager->getDefaultProviderForOperationType('chat') ?: [];
      $selection['provider'] = $default['provider_id'] ?? '';
      $selection['model'] = $default['model_id'] ?? '';
    }
    if (empty($selection['provider']) || empty($selection['model'])) {
      throw new \RuntimeException('No Drupal AI chat provider and model are configured.');
    }
    return [
      'provider' => (string) $selection['provider'],
      'model' => (string) $selection['model'],
      'config' => is_array($selection['config'] ?? NULL) ? $selection['config'] : [],
    ];
  }

  /**
   * Returns the optional Drupal AI manager or a migration-safe error.
   */
  protected function getAiProviderManager(): AiProviderPluginManager {
    if ($this->aiProviderManager === NULL) {
      throw new \RuntimeException('The Drupal AI module is not enabled.');
    }
    return $this->aiProviderManager;
  }

  /**
   * Applies the quota output cap to provider-specific configuration.
   */
  protected function getAiProviderConfiguration(
    object $provider,
    string $model,
    array $configuration,
    int $maxOutputTokens,
  ): array {
    $available = $provider->getAvailableConfiguration('chat', $model);
    $maxKey = isset($available['max_completion_tokens'])
      ? 'max_completion_tokens'
      : (isset($available['max_tokens']) ? 'max_tokens' : NULL);
    if ($maxKey === NULL) {
      throw new \RuntimeException('Drupal AI provider does not expose an output token limit.');
    }
    $configuredMax = isset($configuration[$maxKey])
      ? (int) $configuration[$maxKey]
      : $maxOutputTokens;
    $configuration[$maxKey] = min(max(1, $configuredMax), $maxOutputTokens);
    if (isset($available['temperature'])) {
      $configuration['temperature'] ??= 0.7;
    }
    return $configuration;
  }

  /**
   * Builds a normalized Drupal AI chat input.
   */
  protected function buildAiChatInput(string $message, array $history): ChatInput {
    $messages = [];
    foreach ($history as $item) {
      if (is_array($item) && isset($item['role'], $item['content'])) {
        $messages[] = new ChatMessage((string) $item['role'], (string) $item['content']);
      }
    }
    $messages[] = new ChatMessage('user', $message);
    $input = new ChatInput($messages);
    $system = trim((string) ($this->configFactory->get('dx_ai_gateway.settings')->get('system_prompt') ?: ''));
    if ($system !== '') {
      $input->setSystemPrompt($system);
    }
    return $input;
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
   * Returns a stable usage-log provider ID for an attempt.
   */
  protected function getProviderForAttempt(string $providerId): string {
    if ($providerId !== '__drupal_ai__') {
      return $providerId;
    }
    try {
      return 'ai:' . $this->getAiProviderSelection()['provider'];
    }
    catch (\Throwable) {
      return 'drupal_ai';
    }
  }

  /**
   * Returns a stable usage-log model ID for an attempt.
   */
  protected function getModelForAttempt(string $providerId): string {
    if ($providerId !== '__drupal_ai__') {
      return $this->getModelForProvider($providerId);
    }
    try {
      return $this->getAiProviderSelection()['model'];
    }
    catch (\Throwable) {
      return 'default';
    }
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
