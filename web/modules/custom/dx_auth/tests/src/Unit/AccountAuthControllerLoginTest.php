<?php

declare(strict_types=1);

namespace Drupal\Tests\dx_auth\Unit;

use Drupal\Component\Utility\EmailValidatorInterface;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\dx_auth\Controller\AccountAuthController;
use Drupal\dx_auth\Service\LoginRegisterService;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use Drupal\user\UserStorageInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpFoundation\Request;

/**
 * R1 · 邮箱/账号通道：/dx/auth/account_login 的失败分支与 Topstar JSON 契约。
 *
 * 成功分支收尾于 user_login_finalize()，只能在内核级测试里跑，见
 * BindingsKernelTest 的说明；这里覆盖它之前的全部判定。
 *
 * @coversDefaultClass \Drupal\dx_auth\Controller\AccountAuthController
 * @group dx_auth
 */
class AccountAuthControllerLoginTest extends UnitTestCase {

  /**
   * @var array<int, string>
   */
  private array $floodEvents = [];

  protected function setUp(): void {
    parent::setUp();
    $this->floodEvents = [];
    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);
  }

  /**
   * The real service, with only its storage/config collaborators stubbed.
   *
   * @param array<string, \Drupal\user\UserInterface> $byMail
   * @param array<string, \Drupal\user\UserInterface> $byName
   */
  private function loginRegister(array $byMail = [], array $byName = [], bool $autoRegister = FALSE): LoginRegisterService {
    $storage = $this->createMock(UserStorageInterface::class);
    $storage->method('loadByProperties')->willReturnCallback(static function (array $props) use ($byMail, $byName) {
      if (isset($props['mail'])) {
        return isset($byMail[$props['mail']]) ? [$byMail[$props['mail']]] : [];
      }
      if (isset($props['name'])) {
        return isset($byName[$props['name']]) ? [$byName[$props['name']]] : [];
      }
      return [];
    });
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->with('user')->willReturn($storage);

    $validator = $this->createMock(EmailValidatorInterface::class);
    $validator->method('isValid')->willReturnCallback(static fn ($email) => str_contains((string) $email, '@'));

    return new LoginRegisterService(
      $etm,
      $validator,
      $this->getConfigFactoryStub(['dx_auth.settings' => ['account_auto_register' => $autoRegister]]),
      $this->createMock(LoggerChannelInterface::class),
    );
  }

  private function flood(bool $allowed = TRUE): MockObject {
    $flood = $this->createMock(FloodInterface::class);
    $flood->method('isAllowed')->willReturn($allowed);
    $flood->method('register')->willReturnCallback(function ($name, $window = 3600, $identifier = NULL) {
      $this->floodEvents[] = $name . '|' . $identifier;
    });
    return $flood;
  }

  private function csrf(bool $valid = TRUE): MockObject {
    $csrf = $this->createMock(CsrfTokenGenerator::class);
    $csrf->method('validate')->willReturn($valid);
    return $csrf;
  }

  private function controller(?LoginRegisterService $loginRegister = NULL, ?MockObject $flood = NULL, ?MockObject $csrf = NULL, ?MockObject $userAuth = NULL): AccountAuthController {
    return new AccountAuthController(
      $loginRegister ?? $this->loginRegister(),
      $flood ?? $this->flood(),
      $csrf ?? $this->csrf(),
      $userAuth ?? $this->createMock(\Drupal\user\UserAuthInterface::class),
    );
  }

  /**
   * @param array<string, mixed> $body
   */
  private function request(array $body = [], ?string $token = 'good'): Request {
    $request = Request::create('/dx/auth/account_login', 'POST', $body);
    if ($token !== NULL) {
      $request->headers->set('X-CSRF-Token', $token);
    }
    return $request;
  }

  /**
   * Decodes the Topstar payload a controller method returned.
   *
   * @return array<string, mixed>
   */
  private function payload(mixed $response): array {
    $this->assertInstanceOf(\Symfony\Component\HttpFoundation\JsonResponse::class, $response);
    $this->assertSame(200, $response->getStatusCode(), 'auth JSON always answers HTTP 200 and carries the verdict in code');
    return json_decode((string) $response->getContent(), TRUE);
  }

  /**
   * @covers ::login
   */
  public function testMissingCsrfHeaderIsRefusedBeforeFlood(): void {
    $flood = $this->flood();
    $flood->expects($this->never())->method('isAllowed');
    $result = $this->payload($this->controller(flood: $flood)->login($this->request(['name' => 'me@example.com', 'password' => 'whatever'], NULL)));
    $this->assertSame(0, $result['code']);
    $this->assertSame('安全校验失败，请刷新页面后重试', $result['msg']);
    $this->assertSame([], $result['data']);
    $this->assertArrayNotHasKey('redirect', $result, 'redirect is only present on a real login');
  }

  /**
   * @covers ::login
   */
  public function testInvalidCsrfTokenIsRefused(): void {
    $result = $this->payload($this->controller(csrf: $this->csrf(FALSE))->login($this->request(['name' => 'x', 'password' => 'y'])));
    $this->assertSame('安全校验失败，请刷新页面后重试', $result['msg']);
  }

  /**
   * @covers ::login
   */
  public function testIpThrottle(): void {
    $result = $this->payload($this->controller(flood: $this->flood(FALSE))->login($this->request(['name' => 'x', 'password' => 'y'])));
    $this->assertSame(0, $result['code']);
    $this->assertSame('尝试次数过多，请稍后再试', $result['msg']);
  }

  /**
   * @covers ::login
   */
  public function testBlankCredentials(): void {
    $flood = $this->flood();
    $flood->expects($this->never())->method('register');
    $result = $this->payload($this->controller(flood: $flood)->login($this->request(['name' => '   ', 'password' => ''])));
    $this->assertSame('请填写账号与密码。', $result['msg']);
    $this->assertSame([], $this->floodEvents, 'an empty form does not spend a failed attempt');
  }

  /**
   * @covers ::login
   * @covers ::passwordsMatch
   */
  public function testBlockedAccountKeepsTheSameWordingAsAWrongPassword(): void {
    $blocked = $this->createMock(UserInterface::class);
    $blocked->method('isBlocked')->willReturn(TRUE);
    $svc = $this->loginRegister(['me@example.com' => $blocked]);
    $result = $this->payload($this->controller(loginRegister: $svc)->login($this->request(['name' => 'me@example.com', 'password' => 'anything'])));
    $this->assertSame('该账号尚未激活或已被禁用。', $result['msg']);
    $this->assertSame(['dx_auth.account_login_ip|127.0.0.1'], $this->floodEvents);
  }

  /**
   * @covers ::login
   * @covers ::passwordsMatch
   */
  public function testWrongPassword(): void {
    $account = $this->createMock(UserInterface::class);
    $account->method('isBlocked')->willReturn(FALSE);
    $account->method('getAccountName')->willReturn('me');
    $auth = $this->createMock(\Drupal\user\UserAuthInterface::class);
    $auth->method('authenticate')->willReturn(FALSE);

    $svc = $this->loginRegister(['me@example.com' => $account]);
    $result = $this->payload($this->controller($svc, $this->flood(), $this->csrf(), $auth)->login($this->request(['name' => 'me@example.com', 'password' => 'nope'])));
    $this->assertSame('密码不正确，请重试。或通过「忘记密码」重置。', $result['msg']);
    $this->assertSame(['dx_auth.account_login_ip|127.0.0.1'], $this->floodEvents);
  }

  /**
   * A username that does not exist cannot auto-register: the copy tells the
   * visitor to use a valid email instead.
   *
   * @covers ::login
   */
  public function testUnknownUsernameIsNotRegistered(): void {
    $result = $this->payload($this->controller()->login($this->request(['name' => 'ghost', 'password' => 'longenough1'])));
    $this->assertSame('未检测到账号。新用户请使用有效的电子邮箱进行自动注册。', $result['msg']);
    $this->assertSame(['dx_auth.account_login_ip|127.0.0.1'], $this->floodEvents);
  }

  /**
   * Weak password on a brand-new email answers the password rule and, unlike
   * every other failure, does not register a flood event.
   *
   * @covers ::login
   */
  public function testWeakPasswordOnNewEmail(): void {
    $result = $this->payload($this->controller()->login($this->request(['name' => 'new@example.com', 'password' => '1234567'])));
    $this->assertSame(0, $result['code']);
    $this->assertStringContainsString('密码过短', $result['msg']);
    $this->assertSame([], $this->floodEvents);
  }

  /**
   * personal_registration_enabled=false is an ecosystem (app-store) switch;
   * this channel is gated by dx_auth.settings.account_auto_register, which
   * ships as TRUE. With it off, a fresh email is refused with admin copy.
   *
   * @covers ::login
   */
  public function testAutoRegisterSwitchedOff(): void {
    $svc = $this->loginRegister([], [], FALSE);
    $result = $this->payload($this->controller($svc)->login($this->request(['name' => 'fresh@example.com', 'password' => 'longenough1'])));
    $this->assertSame('新用户自动注册已关闭，请联系管理员。', $result['msg']);
    $this->assertSame(['dx_auth.account_login_ip|127.0.0.1'], $this->floodEvents);
  }

  /**
   * A mail hit is preferred; only a miss falls back to the username index.
   *
   * @covers ::login
   */
  public function testExistingAccountIsFoundByUsernameToo(): void {
    $account = $this->createMock(UserInterface::class);
    $account->method('isBlocked')->willReturn(FALSE);
    $account->method('getAccountName')->willReturn('me');
    $auth = $this->createMock(\Drupal\user\UserAuthInterface::class);
    $auth->method('authenticate')->willReturn(FALSE);

    $svc = $this->loginRegister([], ['me' => $account]);
    $result = $this->payload($this->controller($svc, $this->flood(), $this->csrf(), $auth)->login($this->request(['name' => 'me', 'password' => 'nope'])));
    $this->assertSame('密码不正确，请重试。或通过「忘记密码」重置。', $result['msg'], 'a username hit is treated as an existing account, not as a registration');
  }

  /**
   * @covers ::json
   */
  public function testJsonEnvelopeWithRedirect(): void {
    $controller = $this->controller();
    $method = new \ReflectionMethod(AccountAuthController::class, 'json');
    $method->setAccessible(TRUE);
    $result = $this->payload($method->invoke($controller, 1, 'ok', ['uid' => 9, 'created' => TRUE], '/portal'));
    $this->assertSame(1, $result['code']);
    $this->assertSame('ok', $result['msg']);
    $this->assertSame(['uid' => 9, 'created' => TRUE], $result['data']);
    $this->assertSame('/portal', $result['redirect']);
  }

}
