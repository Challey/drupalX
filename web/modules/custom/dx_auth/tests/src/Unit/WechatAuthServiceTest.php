<?php

declare(strict_types=1);

namespace Drupal\Tests\dx_auth\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use Drupal\dx_auth\Service\WechatAuthService;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * R1 · WeChat 扫码通道回归（生产实现，断言按现网签名写）。
 *
 * 注意类名是 WechatAuthService（小写 c），不是历史分支里的 WeChatAuthService。
 * 所有出站 HTTP 都走 Guzzle MockHandler，单测永不触网。
 *
 * @coversDefaultClass \Drupal\dx_auth\Service\WechatAuthService
 * @group dx_auth
 */
class WechatAuthServiceTest extends UnitTestCase {

  /**
   * A state bag that behaves like the real key/value state store.
   */
  private function state(array $initial = []): MockObject {
    $bag = $initial;
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturnCallback(static function (string $key, $default = NULL) use (&$bag) {
      return $bag[$key] ?? $default;
    });
    $state->method('set')->willReturnCallback(static function (string $key, $value) use (&$bag): void {
      $bag[$key] = $value;
    });
    $state->method('delete')->willReturnCallback(static function (string $key) use (&$bag): void {
      unset($bag[$key]);
    });
    return $state;
  }

  /**
   * Config factory serving one dx_auth.settings document.
   */
  private function configFactory(array $settings): MockObject {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(static fn ($key = '') => $settings[$key] ?? NULL);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('dx_auth.settings')->willReturn($config);
    return $factory;
  }

  /**
   * Client that answers from a queue and never opens a socket.
   *
   * @param array<int, \GuzzleHttp\Psr7\Response|\Throwable> $queue
   */
  private function client(array $queue): Client {
    return new Client(['handler' => HandlerStack::create(new MockHandler($queue))]);
  }

  /**
   * Default "fully configured" settings document.
   */
  private static function full(): array {
    return [
      'wechat_enabled' => TRUE,
      'wechat_app_id' => 'wx0123456789abcdef',
      'wechat_secret' => 'topstar-mp-secret',
      'wechat_token' => 'mp-server-token',
    ];
  }

  /**
   * The gate every wechat endpoint checks before doing anything.
   *
   * @covers ::isEnabled
   * @dataProvider enabledMatrix
   */
  public function testIsEnabledMatrix(array $settings, bool $expected): void {
    $svc = new WechatAuthService(
      $this->configFactory($settings),
      $this->client([]),
      $this->state(),
      $this->createMock(LoggerChannelInterface::class),
    );
    $this->assertSame($expected, $svc->isEnabled());
  }

  /**
   * @return array<string, array{0: array<string, mixed>, 1: bool}>
   */
  public static function enabledMatrix(): array {
    return [
      'fully configured' => [self::full(), TRUE],
      'switch off' => [array_merge(self::full(), ['wechat_enabled' => FALSE]), FALSE],
      'switch missing' => [['wechat_app_id' => 'wx1', 'wechat_secret' => 's'], FALSE],
      'app id blank' => [array_merge(self::full(), ['wechat_app_id' => '   ']), FALSE],
      'secret blank' => [array_merge(self::full(), ['wechat_secret' => '']), FALSE],
    ];
  }

  /**
   * The authorize URL is what the browser is redirected to; its exact shape is
   * part of the contract with WeChat MP.
   *
   * @covers ::buildOauthAuthorizeUrl
   */
  public function testBuildOauthAuthorizeUrl(): void {
    $svc = new WechatAuthService(
      $this->configFactory(self::full()),
      $this->client([]),
      $this->state(),
      $this->createMock(LoggerChannelInterface::class),
    );

    $url = $svc->buildOauthAuthorizeUrl('https://example.com/dx/auth/wechat_jump', 'st/ate+1');

    $this->assertStringStartsWith('https://open.weixin.qq.com/connect/oauth2/authorize?', $url);
    $this->assertStringEndsWith('#wechat_redirect', $url);
    $this->assertStringContainsString('appid=wx0123456789abcdef', $url);
    $this->assertStringContainsString('scope=snsapi_userinfo', $url);
    $this->assertStringContainsString('response_type=code', $url);
    // RFC 3986 encoding: '+' and '/' must NOT collapse into a space.
    $this->assertStringContainsString('state=st%2Fate%2B1', $url);
    $this->assertStringContainsString('redirect_uri=https%3A%2F%2Fexample.com%2Fdx%2Fauth%2Fwechat_jump', $url);
  }

