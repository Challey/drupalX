<?php

declare(strict_types=1);

namespace Drupal\dx_ai_gateway\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Multi-provider AI gateway with failover, streaming, and usage tracking.
 */
class AiGateway {

  /**
   * Maps DrupalX provider ids to drupal/ai provider plugin ids when available.
   */
  protected const AI_MODULE_PROVIDER_MAP = [
    'openai' => 'openai',
  ];

  /**
   * Constructs an AiGateway.
   *
   * @param object|null $aiProvider
   *   Optional Drupal AI provider manager service (ai.provider).
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected StateInterface $state,
    protected ClientInterface $httpClient,
    protected LoggerChannelInterface $logger,
    protected UsageTracker $usageTracker,
    protected KnowledgeBase $knowledgeBase,
    protected ModuleHandlerInterface $moduleHandler,
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
   * @param list<array{role: string, content: string}> $history
   *   Prior turns (user/assistant), excluding the current user message.
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
        $response = $this->dispatchChat($providerId, $message, $history, FALSE);
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
   * Streams a chat reply as text deltas (OpenAI-compatible SSE over HTTP).
   *
   * Yields string chunks. After iteration, call getLastStreamMeta() for usage.
   *
   * @param list<array{role: string, content: string}> $history
   *
   * @return \Generator<int, string>
   */
  public function chatStream(string $message, ?string $provider = NULL, array $history = []): \Generator {
    if (!$this->checkQuota()) {
      throw new \RuntimeException('Monthly AI quota exceeded.');
    }

    $providers = $this->getProviderOrder($provider);
    $lastException = NULL;

    foreach ($providers as $providerId) {
      try {
        $content = '';
        $tokens = 0;
        $model = $this->getModelForProvider($providerId);
        foreach ($this->dispatchChatStream($providerId, $message, $history) as $chunk) {
          if (is_array($chunk) && ($chunk['_meta'] ?? FALSE)) {
            $tokens = (int) ($chunk['tokens'] ?? 0);
            $model = (string) ($chunk['model'] ?? $model);
            continue;
          }
          $text = (string) $chunk;
          $content .= $text;
          yield $text;
        }
        if ($tokens <= 0) {
          $tokens = max(1, (int) ceil(mb_strlen($message . $content) / 4));
        }
        $this->usageTracker->record($providerId, $model, $tokens, 'ok', $message);
        $this->lastStreamMeta = [
          'provider' => $providerId,
          'model' => $model,
          'tokens' => $tokens,
          'content' => $content,
        ];
        return;
      }
      catch (\Throwable $exception) {
        $lastException = $exception;
        $this->usageTracker->record($providerId, $this->getModelForProvider($providerId), 0, 'error', $message);
        $this->logger->warning('AI stream provider @provider failed: @message', [
          '@provider' => $providerId,
          '@message' => $exception->getMessage(),
        ]);
      }
    }

    throw new \RuntimeException('All AI providers failed: ' . ($lastException?->getMessage() ?: 'unknown'), 0, $lastException);
  }

  /**
   * Meta from the last successful chatStream() call.
   *
   * @var array{provider?: string, model?: string, tokens?: int, content?: string}
   */
  protected array $lastStreamMeta = [];

  /**
   * Returns metadata from the last successful stream.
   *
   * @return array{provider: string, model: string, tokens: int, content: string}
   */
  public function getLastStreamMeta(): array {
    return [
      'provider' => (string) ($this->lastStreamMeta['provider'] ?? ''),
      'model' => (string) ($this->lastStreamMeta['model'] ?? ''),
      'tokens' => (int) ($this->lastStreamMeta['tokens'] ?? 0),
      'content' => (string) ($this->lastStreamMeta['content'] ?? ''),
    ];
  }

  /**
   * Tests a single provider with a short ping prompt.
   *
   * @return array{provider: string, content: string, tokens: int, model: string}
   */
  public function testProvider(string $providerId): array {
    return $this->dispatchChat($providerId, 'Reply with exactly: ok', [], FALSE);
  }

  /**
   * Stores a platform API key for a provider (State; not config export).
   */
  public function setApiKey(string $providerId, string $apiKey): void {
    $this->state->set('dx_ai_gateway.api_keys.' . $providerId, $apiKey);
  }

  /**
   * Stores a tenant-override API key.
   */
  public function setTenantApiKey(string $providerId, string $apiKey): void {
    $this->state->set('dx_ai_gateway.tenant_api_keys.' . $providerId, $apiKey);
  }

