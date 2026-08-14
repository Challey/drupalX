<?php

declare(strict_types=1);

namespace Drupal\dx_ai_gateway\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Multi-provider AI gateway with failover, knowledge injection, and usage tracking.
 */
class AiGateway {

  /**
   * Constructs an AiGateway.
   *
   * @param object|null $entityTypeManager
   *   Optional EntityTypeManagerInterface.
   * @param object|null $aiProvider
   *   Optional Drupal AI provider manager service.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected StateInterface $state,
    protected ClientInterface $httpClient,
    protected LoggerChannelInterface $logger,
    protected UsageTracker $usageTracker,
    protected ?object $entityTypeManager = NULL,
    protected ?object $aiProvider = NULL,
  ) {}

  /**
   * Returns configured provider definitions.
   */
  public function getProviders(): array {
    return $this->configFactory->get('dx_ai_gateway.settings')->get('providers') ?: [];
  }

  /**
   * Returns the default provider machine name (considering tenant override).
   */
  public function getDefaultProvider(): string {
    $tenantDefault = (string) ($this->configFactory->get('dx_tenant.settings')->get('ai_default_provider') ?: '');
    if ($tenantDefault !== '') {
      return $tenantDefault;
    }
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
   * @param string|list<array{role: string, content: string}> $input
   *   Either a string user message or an array of history messages.
   *
   * @return array{provider: string, content: string, tokens: int, model: string}
   */
  public function chat(string|array $input, ?string $provider = NULL): array {
    if (!$this->checkQuota()) {
      throw new \RuntimeException('Monthly AI quota exceeded.');
    }

    $providers = $this->getProviderOrder($provider);
    $lastException = NULL;
    $userPreview = is_array($input) ? (string) (end($input)['content'] ?? '') : $input;

    foreach ($providers as $providerId) {
      try {
        $response = $this->dispatchChat($providerId, $input);
        $tokens = (int) ($response['tokens'] ?? max(1, (int) ceil(strlen($userPreview) / 4)));
        $model = (string) ($response['model'] ?? $this->getModelForProvider($providerId));
        $this->usageTracker->record($providerId, $model, $tokens, 'ok', $userPreview);
        $response['tokens'] = $tokens;
        $response['model'] = $model;
        return $response;
      }
      catch (\Throwable $exception) {
        $lastException = $exception;
        $this->usageTracker->record($providerId, $this->getModelForProvider($providerId), 0, 'error', $userPreview);
        $this->logger->warning('AI provider @provider failed: @message', [
          '@provider' => $providerId,
          '@message' => $exception->getMessage(),
        ]);
      }
    }

    throw new \RuntimeException('All AI providers failed: ' . ($lastException?->getMessage() ?: 'unknown'), 0, $lastException);
  }

  /**
   * Streams chat completions chunk by chunk via a callable callback.
   *
   * @param string|list<array{role: string, content: string}> $input
   *   User prompt or message history.
   * @param callable(string): void $onChunk
   *   Callback invoked for each incremental text token/chunk.
   *
   * @return array{provider: string, content: string, tokens: int, model: string}
   */
  public function chatStream(string|array $input, callable $onChunk, ?string $provider = NULL): array {
    if (!$this->checkQuota()) {
      throw new \RuntimeException('Monthly AI quota exceeded.');
    }

    $providers = $this->getProviderOrder($provider);
    $lastException = NULL;
    $userPreview = is_array($input) ? (string) (end($input)['content'] ?? '') : $input;

    foreach ($providers as $providerId) {
      try {
        $response = $this->dispatchChatStream($providerId, $input, $onChunk);
        $tokens = (int) ($response['tokens'] ?? max(1, (int) ceil(strlen($response['content']) / 4)));
        $model = (string) ($response['model'] ?? $this->getModelForProvider($providerId));
        $this->usageTracker->record($providerId, $model, $tokens, 'ok', $userPreview);
        $response['tokens'] = $tokens;
        $response['model'] = $model;
        return $response;
      }
      catch (\Throwable $exception) {
        $lastException = $exception;
        $this->usageTracker->record($providerId, $this->getModelForProvider($providerId), 0, 'error', $userPreview);
        $this->logger->warning('AI streaming provider @provider failed: @message', [
          '@provider' => $providerId,
          '@message' => $exception->getMessage(),
        ]);
      }
    }

    throw new \RuntimeException('All AI providers failed stream: ' . ($lastException?->getMessage() ?: 'unknown'), 0, $lastException);
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
   * Whether a provider has an API key configured (tenant or platform).
   */
  public function hasApiKey(string $providerId): bool {
    return (bool) ($this->state->get('dx_tenant.ai_keys.' . $providerId) ?: $this->state->get('dx_ai_gateway.api_keys.' . $providerId));
  }

  /**
   * Gets the active API key for a provider (tenant override preferred over platform).
   */
  public function getApiKey(string $providerId): string {
    $tenantKey = (string) $this->state->get('dx_tenant.ai_keys.' . $providerId);
    if ($tenantKey !== '') {
      return $tenantKey;
    }
    return (string) $this->state->get('dx_ai_gateway.api_keys.' . $providerId);
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
    else {
      $default = $this->getDefaultProvider();
      if ($default !== '') {
        $order = array_values(array_unique(array_merge([$default], $order)));
      }
    }
    // Only keep providers that still exist in config.
    $known = array_keys($this->getProviders());
    return array_values(array_filter($order, static fn($id) => in_array($id, $known, TRUE)));
  }

  /**
   * Dispatches a chat request to a single provider.
   */
  protected function dispatchChat(string $providerId, string|array $input): array {
    if ($this->aiProvider && method_exists($this->aiProvider, 'createInstance')) {
      try {
        return $this->chatViaAiModule($providerId, $input);
      }
      catch (\Throwable $exception) {
        $this->logger->notice('AI module path failed for @p, falling back to HTTP: @m', [
          '@p' => $providerId,
          '@m' => $exception->getMessage(),
        ]);
      }
    }

    return $this->chatViaHttp($providerId, $input);
  }

  /**
   * Dispatches a streaming chat request via HTTP chunk reading.
   */
  protected function dispatchChatStream(string $providerId, string|array $input, callable $onChunk): array {
    $providers = $this->getProviders();
    if (empty($providers[$providerId]['base_url'])) {
      throw new \InvalidArgumentException("Unknown provider: {$providerId}");
    }

    $apiKey = $this->getApiKey($providerId);
    if (!$apiKey) {
      throw new \RuntimeException("API key not configured for provider: {$providerId}");
    }

    $model = $this->getModelForProvider($providerId);
    $baseUrl = rtrim((string) $providers[$providerId]['base_url'], '/');
    $messages = $this->buildMessages($input);

    $response = $this->httpClient->request('POST', $baseUrl . '/chat/completions', [
      'headers' => [
        'Authorization' => 'Bearer ' . $apiKey,
        'Content-Type' => 'application/json',
      ],
      'json' => [
        'model' => $model,
        'messages' => $messages,
        'temperature' => 0.7,
        'stream' => TRUE,
      ],
      'stream' => TRUE,
      'timeout' => 60,
    ]);

    $fullText = '';
    $body = $response->getBody();
    $buffer = '';

    while (!$body->eof()) {
      $buffer .= $body->read(512);
      while (($linePos = strpos($buffer, "\n")) !== FALSE) {
        $line = trim(substr($buffer, 0, $linePos));
        $buffer = substr($buffer, $linePos + 1);

        if ($line === '' || !str_starts_with($line, 'data:')) {
          continue;
        }

        $payload = trim(substr($line, 5));
        if ($payload === '[DONE]') {
          break 2;
        }

        $decoded = json_decode($payload, TRUE);
        if (is_array($decoded)) {
          $delta = $decoded['choices'][0]['delta']['content'] ?? '';
          if ($delta !== '') {
            $fullText .= $delta;
            $onChunk($delta);
          }
        }
      }
    }

    return [
      'provider' => $providerId,
      'content' => $fullText,
      'tokens' => max(1, (int) ceil(strlen($fullText) / 4)),
      'model' => $model,
    ];
  }

  /**
   * Uses the Drupal AI module provider manager when available.
   */
  protected function chatViaAiModule(string $providerId, string|array $input): array {
    $instance = $this->aiProvider->createInstance($providerId);
    if (!method_exists($instance, 'chat')) {
      throw new \RuntimeException('AI provider does not support chat().');
    }
    $messages = $this->buildMessages($input);
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
  protected function chatViaHttp(string $providerId, string|array $input): array {
    $providers = $this->getProviders();
    if (empty($providers[$providerId]['base_url'])) {
      throw new \InvalidArgumentException("Unknown provider: {$providerId}");
    }

    $apiKey = $this->getApiKey($providerId);
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
        'messages' => $this->buildMessages($input),
        'temperature' => 0.7,
      ],
      'timeout' => 60,
    ]);

    $parsed = $this->parseChatResponse($providerId, $response);
    $parsed['model'] = $model;
    return $parsed;
  }

