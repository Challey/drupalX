<?php

declare(strict_types=1);

namespace Drupal\dcn_ai_gateway\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Multi-provider AI gateway with failover support.
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
    protected ?object $aiProvider = NULL,
  ) {}

  /**
   * Returns configured provider definitions.
   */
  public function getProviders(): array {
    return $this->configFactory->get('dcn_ai_gateway.settings')->get('providers') ?: [];
  }

  /**
   * Returns the default provider machine name.
   */
  public function getDefaultProvider(): string {
    return (string) ($this->configFactory->get('dcn_ai_gateway.settings')->get('default_provider') ?: 'openai');
  }

  /**
   * Checks whether the monthly quota has been exceeded.
   */
  public function checkQuota(int $tokens = 0): bool {
    $quota = (int) ($this->configFactory->get('dcn_ai_gateway.settings')->get('monthly_quota') ?: 100000);
    $used = (int) $this->state->get('dcn_ai_gateway.tokens_used', 0);
    return ($used + $tokens) <= $quota;
  }

  /**
   * Sends a chat request using the configured provider chain.
   *
   * @param string $message
   *   User message text.
   * @param string|null $provider
   *   Optional provider override.
   *
   * @return array
   *   Response payload with keys: provider, content, tokens.
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
        $tokens = (int) ($response['tokens'] ?? strlen($message));
        $this->state->set('dcn_ai_gateway.tokens_used', (int) $this->state->get('dcn_ai_gateway.tokens_used', 0) + $tokens);
        return $response;
      }
      catch (\Throwable $exception) {
        $lastException = $exception;
        $this->logger->warning('AI provider @provider failed: @message', [
          '@provider' => $providerId,
          '@message' => $exception->getMessage(),
        ]);
      }
    }

    throw new \RuntimeException('All AI providers failed.', 0, $lastException);
  }

  /**
   * Builds the provider attempt order.
   */
  protected function getProviderOrder(?string $preferred): array {
    $config = $this->configFactory->get('dcn_ai_gateway.settings');
    $order = $config->get('failover_order') ?: [];
    if ($preferred) {
      $order = array_values(array_unique(array_merge([$preferred], $order)));
    }
    elseif ($config->get('default_provider')) {
      $default = $config->get('default_provider');
      $order = array_values(array_unique(array_merge([$default], $order)));
    }
    return $order;
  }

  /**
   * Dispatches a chat request to a single provider.
   */
  protected function dispatchChat(string $providerId, string $message): array {
    if ($this->aiProvider && method_exists($this->aiProvider, 'createInstance')) {
      return $this->chatViaAiModule($providerId, $message);
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
    $result = $instance->chat([
      ['role' => 'user', 'content' => $message],
    ]);
    return [
      'provider' => $providerId,
      'content' => is_array($result) ? ($result['content'] ?? json_encode($result)) : (string) $result,
      'tokens' => is_array($result) ? (int) ($result['tokens'] ?? 0) : 0,
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

    $apiKey = $this->state->get('dcn_ai_gateway.api_keys.' . $providerId);
    if (!$apiKey) {
      throw new \RuntimeException("API key not configured for provider: {$providerId}");
    }

    $baseUrl = rtrim($providers[$providerId]['base_url'], '/');
    $response = $this->httpClient->request('POST', $baseUrl . '/chat/completions', [
      'headers' => [
        'Authorization' => 'Bearer ' . $apiKey,
        'Content-Type' => 'application/json',
      ],
      'json' => [
        'model' => $this->getModelForProvider($providerId),
        'messages' => [
          ['role' => 'user', 'content' => $message],
        ],
      ],
      'timeout' => 60,
    ]);

    return $this->parseChatResponse($providerId, $response);
  }

  /**
   * Parses an OpenAI-compatible chat completion response.
   */
  protected function parseChatResponse(string $providerId, ResponseInterface $response): array {
    $body = json_decode((string) $response->getBody(), TRUE);
    if (!is_array($body)) {
      throw new \RuntimeException('Invalid AI response payload.');
    }

    $content = $body['choices'][0]['message']['content'] ?? '';
    $tokens = (int) ($body['usage']['total_tokens'] ?? 0);

    return [
      'provider' => $providerId,
      'content' => $content,
      'tokens' => $tokens,
    ];
  }

  /**
   * Returns a default model name per provider.
   */
  protected function getModelForProvider(string $providerId): string {
    return match ($providerId) {
      'deepseek' => 'deepseek-chat',
      'qwen' => 'qwen-plus',
      'zhipu' => 'glm-4',
      default => 'gpt-4o-mini',
    };
  }

}