  /**
   * Clears a tenant-override API key.
   */
  public function clearTenantApiKey(string $providerId): void {
    $this->state->delete('dx_ai_gateway.tenant_api_keys.' . $providerId);
  }

  /**
   * Resolves the effective API key (tenant override → platform).
   */
  public function getApiKey(string $providerId): string {
    if ($this->tenantKeysEnabled()) {
      $tenantKey = $this->state->get('dx_ai_gateway.tenant_api_keys.' . $providerId);
      if (is_string($tenantKey) && $tenantKey !== '') {
        return $tenantKey;
      }
    }
    $platform = $this->state->get('dx_ai_gateway.api_keys.' . $providerId);
    return is_string($platform) ? $platform : '';
  }

  /**
   * Whether a provider has an effective API key configured.
   */
  public function hasApiKey(string $providerId): bool {
    return $this->getApiKey($providerId) !== '';
  }

  /**
   * Whether a tenant-specific key is set for a provider.
   */
  public function hasTenantApiKey(string $providerId): bool {
    return (bool) $this->state->get('dx_ai_gateway.tenant_api_keys.' . $providerId);
  }

  /**
   * Key source label for UI.
   */
  public function keySource(string $providerId): string {
    if ($this->tenantKeysEnabled() && $this->hasTenantApiKey($providerId)) {
      return 'tenant';
    }
    if ($this->state->get('dx_ai_gateway.api_keys.' . $providerId)) {
      return 'platform';
    }
    return 'none';
  }

  /**
   * Whether tenant key overrides are active.
   */
  public function tenantKeysEnabled(): bool {
    if (!$this->moduleHandler->moduleExists('dx_tenant')) {
      return FALSE;
    }
    return (bool) $this->configFactory->get('dx_tenant.settings')->get('ai_keys_override');
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
    $known = array_keys($this->getProviders());
    return array_values(array_filter($order, static fn($id) => in_array($id, $known, TRUE)));
  }