  /**
   * @covers ::buildOauthAuthorizeUrl
   */
  public function testBuildOauthAuthorizeUrlWithoutAppIdStillBuilds(): void {
    $svc = new WechatAuthService(
      $this->configFactory(['wechat_enabled' => FALSE]),
      $this->client([]),
      $this->state(),
      $this->createMock(LoggerChannelInterface::class),
    );
    // Present-day behaviour: the builder itself does not gate on config, so it
    // emits an empty appid. The controller is what refuses to reach it.
    $this->assertStringContainsString('appid=&', $svc->buildOauthAuthorizeUrl('https://example.com/jump', 's1'));
  }

  /**
   * MP server callback signature: sha1 of the sorted token/timestamp/nonce.
   *
   * @covers ::checkSignature
   */
  public function testCheckSignature(): void {
    $svc = new WechatAuthService(
      $this->configFactory(self::full()),
      $this->client([]),
      $this->state(),
      $this->createMock(LoggerChannelInterface::class),
    );
    $timestamp = '1700000000';
    $nonce = 'abc123';
    $parts = ['mp-server-token', $timestamp, $nonce];
    sort($parts, SORT_STRING);
    $signature = sha1(implode($parts));

    $this->assertTrue($svc->checkSignature($signature, $timestamp, $nonce));
    $this->assertFalse($svc->checkSignature($signature, '1700000001', $nonce), 'a moved timestamp invalidates the signature');
    $this->assertFalse($svc->checkSignature(strrev($signature), $timestamp, $nonce), 'a tampered signature is refused');
  }

  /**
   * Token fetch caches into state and the cache is honoured.
   *
   * @covers ::getAccessToken
   */
  public function testGetAccessTokenIsCached(): void {
    // Exactly one response is queued: a second HTTP call would drain the queue
    // and surface as an exception, so passing twice proves the cache.
    $state = $this->state();
    $svc = new WechatAuthService(
      $this->configFactory(self::full()),
      $this->client([new Response(200, [], '{"access_token":"AT-1","expires_in":7200}')]),
      $state,
      $this->createMock(LoggerChannelInterface::class),
    );

    $this->assertSame('AT-1', $svc->getAccessToken());
    $this->assertSame('AT-1', $svc->getAccessToken());
  }

  /**
   * A live-enough cached token short-circuits the HTTP call.
   *
   * @covers ::getAccessToken
   */
  public function testGetAccessTokenReadsFreshCache(): void {
    $state = $this->state(['dx_auth.wechat_access_token' => ['token' => 'CACHED', 'expire' => time() + 3600]]);
    $state->expects($this->never())->method('set');

    $svc = new WechatAuthService(
      $this->configFactory(self::full()),
      $this->client([]),
      $state,
      $this->createMock(LoggerChannelInterface::class),
    );
    $this->assertSame('CACHED', $svc->getAccessToken());
  }

