<?php

declare(strict_types=1);

namespace Drupal\Tests\dx_ai_gateway\Unit;

use Drupal\dx_ai_gateway\Commands\AiStatusCommands;
use Drupal\dx_ai_gateway\Service\AiGateway;
use Drupal\dx_ai_gateway\Service\UsageTracker;
use Drupal\Tests\UnitTestCase;

/**
 * R3 · dx:ai-readiness 就绪报表的单元测试（模型 / 密钥 / 配额 三元组）。
 *
 * buildReport() 是纯静态整形器；collect() 只读 AiGateway / UsageTracker。两者
 * 都不写配置、不写 State、不出网。命令名刻意用 dx:ai-readiness，不复用现网
 * AiCommands 的 dx:ai-status（L1 的 delivery-ops-smoke.sh 依赖其 ready_count）。
 *
 * @coversDefaultClass \Drupal\dx_ai_gateway\Commands\AiStatusCommands
 * @group dx_ai_gateway
 */
class AiStatusCommandsTest extends UnitTestCase {

  /**
   * The four shipping providers, as config/install declares them.
   *
   * @return array<string, array<string, string>>
   */
  private function providers(): array {
    return [
      'openai' => ['label' => 'OpenAI', 'model' => 'gpt-4o-mini'],
      'deepseek' => ['label' => 'DeepSeek', 'model' => 'deepseek-chat'],
      'qwen' => ['label' => '通义千问', 'model' => 'qwen-plus'],
      'zhipu' => ['label' => '智谱 GLM', 'model' => 'glm-4'],
    ];
  }

  /**
   * @return array<string, int|string>
   */
  private function quota(int $remaining = 100000): array {
    return [
      'period' => '2026-09',
      'quota' => 100000,
      'tokens_used' => 100000 - $remaining,
      'remaining' => $remaining,
      'calls' => 0,
      'ok_calls' => 0,
    ];
  }

  /**
   * Builds provider rows the way collect() does, with a configurable key state.
   *
   * @param array<string, string> $sources
   *   Provider id → key source ('none'|'site'|'environment').
   *
   * @return list<array<string, mixed>>
   */
  private function rows(string $default, array $sources = []): array {
    $rows = [];
    foreach ($this->providers() as $id => $meta) {
      $source = $sources[$id] ?? 'none';
      $rows[] = [
        'id' => $id,
        'label' => $meta['label'],
        'model' => $meta['model'],
        'key_configured' => $source !== 'none',
        'key_source' => $source,
        'default' => $id === $default,
      ];
    }
    return $rows;
  }

  /**
   * @covers ::buildReport
   */
  public function testNoKeyEnvironmentIsParseableAndFlagsEveryProvider(): void {
    $report = AiStatusCommands::buildReport($this->rows('deepseek'), $this->quota());

    $this->assertFalse($report['ready']);
    $this->assertSame(0, $report['ready_count']);
    $this->assertSame(4, $report['provider_count']);
    $this->assertSame(['openai', 'deepseek', 'qwen', 'zhipu'], $report['missing']);
    $this->assertStringContainsString('missing API key(s)', $report['hint']);
    $this->assertStringContainsString('/admin/dx/ai', $report['hint']);
    $this->assertSame('deepseek', $report['default_provider']);

    // The 三元组 keys are always present.
    $this->assertArrayHasKey('models', $report);
    $this->assertArrayHasKey('keys', $report);
    $this->assertArrayHasKey('quota', $report);
    $this->assertSame('deepseek-chat', $report['models']['deepseek']);
    $this->assertSame(['configured' => FALSE, 'source' => 'none'], $report['keys']['deepseek']);

    // The --format=json contract: valid, round-trippable JSON.
    $json = json_encode($report, JSON_UNESCAPED_UNICODE);
    $this->assertNotFalse($json);
    $this->assertTrue(json_validate($json));
    $this->assertSame($report, json_decode($json, TRUE));
  }

  /**
   * @covers ::buildReport
   */
  public function testDefaultProviderKeyMakesItReady(): void {
    $report = AiStatusCommands::buildReport(
      $this->rows('deepseek', ['deepseek' => 'environment']),
      $this->quota(),
    );
    $this->assertTrue($report['ready']);
    $this->assertSame(1, $report['ready_count']);
    $this->assertSame(['openai', 'qwen', 'zhipu'], $report['missing']);
    $this->assertNotContains('deepseek', $report['missing']);
    $this->assertSame('environment', $report['keys']['deepseek']['source']);
  }

