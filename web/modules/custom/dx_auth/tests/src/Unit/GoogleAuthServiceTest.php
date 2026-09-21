<?php

declare(strict_types=1);

namespace Drupal\Tests\dx_auth\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\dx_auth\Service\GoogleAuthService;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpFoundation\Request;

/**
 * R1 · Google 通道回归（含大陆访客隐藏逻辑）。
 *
 * @coversDefaultClass \Drupal\dx_auth\Service\GoogleAuthService
 * @group dx_auth
 */
class GoogleAuthServiceTest extends UnitTestCase {

  /**
   * @var array<int, array<string, mixed>>
   */
  private array $sent = [];

  protected function setUp(): void {
    parent::setUp();
    $this->sent = [];
  }

  private function configFactory(array $settings = []): MockObject {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(static fn ($key = '') => $settings[$key] ?? NULL);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('dx_auth.settings')->willReturn($config);
    return $factory;
  }

  private function service(array $settings = [], array $queue = []): GoogleAuthService {
    $handler = new MockHandler($queue);
    $stack = HandlerStack::create($handler);
    $stack->push(Middleware::history($this->sent));
    return new GoogleAuthService(
      $this->configFactory($settings),
      new Client(['handler' => $stack]),
      $this->createMock(LoggerChannelInterface::class),
    );
  }

  private static function configured(): array {
    return [
      'google_enabled' => TRUE,
      'google_client_id' => 'topstar.apps.googleusercontent.com',
      'google_client_secret' => 'GOCSPX-test',
    ];
  }

  private function request(string $country = '', string $header = 'CF-IPCountry'): Request {
    $request = Request::create('https://example.com/dx/auth/google_jump');
    if ($country !== '') {
      $request->headers->set($header, $country);
    }
    return $request;
  }

  /**
   * @covers ::isAvailable
   * @dataProvider availabilityProvider
   */
  public function testIsAvailable(array $settings, string $country, bool $expected): void {
    $svc = $this->service($settings);
    $this->assertSame($expected, $svc->isAvailable($this->request($country)));
  }

  /**
   * @return array<string, array{0: array<string, mixed>, 1: string, 2: bool}>
   */
  public static function availabilityProvider(): array {
    return [
      'configured, non-CN' => [self::configured(), 'US', TRUE],
      'configured, CN visitor hidden' => [self::configured(), 'CN', FALSE],
      'configured, unknown country hidden' => [self::configured(), 'XX', FALSE],
      'configured, no geo header at all' => [self::configured(), '', FALSE],
      'geo check bypassed for CN' => [array_merge(self::configured(), ['google_ignore_geo' => TRUE]), 'CN', TRUE],
      'geo check bypassed without header' => [array_merge(self::configured(), ['google_ignore_geo' => TRUE]), '', TRUE],
      'switch off' => [array_merge(self::configured(), ['google_enabled' => FALSE]), 'US', FALSE],
      'switch missing' => [[], 'US', FALSE],
      'client id blank' => [array_merge(self::configured(), ['google_client_id' => '  ']), 'US', FALSE],
      'client secret blank' => [array_merge(self::configured(), ['google_client_secret' => '']), 'US', FALSE],
    ];
  }

  /**
   * HK/MO/TW are not treated as mainland, and CF wins over the other headers.
   *
   * @covers ::isMainlandChina
   * @dataProvider countryProvider
   */
  public function testIsMainlandChina(string $cf, string $cloudfront, bool $expected): void {
    $svc = $this->service(self::configured());
    $request = Request::create('https://example.com/');
    if ($cf !== '') {
      $request->headers->set('CF-IPCountry', $cf);
    }
    if ($cloudfront !== '') {
      $request->headers->set('CloudFront-Viewer-Country', $cloudfront);
    }
    $this->assertSame($expected, $svc->isMainlandChina($request));
  }

  /**
   * @return array<string, array{0: string, 1: string, 2: bool}>
   */
  public static function countryProvider(): array {
    return [
      'CN' => ['CN', '', TRUE],
      'lowercase cn' => ['cn', '', TRUE],
      'padded CN' => [' CN ', '', TRUE],
      'HK' => ['HK', '', FALSE],
      'MO' => ['MO', '', FALSE],
      'TW' => ['TW', '', FALSE],
      'US' => ['US', '', FALSE],
      'XX unknown falls through to CloudFront' => ['XX', 'SG', FALSE],
      'T1 anonymised falls through' => ['T1', 'CN', TRUE],
      'garbage CF falls through' => ['China', 'CN', TRUE],
      'no header at all hides the button' => ['', '', TRUE],
      'only CloudFront CN' => ['', 'CN', TRUE],
      'only CloudFront DE' => ['', 'de', FALSE],
    ];
  }

  /**
   * @covers ::redirectUri
   */
  public function testRedirectUriDefaultsToTheJumpRoute(): void {
    $svc = $this->service(self::configured());
    $this->assertSame(
      'https://example.com/dx/auth/google_jump',
      $svc->redirectUri(Request::create('https://example.com/some/where'))
    );
  }

