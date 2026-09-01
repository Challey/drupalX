<?php

declare(strict_types=1);

namespace Drupal\Tests\dx_auth\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ConfigInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\dx_auth\Service\SmsAuthService;
use Drupal\dx_auth\Service\SocialAccountLinker;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * R1 · 手机短信验证码通道回归（Aliyun Dysmsapi，无 SDK 的 HTTP RPC）。
 *
 * @coversDefaultClass \Drupal\dx_auth\Service\SmsAuthService
 * @group dx_auth
 */
class SmsAuthServiceTest extends UnitTestCase {

  /**
   * @var array<int, array<string, mixed>>
   */
  private array $sent = [];

  protected function setUp(): void {
    parent::setUp();
    $this->sent = [];
  }

  private function configFactory(array $settings): MockObject {
    $config = $this->createMock(ConfigInterface::class);
    $config->method('get')->willReturnCallback(static fn ($key = '') => $settings[$key] ?? NULL);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('dx_auth.settings')->willReturn($config);
    return $factory;
  }

  /**
   * Client with a queued answer set plus a history sink for the outgoing URL.
   *
   * @param array<int, \GuzzleHttp\Psr7\Response|\Throwable> $queue
   */
  private function client(array $queue): Client {
    $handler = new MockHandler($queue);
    $stack = HandlerStack::create($handler);
    $stack->push(Middleware::history($this->sent));
    return new Client(['handler' => $stack]);
  }

  private static function full(): array {
    return [
      'sms_enabled' => TRUE,
      'sms_access_key' => 'LTAI-test-key',
      'sms_access_secret' => 'test-secret',
      'sms_sign_name' => 'DrupalX',
      'sms_template_code' => 'SMS_100000',
    ];
  }

  /**
   * Four config keys gate the channel; the sign name is the only one with a
   * hard-coded fallback.
   *
   * @covers ::isEnabled
   * @dataProvider enabledMatrix
   */
  public function testIsEnabledMatrix(array $settings, bool $expected): void {
    $svc = $this->service($settings, [], $this->flood(TRUE), $this->linker());
    $this->assertSame($expected, $svc->isEnabled());
  }

  /**
   * @return array<string, array{0: array<string, mixed>, 1: bool}>
   */
  public static function enabledMatrix(): array {
    return [
      'fully configured' => [self::full(), TRUE],
      'switch off' => [self::full() + ['sms_enabled' => FALSE], FALSE],
      'switch missing' => [['sms_access_key' => 'a', 'sms_access_secret' => 'b', 'sms_template_code' => 'c'], FALSE],
      'access key blank' => [self::full() + ['sms_access_key' => ' '], FALSE],
      'access secret blank' => [self::full() + ['sms_access_secret' => ''], FALSE],
      'template blank' => [self::full() + ['sms_template_code' => '  '], FALSE],
      'sign name blank is fine' => [self::full() + ['sms_sign_name' => ''], TRUE],
    ];
  }

  private function flood(bool $allowed): MockObject {
    $flood = $this->createMock(FloodInterface::class);
    $flood->method('isAllowed')->willReturn($allowed);
    return $flood;
  }

  private function linker(?MockObject $linker = NULL): MockObject {
    return $linker ?? $this->createMock(SocialAccountLinker::class);
  }

  private function service(array $settings, array $queue, MockObject $flood, MockObject $linker, ?MockObject $logger = NULL): SmsAuthService {
    $logger ??= $this->createMock(LoggerChannelInterface::class);
    return new SmsAuthService(
      $this->configFactory($settings),
      $this->client($queue),
      $linker,
      $flood,
      $logger,
    );
  }

  /**
   * @covers ::sendCode
   */
  public function testSendCodeRefusesDisabledChannel(): void {
    $linker = $this->linker();
    $linker->expects($this->never())->method('storeSmsCode');
    $svc = $this->service(['sms_enabled' => FALSE], [], $this->flood(TRUE), $linker);
    $this->assertSame('sms_disabled', $svc->sendCode('13800138000', '203.0.113.9'));
  }