  /**
   * Builds knowledge base context from tenant settings, products, and articles.
   */
  public function getKnowledgeContext(): string {
    $parts = [];
    $tenantConfig = $this->configFactory->get('dx_tenant.settings');

    $companyName = (string) ($tenantConfig->get('company_name') ?: '');
    $industry = (string) ($tenantConfig->get('industry') ?: '');
    $customKnowledge = (string) ($tenantConfig->get('ai_knowledge_intro') ?: '');

    if ($companyName !== '') {
      $parts[] = "企业名称: {$companyName}";
    }
    if ($industry !== '') {
      $parts[] = "行业: {$industry}";
    }
    if ($customKnowledge !== '') {
      $parts[] = "企业资料与常见问答:\n{$customKnowledge}";
    }

    // Pull published products and media if EntityTypeManager is available.
    if ($this->entityTypeManager) {
      try {
        /** @var \Drupal\node\NodeStorageInterface $nodeStorage */
        $nodeStorage = $this->entityTypeManager->getStorage('node');

        // Products.
        $pIds = $nodeStorage->getQuery()
          ->accessCheck(FALSE)
          ->condition('type', 'dx_product')
          ->condition('status', 1)
          ->range(0, 10)
          ->sort('created', 'DESC')
          ->execute();

        if ($pIds) {
          $products = $nodeStorage->loadMultiple($pIds);
          $productList = [];
          foreach ($products as $node) {
            /** @var \Drupal\node\NodeInterface $node */
            $title = $node->label();
            $sku = $node->hasField('field_dx_sku') ? (string) $node->get('field_dx_sku')->value : '';
            $price = $node->hasField('field_dx_price') ? (string) $node->get('field_dx_price')->value : '';
            $desc = $node->hasField('body') ? strip_tags((string) $node->get('body')->value) : '';
            $line = "- {$title}";
            if ($sku !== '') {
              $line .= " (SKU: {$sku})";
            }
            if ($price !== '') {
              $line .= " 价格: ¥{$price}";
            }
            if ($desc !== '') {
              $line .= " 描述: " . mb_substr($desc, 0, 100);
            }
            $productList[] = $line;
          }
          if ($productList) {
            $parts[] = "产品与服务目录:\n" . implode("\n", $productList);
          }
        }
      }
      catch (\Throwable $e) {
        // Fallback gracefully if node tables don't exist in early bootstrap.
      }
    }

    return implode("\n\n", $parts);
  }