  /**
   * The Console-registered URI wins, and a trailing slash is trimmed because
   * Google compares the URI literally.
   *
   * @covers ::redirectUri
   */
  public function testRedirectUriOverrideIsTrimmed(): void {
    $svc = $this->service(self::configured() + ['google_redirect_uri' => ' https://auth.example.com/dx/auth/google_jump/ ']);
    $this->assertSame('https://auth.example.com/dx/auth/google_jump', $svc->redirectUri(Request::create('https://example.com/')));
  }

  /**
   * @covers ::buildAuthorizeUrl
   */
  public function testBuildAuthorizeUrl(): void {
    $svc = $this->service(self::configured());
    $url = $svc->buildAuthorizeUrl('https://example.com/dx/auth/google_jump', 'abc-123');

    $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
    $this->assertStringContainsString('client_id=topstar.apps.googleusercontent.com', $url);
    $this->assertStringContainsString('scope=openid%20email%20profile', $url, 'spaces must be %20, never +');
    $this->assertStringContainsString('response_type=code', $url);
    $this->assertStringContainsString('access_type=online', $url);
    $this->assertStringContainsString('prompt=select_account', $url);
    $this->assertStringContainsString('state=abc-123', $url);
    $this->assertStringContainsString('redirect_uri=https%3A%2F%2Fexample.com%2Fdx%2Fauth%2Fgoogle_jump', $url);
  }

  /**
   * @covers ::profileFromCode
   */
  public function testProfileFromCode(): void {
    $svc = $this->service(self::configured(), [
      new Response(200, [], '{"access_token":"ya29.AT","expires_in":3599}'),
      new Response(200, [], '{"sub":"109876543210","email":" A.B@Example.COM ","email_verified":true,"name":"  Ada B  "}'),
    ]);

    $profile = $svc->profileFromCode('code-1', Request::create('https://example.com/dx/auth/google_jump'));
    $this->assertSame([
      'sub' => '109876543210',
      'email' => 'a.b@example.com',
      'email_verified' => TRUE,
      'name' => 'Ada B',
    ], $profile);

    $body = (string) $this->sent[0]['request']->getBody();
    parse_str($body, $form);
    $this->assertSame('authorization_code', $form['grant_type']);
    $this->assertSame('code-1', $form['code']);
    $this->assertSame('https://example.com/dx/auth/google_jump', $form['redirect_uri'], 'the token exchange repeats the authorize redirect_uri');
    $this->assertSame('application/json', $this->sent[0]['request']->getHeaderLine('Accept'));
    $this->assertSame('Bearer ya29.AT', $this->sent[1]['request']->getHeaderLine('Authorization'));
  }

  /**
   * A missing email is tolerated today: the sub alone identifies the account.
   *
   * @covers ::profileFromCode
   */
  public function testProfileFromCodeWithoutEmail(): void {
    $svc = $this->service(self::configured(), [
      new Response(200, [], '{"access_token":"ya29.AT"}'),
      new Response(200, [], '{"sub":"1"}'),
    ]);
    $this->assertSame(
      ['sub' => '1', 'email' => '', 'email_verified' => FALSE, 'name' => ''],
      $svc->profileFromCode('code-1', Request::create('https://example.com/'))
    );
  }

  /**
   * @covers ::profileFromCode
   */
  public function testRevokedCodeSurfacesGooglesDescription(): void {
    $svc = $this->service(self::configured(), [
      new Response(400, [], '{"error":"invalid_grant","error_description":"Bad Request"}'),
    ]);
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Bad Request');
    $svc->profileFromCode('stale-code', Request::create('https://example.com/'));
  }

  /**
   * @covers ::profileFromCode
   */
  public function testTokenFailureWithoutDescription(): void {
    $svc = $this->service(self::configured(), [new Response(400, [], '{"error":"invalid_client"}')]);
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('invalid_client');
    $svc->profileFromCode('c', Request::create('https://example.com/'));
  }

  /**
   * @covers ::profileFromCode
   */
  public function testTokenFailureWithoutPayload(): void {
    $svc = $this->service(self::configured(), [new Response(502, [], '<html>bad gateway</html>')]);
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('token_exchange_failed');
    $svc->profileFromCode('c', Request::create('https://example.com/'));
  }

  /**
   * @covers ::profileFromCode
   */
  public function testUserInfoWithoutSubject(): void {
    $svc = $this->service(self::configured(), [
      new Response(200, [], '{"access_token":"ya29.AT"}'),
      new Response(401, [], '{"error":"invalid_token"}'),
    ]);
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('userinfo_failed');
    $svc->profileFromCode('c', Request::create('https://example.com/'));
  }

  /**
   * Current behaviour: profileFromCode() has no try/catch, so a socket failure
   * bubbles up and only the controller keeps the page alive.
   *
   * @covers ::profileFromCode
   */
  public function testTransportFailurePropagates(): void {
    $svc = $this->service(self::configured(), [new ConnectException('cURL error 28', new Psr7Request('POST', '/'))]);
    $this->expectException(ConnectException::class);
    $svc->profileFromCode('c', Request::create('https://example.com/'));
  }

}