  /**
   * @covers ::sendCode
   * @dataProvider badMobileProvider
   */
  public function testSendCodeRefusesBadNumber(string $input): void {
    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->never())->method('isAllowed');
    $svc = $this->service(self::full(), [], $flood, $this->linker());
    $this->assertSame('invalid_mobile', $svc->sendCode($input, '203.0.113.9'));
  }

  /**
   * @return array<string, array{0: string}>
   */
  public static function badMobileProvider(): array {
    return [
      'empty' => [''],
      'letters' => ['abcdefgh'],
      'separators only' => ['  -- '],
      'too short' => ['13800'],
      'plus only' => ['+'],
    ];
  }

  /**
   * The IP window (10/h) is checked before the per-number window (5/h).
   *
   * @covers ::sendCode
   */
  public function testSendCodeHitsIpFlood(): void {
    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->once())
      ->method('isAllowed')
      ->with('dx_auth.sms_send_ip', 10, 3600, '203.0.113.9')
      ->willReturn(FALSE);
    $linker = $this->linker();
    $linker->expects($this->never())->method('storeSmsCode');
    $svc = $this->service(self::full(), [], $flood, $linker);
    $this->assertSame('flood', $svc->sendCode('13800138000', '203.0.113.9'));
  }

  /**
   * @covers ::sendCode
   */
  public function testSendCodeHitsMobileFlood(): void {
    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->exactly(2))
      ->method('isAllowed')
      ->willReturnCallback(static fn ($name, $threshold, $window, $identifier) => $name !== 'dx_auth.sms_send_mobile');
    $svc = $this->service(self::full(), [], $flood, $this->linker());
    $this->assertSame('flood', $svc->sendCode('13800138000', '203.0.113.9'));
  }

  /**
   * Happy path: OTP stored, both flood counters registered, in that order.
   *
   * @covers ::sendCode
   */
  public function testSendCodeSuccess(): void {
    $linker = $this->linker();
    $linker->method('normalizeMobile')->willReturnArgument(0);
    $linker->expects($this->once())
      ->method('storeSmsCode')
      ->with('13800138000', $this->matchesRegularExpression('/^\d{6}$/'));

    $flood = $this->createMock(FloodInterface::class);
    $flood->method('isAllowed')->willReturn(TRUE);
    $events = [];
    $flood->method('register')->willReturnCallback(static function ($name, $window, $identifier) use (&$events): void {
      $events[] = $name . '|' . $identifier;
    });

    $svc = $this->service(self::full(), [new Response(200, [], '{"Code":"OK","Message":"OK"}')], $flood, $linker);
    $this->assertTrue($svc->sendCode('13800138000', '203.0.113.9'));
    $this->assertSame(['dx_auth.sms_send_ip|203.0.113.9', 'dx_auth.sms_send_mobile|13800138000'], $events);
  }

  /**
   * A provider rejection must not consume the sender's quota counters.
   *
   * @covers ::sendCode
   */
  public function testSendCodeProviderFailurePassesMessageThrough(): void {
    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->once())->method('error');
    $linker = $this->linker();
    $linker->method('normalizeMobile')->willReturnArgument(0);
    $linker->expects($this->never())->method('storeSmsCode');
    $flood = $this->createMock(FloodInterface::class);
    $flood->method('isAllowed')->willReturn(TRUE);
    $flood->expects($this->never())->method('register');

    $svc = $this->service(self::full(), [new Response(200, [], '{"Code":"isv.SIGN_NAME_ILLEGAL","Message":"签名不合法"}')], $flood, $linker, $logger);
    $this->assertSame('签名不合法', $svc->sendCode('13800138000', '203.0.113.9'));
  }

  /**
   * A body without any Message collapses to the generic key.
   *
   * @covers ::sendCode
   */
  public function testSendCodeProviderFailureWithoutMessage(): void {
    $linker = $this->linker();
    $linker->method('normalizeMobile')->willReturnArgument(0);
    $svc = $this->service(self::full(), [new Response(500, [], 'gateway blew up')], $this->flood(TRUE), $linker);
    $this->assertSame('send_failed', $svc->sendCode('13800138000', '203.0.113.9'));
  }

  /**
   * Up/timeout never bubbles out of the login page.
   *
   * @covers ::sendCode
   */
  public function testSendCodeSwallowsTransportErrors(): void {
    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->once())->method('error');
    $linker = $this->linker();
    $linker->method('normalizeMobile')->willReturnArgument(0);
    $svc = $this->service(self::full(), [new ConnectException('cURL error 28', new Psr7Request('GET', '/'))], $this->flood(TRUE), $linker, $logger);
    $this->assertSame('send_failed', $svc->sendCode('13800138000', '203.0.113.9'));
  }

  /**
   * Outgoing RPC contract: +86 is stripped for the carrier, everything else is
   * Aliyun's fixed parameter set.
   *
   * @covers ::sendCode
   */
  public function testOutgoingRpcParameters(): void {
    $linker = $this->linker();
    $linker->method('normalizeMobile')->willReturnCallback(
      static fn (string $mobile): string => str_replace([' ', '-'], '', $mobile)
    );
    $stored = NULL;
    $linker->method('storeSmsCode')->willReturnCallback(static function ($mobile, $code) use (&$stored): void {
      $stored = [$mobile, $code];
    });

    $svc = $this->service(self::full(), [new Response(200, [], '{"Code":"OK"}')], $this->flood(TRUE), $linker);
    $this->assertTrue($svc->sendCode('+86 138-0013-8000', '203.0.113.9'));

    $this->assertCount(1, $this->sent);
    $query = [];
    parse_str((string) parse_url((string) $this->sent[0]['request']->getUri(), PHP_URL_QUERY), $query);
    $this->assertSame('SendSms', $query['Action']);
    $this->assertSame('2017-05-25', $query['Version']);
    $this->assertSame('cn-hangzhou', $query['RegionId']);
    $this->assertSame('HMAC-SHA1', $query['SignatureMethod']);
    $this->assertSame('1.0', $query['SignatureVersion']);
    $this->assertSame('LTAI-test-key', $query['AccessKeyId']);
    $this->assertSame('DrupalX', $query['SignName']);
    $this->assertSame('SMS_100000', $query['TemplateCode']);
    $this->assertSame('13800138000', $query['PhoneNumbers'], 'the carrier receives the national number without +86');
    $this->assertSame($stored[1], json_decode((string) $query['TemplateParam'], TRUE)['code'], 'the OTP that is sent is the OTP that is stored');

    // Present-day asymmetry, recorded on purpose: the OTP is cached under the
    // '+86…' key because normalizeMobile() keeps the country code, so the
    // login request has to repeat the exact same spelling of the number.
    $this->assertSame('+8613800138000', $stored[0]);
  }

  /**
   * Aliyun's percent-encoding differs from rawurlencode() in three places.
   *
   * @covers ::percentEncode
   * @dataProvider percentProvider
   */
  public function testPercentEncode(string $value, string $expected): void {
    $svc = $this->service(self::full(), [], $this->flood(TRUE), $this->linker());
    $method = new \ReflectionMethod(SmsAuthService::class, 'percentEncode');
    $method->setAccessible(TRUE);
    $this->assertSame($expected, $method->invoke($svc, $value));
  }

  /**
   * @return array<string, array{0: string, 1: string}>
   */
  public static function percentProvider(): array {
    return [
      'plain' => ['abc123', 'abc123'],
      'space star tilde plus' => ['a b*c~+', 'a%20b%2Ac~%2B'],
      'url' => ['https://x.example/a?b=c&d=e', 'https%3A%2F%2Fx.example%2Fa%3Fb%3Dc%26d%3De'],
      'chinese' => ['签名不合法', '%E7%AD%BE%E5%90%8D%E4%B8%8D%E5%90%88%E6%B3%95'],
    ];
  }

}