  /**
   * Builds chat messages including optional system prompt and knowledge context.
   *
   * @param string|list<array{role: string, content: string}> $input
   *
   * @return list<array{role: string, content: string}>
   */
  protected function buildMessages(string|array $input): array {
    $messages = [];
    $systemParts = [];

    $baseSystem = trim((string) ($this->configFactory->get('dx_ai_gateway.settings')->get('system_prompt') ?: ''));
    $tenantSystem = trim((string) ($this->configFactory->get('dx_tenant.settings')->get('ai_system_prompt') ?: ''));

    if ($tenantSystem !== '') {
      $systemParts[] = $tenantSystem;
    }
    elseif ($baseSystem !== '') {
      $systemParts[] = $baseSystem;
    }

    $knowledge = $this->getKnowledgeContext();
    if ($knowledge !== '') {
      $systemParts[] = "【企业知识库与产品信息参考】\n" . $knowledge;
    }

    if ($systemParts) {
      $messages[] = ['role' => 'system', 'content' => implode("\n\n", $systemParts)];
    }

    if (is_array($input)) {
      foreach ($input as $msg) {
        if (isset($msg['role'], $msg['content'])) {
          $messages[] = [
            'role' => in_array($msg['role'], ['user', 'assistant', 'system'], TRUE) ? $msg['role'] : 'user',
            'content' => (string) $msg['content'],
          ];
        }
      }
    }
    else {
      $messages[] = ['role' => 'user', 'content' => $input];
    }

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