  /**
   * @covers ::buildReport
   */
  public function testExhaustedQuotaBlocksReadinessDespiteKeys(): void {
    $report = AiStatusCommands::buildReport(
      $this->rows('deepseek', ['deepseek' => 'site', 'qwen' => 'site', 'zhipu' => 'site', 'openai' => 'site']),
      $this->quota(0),
    );
    $this->assertFalse($report['ready'], 'remaining=0 is a kill switch even with every key present');
    $this->assertSame(4, $report['ready_count']);
    $this->assertSame([], $report['missing']);
    $this->assertStringContainsString('monthly quota exhausted', $report['hint']);
  }

  /**
   * @covers ::buildReport
   */
  public function testFullyReadyHasCleanHint(): void {
    $report = AiStatusCommands::buildReport(
      $this->rows('deepseek', ['deepseek' => 'site', 'qwen' => 'site', 'zhipu' => 'site', 'openai' => 'site']),
      $this->quota(),
    );
    $this->assertTrue($report['ready']);
    $this->assertSame('ok', $report['hint']);
    $this->assertSame([], $report['missing']);
  }

  /**
   * @covers ::buildReport
   */
  public function testSecretsAreNeverEmitted(): void {
    $report = AiStatusCommands::buildReport([
      [
        'id' => 'deepseek',
        'model' => 'deepseek-chat',
        'key_configured' => TRUE,
        'key_source' => 'site',
        'default' => TRUE,
        // A caller mistake the shaper must not propagate.
        'api_key' => 'sk-SUPER-SECRET-123',
      ],
    ], $this->quota());

    $json = (string) json_encode($report, JSON_UNESCAPED_UNICODE);
    $this->assertStringNotContainsString('sk-SUPER-SECRET-123', $json);
    $this->assertStringNotContainsString('api_key', $json);
    $this->assertSame(
      ['id', 'label', 'model', 'key_configured', 'key_source', 'default'],
      array_keys($report['providers'][0]),
    );
  }

  /**
   * @covers ::buildReport
   */
  public function testEmptyCatalogAndPartialQuotaAreTyped(): void {
    $empty = AiStatusCommands::buildReport([], ['remaining' => 5]);
    $this->assertSame(0, $empty['provider_count']);
    $this->assertFalse($empty['ready']);
    $this->assertSame('', $empty['default_provider']);
    $this->assertStringContainsString('no default provider resolved', $empty['hint']);

    $noQuota = AiStatusCommands::buildReport($this->rows('deepseek'), []);
    $this->assertSame(0, $noQuota['quota']['quota']);
    $this->assertSame('', $noQuota['quota']['period']);
    $this->assertFalse($noQuota['ready'], 'an absent remaining is treated as exhausted');
  }

  /**
   * collect() reads only the read-only service methods and shapes the triple.
   *
   * @covers ::collect
   */
  public function testCollectWiresGatewayAndUsageTracker(): void {
    $gateway = $this->createMock(AiGateway::class);
    $gateway->method('getDefaultProvider')->willReturn('deepseek');
    $gateway->method('getProviders')->willReturn($this->providers());
    $gateway->method('getModelForProvider')->willReturnCallback(
      fn (string $id): string => $this->providers()[$id]['model'] ?? 'unknown'
    );
    // Only deepseek has a key, sourced from the environment.
    $gateway->method('hasApiKey')->willReturnCallback(fn (string $id): bool => $id === 'deepseek');
    $gateway->method('getApiKeySource')->willReturnCallback(
      fn (string $id): string => $id === 'deepseek' ? 'environment' : 'none'
    );

    $tracker = $this->createMock(UsageTracker::class);
    $tracker->method('summary')->willReturn($this->quota());

    // The DrushCommands constructor needs a Drush runtime, so build the object
    // without it and inject the two collaborators by reflection.
    $command = (new \ReflectionClass(AiStatusCommands::class))->newInstanceWithoutConstructor();
    foreach (['aiGateway' => $gateway, 'usageTracker' => $tracker] as $prop => $value) {
      $rp = new \ReflectionProperty(AiStatusCommands::class, $prop);
      $rp->setAccessible(TRUE);
      $rp->setValue($command, $value);
    }

    $collect = new \ReflectionMethod(AiStatusCommands::class, 'collect');
    $collect->setAccessible(TRUE);
    /** @var array<string, mixed> $report */
    $report = $collect->invoke($command);

    $this->assertTrue($report['ready']);
    $this->assertSame(1, $report['ready_count']);
    $this->assertSame('deepseek', $report['default_provider']);
    $this->assertSame('deepseek-chat', $report['models']['deepseek']);
    $this->assertSame(['openai', 'qwen', 'zhipu'], $report['missing']);
    $this->assertSame(100000, $report['quota']['remaining']);
  }

}
