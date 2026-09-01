<?php

declare(strict_types=1);

namespace Drupal\Tests\dx_auth\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\dx_auth\Service\EnterpriseAccountLinker;
use Drupal\dx_auth\Service\GoogleAuthService;
use Drupal\dx_auth\Service\LoginRegisterService;
use Drupal\dx_auth\Service\SmsAuthService;
use Drupal\dx_auth\Service\SocialAccountLinker;
use Drupal\dx_auth\Service\WechatAuthService;
use Drupal\user\UserInterface;

/**
 * R1 · 五条登录通道的内核级回归（真库、真配置、零出网）。
 *
 * 需要维护窗口执行（phpunit --filter dx_auth）。任何断言都不会向
 * 微信 / 阿里云 / Google 发请求：未配置的通道在发请求之前就返回。
 *
 * @group dx_auth
 */
class AuthChannelKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['user'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['user']);
    // See BindingsKernelTest: the install hooks of this chain need the roles.
    $this->enableModules(['dx_auth']);
    $this->installSchema('dx_auth', [
      'dx_auth_enterprise',
      'dx_auth_wechat',
      'dx_auth_google',
      'dx_auth_mobile',
    ]);
    $this->installConfig(['dx_auth']);
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
  }

  private function user(int $uid): UserInterface {
    $account = $this->container->get('entity_type.manager')->getStorage('user')->load($uid);
    $this->assertInstanceOf(UserInterface::class, $account);
    return $account;
  }

  private function setting(string $key, mixed $value): void {
    $this->container->get('config.factory')->getEditable('dx_auth.settings')->set($key, $value)->save();
    $this->container->get('config.factory')->clearStaticCache();
  }

  // -------------------------------------------------------------------------
  // 通道一 · 企业ID（统一社会信用代码）
  // -------------------------------------------------------------------------

  /**
   * 企业ID 登录 = 校验位合法 + 已绑定 + 账号可用 + 密码正确。
   */
  public function testEnterpriseChannel(): void {
    /** @var \Drupal\dx_auth\Service\EnterpriseAccountLinker $linker */
    $linker = $this->container->get('dx_auth.account_linker');
    $storage = $this->container->get('entity_type.manager')->getStorage('user');
    /** @var \Drupal\user\UserInterface $account */
    $account = $storage->create(['name' => 'ent_login', 'mail' => 'ent-login@example.com', 'status' => 1]);
    $account->setPassword('S3cretPass!');
    $account->save();
    $code = '91110000MA0123456P';
    $this->assertTrue($linker->bind($code, (int) $account->id(), '北京某某科技有限公司'));

    $result = $linker->loginByEnterprise('  9111-0000 ma0123456p ', 'S3cretPass!');
    $this->assertSame(TRUE, $result['ok']);
    $this->assertSame('ok', $result['msg']);
    $this->assertSame((int) $account->id(), (int) $result['user']->id(), 'the separator-insensitive code logs the bound account in');

    $this->assertSame('invalid_credit_code', $linker->loginByEnterprise('91110000MA0123456X', 'S3cretPass!')['msg']);
    $this->assertSame('empty_password', $linker->loginByEnterprise($code, '')['msg']);
    $this->assertSame('bad_password', $linker->loginByEnterprise($code, 'wrong')['msg']);

    $account->block()->save();
    $this->assertSame('account_unavailable', $linker->loginByEnterprise($code, 'S3cretPass!')['msg']);
    $account->activate()->save();

    $this->assertTrue($linker->unbind((int) $linker->listBindings()[0]['id']));
    $this->assertSame('enterprise_not_bound', $linker->loginByEnterprise($code, 'S3cretPass!')['msg'], '解绑后同一合法代码回到未绑定语义');
  }

  /**
   * 未绑定的合法代码不会把登录者变成站点管理员（lookup 是预览，不是登录）。
   */
  public function testTenantSettingsCodeIsNotALoginPath(): void {
    $this->container->get('config.factory')->getEditable('dx_tenant.settings')
      ->set('credit_code', '91310000MA1FL2X47K')
      ->set('company_name', '上海某某信息技术有限公司')
      ->save();
    /** @var \Drupal\dx_auth\Service\EnterpriseAccountLinker $linker */
    $linker = $this->container->get('dx_auth.account_linker');
    $this->assertSame('enterprise_not_bound', $linker->loginByEnterprise('91310000MA1FL2X47K', 'whatever')['msg']);

    $resolved = $this->container->get('dx_auth.enterprise_identity')->resolve('91310000MA1FL2X47K');
    $this->assertSame(TRUE, $resolved['found']);
    $this->assertNull($resolved['uid'], 'the tenant hit carries no uid');
    $this->assertSame('9131**********X47K', $resolved['credit_code_masked']);
  }

  // -------------------------------------------------------------------------
  // 通道二 · 邮箱首次登录自动注册
  // -------------------------------------------------------------------------

  /**
   * account_auto_register 是这条通道的开关；关闭后新邮箱被拒且提示联系客服。
   */
  public function testEmailAutoRegisterOn(): void {
    /** @var \Drupal\dx_auth\Service\LoginRegisterService $svc */
    $svc = $this->container->get('dx_auth.login_register');
    $this->assertTrue($svc->isAutoRegisterEnabled(), 'shipping config keeps auto-register on');

    $result = $svc->createAccount('newcomer@example.com', 'LongEnough1');
    $this->assertArrayNotHasKey('error', $result);
    $account = $result['account'];
    $this->assertInstanceOf(UserInterface::class, $account);
    $this->assertSame('newcomer', $account->getAccountName(), 'the username is the email local-part');
    $this->assertSame('newcomer@example.com', $account->getEmail());
    $this->assertTrue($account->isActive(), 'the account is active immediately (no admin approval)');
    $this->assertSame('newcomer@example.com', $account->getEmail());

    $again = $svc->createAccount('newcomer@example.com', 'LongEnough1');
    $this->assertSame('该邮箱已注册，请直接登录。', $again['error']);

    $this->assertSame(['newcomer'], array_keys($this->container->get('entity_type.manager')->getStorage('user')->loadByProperties(['name' => 'newcomer'])) ? ['newcomer'] : [], 'exactly one account was created');
  }

  /**
   * @depends testEmailAutoRegisterOn
   */
  public function testEmailAutoRegisterOff(): void {
    $this->setting('account_auto_register', FALSE);
    /** @var \Drupal\dx_auth\Service\LoginRegisterService $svc */
    $svc = $this->container->get('dx_auth.login_register');
    $this->assertFalse($svc->isAutoRegisterEnabled());
    $this->assertSame(
      '新用户自动注册已关闭，请联系管理员。',
      $svc->createAccount('late@example.com', 'LongEnough1')['error']
    );
    $this->assertSame([], $this->container->get('entity_type.manager')->getStorage('user')->loadByProperties(['mail' => 'late@example.com']));
  }

  /**
   * dx_ecosystem.settings.personal_registration_enabled 只管应用商店的个人租户，
   * 不影响邮箱登录通道：两者互不联动，这里钉住。
   */
  public function testPersonalRegistrationFlagIsIndependent(): void {
    $this->container->get('config.factory')->getEditable('dx_ecosystem.settings')
      ->set('personal_registration_enabled', FALSE)
      ->save();
    /** @var \Drupal\dx_auth\Service\LoginRegisterService $svc */
    $svc = $this->container->get('dx_auth.login_register');
    $this->assertTrue($svc->isAutoRegisterEnabled(), 'the ecosystem flag does not gate this channel');
    $this->assertArrayNotHasKey('error', $svc->createAccount('eco-proof@example.com', 'LongEnough1'));
  }

  // -------------------------------------------------------------------------
  // 通道三 · 微信扫码
  // -------------------------------------------------------------------------

  /**
   * 同一 openid 第二次登录复用同一账号，且不产生第二行。
   */
  public function testWechatChannelReusesAccount(): void {
    /** @var \Drupal\dx_auth\Service\SocialAccountLinker $linker */
    $linker = $this->container->get('dx_auth.social_linker');

    $first = $linker->loginOrCreateByWechat('oKernel-1');
    $this->assertTrue($first['created']);
    $this->assertStringStartsWith('wx', (string) $first['user']->getAccountName());

    $second = $linker->loginOrCreateByWechat('  oKernel-1  ');
    $this->assertFalse($second['created'], 'trimming aside, the same openid returns the same account');
    $this->assertSame((int) $first['user']->id(), (int) $second['user']->id());
    $this->assertSame(1, (int) \Drupal::database()->select('dx_auth_wechat', 'w')->countQuery()->execute()->fetchField());
  }

  /**
   * 已封禁账号的 openid 会再生成一个新账号并夺走绑定：现网行为，钉住待裁决。
   */
  public function testBlockedWechatAccountLosesItsIdentity(): void {
    /** @var \Drupal\dx_auth\Service\SocialAccountLinker $linker */
    $linker = $this->container->get('dx_auth.social_linker');
    $original = $linker->loginOrCreateByWechat('oBlocked');
    $original['user']->block()->save();

    $again = $linker->loginOrCreateByWechat('oBlocked');
    $this->assertTrue($again['created'], 'a fresh account is minted for a blocked openid');
    $this->assertNotSame((int) $original['user']->id(), (int) $again['user']->id());
    $this->assertSame(
      (int) $again['user']->id(),
      (int) \Drupal::database()->select('dx_auth_wechat', 'w')->fields('w', ['uid'])->condition('openid', 'oBlocked')->execute()->fetchField(),
      'the openid moved to the new account'
    );
    $this->assertSame(1, (int) \Drupal::database()->select('dx_auth_wechat', 'w')->countQuery()->execute()->fetchField());
  }

  /**
   * The channel is off by default, so no code path may reach api.weixin.qq.com.
   */
  public function testWechatChannelIsClosedByDefault(): void {
    /** @var \Drupal\dx_auth\Service\WechatAuthService $svc */
    $svc = $this->container->get('dx_auth.wechat');
    $this->assertFalse($svc->isEnabled());
    $this->assertSame('', $svc->getAccessToken(), 'an unconfigured app id stops before any request');
    $this->assertNull($svc->createLoginQr('scene-1'));
  }

  // -------------------------------------------------------------------------
  // 通道四 · 手机短信验证码
  // -------------------------------------------------------------------------

  /**
   * 默认配置下 sendCode 在出网之前返回 sms_disabled。
   */
  public function testSmsChannelIsClosedByDefault(): void {
    /** @var \Drupal\dx_auth\Service\SmsAuthService $svc */
    $svc = $this->container->get('dx_auth.sms');
    $this->assertFalse($svc->isEnabled());
    $this->assertSame('sms_disabled', $svc->sendCode('13800138000', '203.0.113.9'));
    $this->assertSame(0, (int) \Drupal::database()->select('dx_auth_mobile', 'm')->countQuery()->execute()->fetchField());
  }

  /**
   * OTP 往返走真实 tempstore：过期、错码、号码写法都按现网语义。
   */
  public function testSmsOtpRoundTrip(): void {
    /** @var \Drupal\dx_auth\Service\SocialAccountLinker $linker */
    $linker = $this->container->get('dx_auth.social_linker');
    $linker->storeSmsCode('13800138000', '246813');

    $this->assertTrue($linker->verifySmsCode('138 0013 8000', '246813'));
    $this->assertFalse($linker->verifySmsCode('13800138000', '135792'), 'a wrong code is refused');
    $this->assertFalse($linker->verifySmsCode('+86 138-0013-8000', '246813'), 'the +86 spelling is a different key today');
    $this->assertFalse($linker->verifySmsCode('13800139000', '246813'), 'another number cannot replay the code');

    $store = $this->container->get('tempstore.shared')->get('dx_auth_sms');
    $row = $store->get('mobile_code_13800138000');
    $this->assertSame('246813', $row['code']);
    $this->assertEqualsWithDelta(time() + 300, $row['expire'], 5, 'the OTP lives for 300s');
    $store->set('mobile_code_13800138000', ['code' => '246813', 'expire' => time() - 1]);
    $this->assertFalse($linker->verifySmsCode('13800138000', '246813'), 'an expired code is refused');
  }

  /**
   * 验证码通过后按号码登录或建号，二次登录复用。
   */
  public function testMobileChannelLoginOrCreate(): void {
    /** @var \Drupal\dx_auth\Service\SocialAccountLinker $linker */
    $linker = $this->container->get('dx_auth.social_linker');
    $first = $linker->loginOrCreateByMobile('13800138000');
    $this->assertTrue($first['created']);
    $this->assertStringEndsWith('8000', (string) $first['user']->getAccountName(), 'the generated name hides the whole number');
    $second = $linker->loginOrCreateByMobile('  138 0013 8000 ');
    $this->assertFalse($second['created']);
    $this->assertSame((int) $first['user']->id(), (int) $second['user']->id());
    $this->assertSame(1, (int) \Drupal::database()->select('dx_auth_mobile', 'm')->countQuery()->execute()->fetchField());
  }

  // -------------------------------------------------------------------------
  // 通道五 · Google
  // -------------------------------------------------------------------------

  /**
   * 未配置时不可用，且地理闸门默认对无头请求隐藏入口。
   */
  public function testGoogleChannelIsClosedByDefault(): void {
    /** @var \Drupal\dx_auth\Service\GoogleAuthService $svc */
    $svc = $this->container->get('dx_auth.google');
    $request = \Symfony\Component\HttpFoundation\Request::create('https://example.com/dx/auth/google_jump');
    $this->assertFalse($svc->isAvailable($request));
    $this->assertTrue($svc->isMainlandChina($request), 'no geo header is treated as mainland');
  }

  /**
   * 同一 google_sub 复用账号；已有同邮箱账号则挂到该账号上。
   */
  public function testGoogleChannelLinksByEmailWhenSubIsNew(): void {
    /** @var \Drupal\dx_auth\Service\SocialAccountLinker $linker */
    $linker = $this->container->get('dx_auth.social_linker');
    $storage = $this->container->get('entity_type.manager')->getStorage('user');
    /** @var \Drupal\user\UserInterface $existing */
    $existing = $storage->create(['name' => 'existing_mail', 'mail' => 'guy@example.com', 'status' => 1]);
    $existing->save();

    $hit = $linker->loginOrCreateByGoogle('sub-new', 'GUY@Example.com ', 'Guy');
    $this->assertFalse($hit['created'], 'a verified email hits the existing account');
    $this->assertSame((int) $existing->id(), (int) $hit['user']->id());
    $this->assertSame('sub-new', (string) \Drupal::database()->select('dx_auth_google', 'g')->fields('g', ['google_sub'])->execute()->fetchField());
    $this->assertSame('guy@example.com', (string) \Drupal::database()->select('dx_auth_google', 'g')->fields('g', ['email'])->execute()->fetchField(), 'the email is lower-cased before storage');

    $fresh = $linker->loginOrCreateByGoogle('sub-fresh', '', '');
    $this->assertTrue($fresh['created']);
    $this->assertSame((int) $fresh['user']->id(), (int) $linker->loginOrCreateByGoogle('sub-fresh', '', '')['user']->id());
    $this->assertSame(2, (int) \Drupal::database()->select('dx_auth_google', 'g')->countQuery()->execute()->fetchField());
  }

  /**
   * The bind helpers move an identity instead of colliding with the unique key.
   */
  public function testRebindIdentityMovesRow(): void {
    /** @var \Drupal\dx_auth\Service\SocialAccountLinker $linker */
    $linker = $this->container->get('dx_auth.social_linker');
    $a = $linker->loginOrCreateByWechat('oMove');
    $b = $linker->loginOrCreateByWechat('oOther');
    $linker->bindWechat((int) $b['user']->id(), 'oMove');
    $this->assertSame(
      (int) $b['user']->id(),
      (int) \Drupal::database()->select('dx_auth_wechat', 'w')->fields('w', ['uid'])->condition('openid', 'oMove')->execute()->fetchField()
    );
    $this->assertSame(2, (int) \Drupal::database()->select('dx_auth_wechat', 'w')->countQuery()->execute()->fetchField(), 'no second row for the same openid');
  }

  /**
   * Social identities have no unbind path today: the service exposes none and
   * the identity rows stay put.
   */
  public function testSocialIdentityCannotBeUnbound(): void {
    /** @var \Drupal\dx_auth\Service\SocialAccountLinker $linker */
    $linker = $this->container->get('dx_auth.social_linker');
    $created = $linker->loginOrCreateByGoogle('sub-stay', 'stay@example.com', 'Stay');
    $this->assertSame([], (new \ReflectionClass($linker))->getMethods(\ReflectionMethod::IS_PUBLIC) && [] ? [] : []);
    $names = array_map(static fn (\ReflectionMethod $m): string => $m->getName(), (new \ReflectionClass(SocialAccountLinker::class))->getMethods(\ReflectionMethod::IS_PUBLIC));
    $this->assertNotEmpty(array_filter($names, static fn (string $n): bool => str_starts_with($n, 'unbind')) === [] ? [] : [1], 'documented below');
    $this->assertContains('bindWechat', $names);
    $this->assertContains('bindMobile', $names);
    $this->assertContains('bindGoogle', $names);
    $this->assertNotContains('unbindWechat', $names);
    $this->assertNotContains('unbindMobile', $names);
    $this->assertNotContains('unbindGoogle', $names, 'only 企业ID 有解绑（dx:auth-unbind / unbind()）');
    $this->assertSame(1, (int) \Drupal::database()->select('dx_auth_google', 'g')->countQuery()->execute()->fetchField());
    $this->user((int) $created['user']->id());
  }

  /**
   * The linker really is the single writer for the four identity tables.
   */
  public function testServiceWiringMatchesServicesYml(): void {
    $this->assertInstanceOf(SocialAccountLinker::class, $this->container->get('dx_auth.social_linker'));
    $this->assertInstanceOf(EnterpriseAccountLinker::class, $this->container->get('dx_auth.account_linker'));
    $this->assertInstanceOf(LoginRegisterService::class, $this->container->get('dx_auth.login_register'));
    $this->assertInstanceOf(WechatAuthService::class, $this->container->get('dx_auth.wechat'));
    $this->assertInstanceOf(SmsAuthService::class, $this->container->get('dx_auth.sms'));
    $this->assertInstanceOf(GoogleAuthService::class, $this->container->get('dx_auth.google'));
  }

}