  /**
   * Dispatches a non-streaming chat request to a single provider.
   *
   * @param list<array{role: string, content: string}> $history
   */
  protected function dispatchChat(string $providerId, string $message, array $history, bool $stream): array {
    $preferAi = (bool) $this->configFactory->get('dx_ai_gateway.settings')->get('prefer_ai_module');
    if ($preferAi && $this->canUseAiModule($providerId)) {
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

    return $this->chatViaHttp($providerId, $message, $history, FALSE);
  }

  /**
   * Dispatches a streaming chat request.
   *
   * @param list<array{role: string, content: string}> $history
   *
   * @return \Generator<int, string|array>
   */
  protected function dispatchChatStream(string $providerId, string $message, array $history): \Generator {
    $preferAi = (bool) $this->configFactory->get('dx_ai_gateway.settings')->get('prefer_ai_module');
    if ($preferAi && $this->canUseAiModule($providerId)) {
      try {
        yield from $this->chatStreamViaAiModule($providerId, $message, $history);
        return;
      }
      catch (\Throwable $exception) {
        $this->logger->notice('AI module stream failed for @p, falling back to HTTP: @m', [
          '@p' => $providerId,
          '@m' => $exception->getMessage(),
        ]);
      }
    }

    yield from $this->chatStreamViaHttp($providerId, $message, $history);
  }

  /**
   * Whether the Drupal AI module can handle this provider id.
   */
  protected function canUseAiModule(string $providerId): bool {
    if (!$this->aiProvider || !method_exists($this->aiProvider, 'createInstance')) {
      return FALSE;
    }
    $pluginId = self::AI_MODULE_PROVIDER_MAP[$providerId] ?? NULL;
    if (!$pluginId) {
      return FALSE;
    }
    if (method_exists($this->aiProvider, 'hasDefinition')) {
      return (bool) $this->aiProvider->hasDefinition($pluginId);
    }
    return TRUE;
  }

  /**
   * Uses the Drupal AI module provider manager when available.
   *
   * @param list<array{role: string, content: string}> $history
   */
  protected function chatViaAiModule(string $providerId, string $message, array $history): array {
    $pluginId = self::AI_MODULE_PROVIDER_MAP[$providerId] ?? $providerId;
    $instance = $this->aiProvider->createInstance($pluginId);
    $model = $this->getModelForProvider($providerId);

    if (class_exists('\Drupal\ai\OperationType\Chat\ChatInput')
      && class_exists('\Drupal\ai\OperationType\Chat\ChatMessage')) {
      $chatMessages = [];
      foreach ($this->buildMessages($message, $history) as $row) {
        if ($row['role'] === 'system') {
          continue;
        }
        $chatMessages[] = new \Drupal\ai\OperationType\Chat\ChatMessage($row['role'], $row['content']);
      }
      $input = new \Drupal\ai\OperationType\Chat\ChatInput($chatMessages);
      $system = $this->buildSystemPrompt();
      if ($system !== '' && method_exists($input, 'setSystemPrompt')) {
        $input->setSystemPrompt($system);
      }
      $apiKey = $this->getApiKey($providerId);
      if ($apiKey !== '' && method_exists($instance, 'setAuthentication')) {
        $instance->setAuthentication($apiKey);
      }
      $output = $instance->chat($input, $model, ['dx_ai_gateway']);
      $normalized = method_exists($output, 'getNormalized') ? $output->getNormalized() : $output;
      $text = '';
      if (is_object($normalized) && method_exists($normalized, 'getText')) {
        $text = (string) $normalized->getText();
      }
      elseif (is_string($normalized)) {
        $text = $normalized;
      }
      else {
        $text = (string) json_encode($normalized);
      }
      $tokens = 0;
      if (is_object($output) && method_exists($output, 'getTokenUsage')) {
        $usage = $output->getTokenUsage();
        if (is_object($usage) && method_exists($usage, 'getTotal')) {
          $tokens = (int) $usage->getTotal();
        }
        elseif (is_array($usage)) {
          $tokens = (int) ($usage['total'] ?? $usage['total_tokens'] ?? 0);
        }
      }
      return [
        'provider' => $providerId,
        'content' => $text,
        'tokens' => $tokens,
        'model' => $model,
      ];
    }

    // Legacy fallback if DTOs are unavailable.
    if (!method_exists($instance, 'chat')) {
      throw new \RuntimeException('AI provider does not support chat().');
    }
    $result = $instance->chat($this->buildMessages($message, $history));
    return [
      'provider' => $providerId,
      'content' => is_array($result) ? (string) ($result['content'] ?? json_encode($result)) : (string) $result,
      'tokens' => is_array($result) ? (int) ($result['tokens'] ?? 0) : 0,
      'model' => $model,
    ];
  }

  /**
   * Streams via Drupal AI module when the provider supports streamed output.
   *
   * @param list<array{role: string, content: string}> $history
   *
   * @return \Generator<int, string|array>
   */
  protected function chatStreamViaAiModule(string $providerId, string $message, array $history): \Generator {
    $pluginId = self::AI_MODULE_PROVIDER_MAP[$providerId] ?? $providerId;
    $instance = $this->aiProvider->createInstance($pluginId);
    $model = $this->getModelForProvider($providerId);

    if (!class_exists('\Drupal\ai\OperationType\Chat\ChatInput')
      || !class_exists('\Drupal\ai\OperationType\Chat\ChatMessage')) {
      // Fall back to non-stream then emit as one chunk.
      $result = $this->chatViaAiModule($providerId, $message, $history);
      yield $result['content'];
      yield ['_meta' => TRUE, 'tokens' => $result['tokens'], 'model' => $model];
      return;
    }

    $chatMessages = [];
    foreach ($this->buildMessages($message, $history) as $row) {
      if ($row['role'] === 'system') {
        continue;
      }
      $chatMessages[] = new \Drupal\ai\OperationType\Chat\ChatMessage($row['role'], $row['content']);
    }
    $input = new \Drupal\ai\OperationType\Chat\ChatInput($chatMessages);
    $system = $this->buildSystemPrompt();
    if ($system !== '' && method_exists($input, 'setSystemPrompt')) {
      $input->setSystemPrompt($system);
    }
    if (method_exists($input, 'setStreamedOutput')) {
      $input->setStreamedOutput(TRUE);
    }
    $apiKey = $this->getApiKey($providerId);
    if ($apiKey !== '' && method_exists($instance, 'setAuthentication')) {
      $instance->setAuthentication($apiKey);
    }

    $output = $instance->chat($input, $model, ['dx_ai_gateway', 'stream']);
    $normalized = method_exists($output, 'getNormalized') ? $output->getNormalized() : $output;

    if (is_iterable($normalized) && !is_string($normalized)) {
      foreach ($normalized as $piece) {
        if (is_object($piece) && method_exists($piece, 'getText')) {
          $text = (string) $piece->getText();
        }
        else {
          $text = (string) $piece;
        }
        if ($text !== '') {
          yield $text;
        }
      }
    }
    elseif (is_object($normalized) && method_exists($normalized, 'getText')) {
      yield (string) $normalized->getText();
    }
    else {
      yield (string) $normalized;
    }

    $tokens = 0;
    if (is_object($output) && method_exists($output, 'getTokenUsage')) {
      $usage = $output->getTokenUsage();
      if (is_object($usage) && method_exists($usage, 'getTotal')) {
        $tokens = (int) $usage->getTotal();
      }
    }
    yield ['_meta' => TRUE, 'tokens' => $tokens, 'model' => $model];
  }

  /**
   * Performs an OpenAI-compatible HTTP chat completion request.
   *
   * @param list<array{role: string, content: string}> $history
   */
  protected function chatViaHttp(string $providerId, string $message, array $history, bool $stream = FALSE): array {
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
    $payload = [
      'model' => $model,
      'messages' => $this->buildMessages($message, $history),
      'temperature' => 0.7,
    ];
    if ($stream) {
      $payload['stream'] = TRUE;
    }

    $response = $this->httpClient->request('POST', $baseUrl . '/chat/completions', [
      'headers' => [
        'Authorization' => 'Bearer ' . $apiKey,
        'Content-Type' => 'application/json',
      ],
      'json' => $payload,
      'timeout' => 90,
    ]);

    $parsed = $this->parseChatResponse($providerId, $response);
    $parsed['model'] = $model;
    return $parsed;
  }

  /**
   * Streams an OpenAI-compatible chat completion over HTTP.
   *
   * @param list<array{role: string, content: string}> $history
   *
   * @return \Generator<int, string|array>
   */
  protected function chatStreamViaHttp(string $providerId, string $message, array $history): \Generator {
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
        'Accept' => 'text/event-stream',
      ],
      'json' => [
        'model' => $model,
        'messages' => $this->buildMessages($message, $history),
        'temperature' => 0.7,
        'stream' => TRUE,
      ],
      'stream' => TRUE,
      'timeout' => 120,
    ]);

    $tokens = 0;
    foreach ($this->iterateSseDeltas($response->getBody()) as $item) {
      if (is_array($item) && ($item['_meta'] ?? FALSE)) {
        $tokens = (int) ($item['tokens'] ?? 0);
        continue;
      }
      yield $item;
    }
    yield ['_meta' => TRUE, 'tokens' => $tokens, 'model' => $model];
  }

  /**
   * Parses OpenAI-compatible SSE body into text deltas.
   *
   * @return \Generator<int, string|array>
   */
  protected function iterateSseDeltas(StreamInterface $body): \Generator {
    $buffer = '';
    $tokens = 0;
    while (!$body->eof()) {
      $buffer .= $body->read(1024);
      while (($pos = strpos($buffer, "\n")) !== FALSE) {
        $line = trim(substr($buffer, 0, $pos));
        $buffer = substr($buffer, $pos + 1);
        if ($line === '' || !str_starts_with($line, 'data:')) {
          continue;
        }
        $data = trim(substr($line, 5));
        if ($data === '[DONE]') {
          yield ['_meta' => TRUE, 'tokens' => $tokens];
          return;
        }
        $json = json_decode($data, TRUE);
        if (!is_array($json)) {
          continue;
        }
        if (!empty($json['usage']['total_tokens'])) {
          $tokens = (int) $json['usage']['total_tokens'];
        }
        $delta = $json['choices'][0]['delta']['content'] ?? '';
        if (is_string($delta) && $delta !== '') {
          yield $delta;
        }
      }
    }
    yield ['_meta' => TRUE, 'tokens' => $tokens];
  }

  /**
   * Builds the effective system prompt (base + knowledge).
   */
  protected function buildSystemPrompt(): string {
    $system = trim((string) ($this->configFactory->get('dx_ai_gateway.settings')->get('system_prompt') ?: ''));
    $knowledge = trim($this->knowledgeBase->buildContext());
    if ($knowledge !== '') {
      $system = trim($system . "\n\n" . $knowledge);
    }
    return $system;
  }

  /**
   * Builds chat messages including system prompt, history, and current user turn.
   *
   * @param list<array{role: string, content: string}> $history
   *
   * @return list<array{role: string, content: string}>
   */
  protected function buildMessages(string $message, array $history = []): array {
    $messages = [];
    $system = $this->buildSystemPrompt();
    if ($system !== '') {
      $messages[] = ['role' => 'system', 'content' => $system];
    }
    foreach ($history as $row) {
      if (!is_array($row) || empty($row['role']) || !isset($row['content'])) {
        continue;
      }
      if (!in_array($row['role'], ['user', 'assistant'], TRUE)) {
        continue;
      }
      $content = trim((string) $row['content']);
      if ($content === '') {
        continue;
      }
      $messages[] = ['role' => $row['role'], 'content' => $content];
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