  /**
   * Error payloads degrade to an empty token and are logged, never thrown.
   *
   * @covers ::getAccessToken
   */
  public function testGetAccessTokenFailureIsLoggedAndEmpty(): void {
    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->once())->method('error');
    $svc = new WechatAuthService(
      $this->configFactory(self::full()),
      $this->client([new Response(200, [], '{"errcode":40013,"errmsg":"invalid appid"}')]),
      $this->state(),
      $logger,
    );
    $this->assertSame('', $svc->getAccessToken());
  }

  /**
   * Up/timeout failures must not take the login page down.
   *
   * @covers ::getAccessToken
   */
  public function testGetAccessTokenSwallowsTransportErrors(): void {
    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->once())->method('error');
    $svc = new WechatAuthService(
      $this->configFactory(self::full()),
      $this->client([new ConnectException('cURL error 28: timed out', new Psr7Request('GET', '/'))]),
      $this->state(),
      $logger,
    );
    $this->assertSame('', $svc->getAccessToken());
  }

  /**
   * Unconfigured credentials must not even attempt a request.
   *
   * @covers ::getAccessToken
   */
  public function testGetAccessTokenWithoutCredentials(): void {
    $svc = new WechatAuthService(
      $this->configFactory(['wechat_enabled' => TRUE]),
      $this->client([]),
      $this->state(),
      $this->createMock(LoggerChannelInterface::class),
    );
    $this->assertSame('', $svc->getAccessToken());
  }

  /**
   * @covers ::createLoginQr
   */
  public function testCreateLoginQr(): void {
    $svc = new WechatAuthService(
      $this->configFactory(self::full()),
      $this->client([
        new Response(200, [], '{"access_token":"AT-1","expires_in":7200}'),
        new Response(200, [], '{"ticket":"gQHO8AAAAAAAAA","url":"https://mp.weixin.qq.com/x"}'),
      ]),
      $this->state(),
      $this->createMock(LoggerChannelInterface::class),
    );
    $qr = $svc->createLoginQr('dxb1234');
    $this->assertIsArray($qr);
    $this->assertSame('dxb1234', $qr['scene_id']);
    $this->assertSame('https://mp.weixin.qq.com/cgi-bin/showqrcode?ticket=' . urlencode('gQHO8AAAAAAAAA'), $qr['url']);
  }

  /**
   * @covers ::createLoginQr
   */
  public function testCreateLoginQrReturnsNullWithoutToken(): void {
    $svc = new WechatAuthService(
      $this->configFactory(['wechat_enabled' => TRUE]),
      $this->client([]),
      $this->state(),
      $this->createMock(LoggerChannelInterface::class),
    );
    $this->assertNull($svc->createLoginQr('dxb1'));
  }

  /**
   * @covers ::createLoginQr
   */
  public function testCreateLoginQrReturnsNullWhenTicketMissing(): void {
    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->once())->method('error');
    $svc = new WechatAuthService(
      $this->configFactory(self::full()),
      $this->client([
        new Response(200, [], '{"access_token":"AT-1","expires_in":7200}'),
        new Response(200, [], '{"errcode":45009,"errmsg":"reach max api second quota"}'),
      ]),
      $this->state(),
      $logger,
    );
    $this->assertNull($svc->createLoginQr('dxb1'));
  }

  /**
   * @covers ::openidFromCode
   */
  public function testOpenidFromCode(): void {
    $svc = new WechatAuthService(
      $this->configFactory(self::full()),
      $this->client([new Response(200, [], '{"openid":"oABC-123","access_token":"oat","expires_in":7200}')]),
      $this->state(),
      $this->createMock(LoggerChannelInterface::class),
    );
    $this->assertSame('oABC-123', $svc->openidFromCode('code-1'));
  }

  /**
   * 40029 (invalid code) / a second use of the same code yields '' and the
   * controller turns that into '二维码已失效，请重新扫码'.
   *
   * @covers ::openidFromCode
   */
  public function testOpenidFromCodeFailure(): void {
    $svc = new WechatAuthService(
      $this->configFactory(self::full()),
      $this->client([new Response(200, [], '{"errcode":40029,"errmsg":"invalid code"}')]),
      $this->state(),
      $this->createMock(LoggerChannelInterface::class),
    );
    $this->assertSame('', $svc->openidFromCode('used-code'));
  }

}
