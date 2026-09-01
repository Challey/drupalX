<?php

declare(strict_types=1);

namespace Drupal\Tests\dx_auth\Kernel;

use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\dx_auth\Controller\BindingsController;
use Drupal\dx_auth\Service\EnterpriseAccountLinker;
use Drupal\dx_auth\Service\SocialAccountLinker;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * R2 · /dx/auth/bindings 边界用例：真库、真访问控制、真归并。
 *
 * 需要维护窗口执行（phpunit --filter dx_auth）：本类会建表、建用户、写身份表，
 * 只在 phpunit 的内核沙箱里跑，绝不在生产站点上跑。
 *
 * @group dx_auth
 */
class BindingsKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['user'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  protected SocialAccountLinker $social;

  protected EnterpriseAccountLinker $enterprise;

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['user']);

    // dx_auth (and its dx_tenant / dx_ai_gateway chain) is enabled only after
    // the user roles exist, because dx_auth_install() grants a permission to
    // the administrator role and dx_ai_gateway_install() does the same.
    $this->enableModules(['dx_auth']);
    $this->installSchema('dx_auth', [
      'dx_auth_enterprise',
      'dx_auth_wechat',
      'dx_auth_google',
      'dx_auth_mobile',
    ]);
    $this->installConfig(['dx_auth']);

    $this->social = $this->container->get('dx_auth.social_linker');
    $this->enterprise = $this->container->get('dx_auth.account_linker');
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
  }

  /**
   * Creates and saves a real user entity.
   */
  private function createUser(string $name, ?string $mail = NULL): UserInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('user');
    /** @var \Drupal\user\UserInterface $account */
    $account = $storage->create(['name' => $name, 'mail' => $mail, 'status' => 1]);
    $account->setPassword('Passw0rd!x');
    $account->save();
    $storage->resetCache();
    return $account;
  }

  private function mobileRows(?int $uid = NULL): int {
    $query = \Drupal::database()->select('dx_auth_mobile', 'm');
    if ($uid !== NULL) {
      $query->condition('uid', $uid);
    }
    return (int) $query->countQuery()->execute()->fetchField();
  }

  /**
   * Pushes a request so the access checkers can look at method + headers.
   */
  private function withRequest(string $path, string $method, bool $csrf, callable $callback): mixed {
    $request = Request::create($path, $method);
    if ($csrf) {
      $request->headers->set('X-CSRF-Token', (string) $this->container->get('csrf_token')->get(CsrfRequestHeaderAccessCheck::TOKEN_KEY));
    }
    $this->container->get('request_stack')->push($request);
    try {
      return $callback();
    }
    finally {
      $this->container->get('request_stack')->pop();
    }
  }

  // -------------------------------------------------------------------------
  // 未授权访问
  // -------------------------------------------------------------------------

  /**
   * The bind page and its JSON actions are login-only; the login endpoints
   * themselves must stay reachable.
   *
   * @dataProvider anonymousAccessProvider
   */
  public function testAnonymousAccess(string $routeName, string $path, string $method, bool $csrf, bool $allowed): void {
    $access = $this->withRequest($path, $method, $csrf, fn () => $this->container->get('access_manager')->checkNamedRoute($routeName, [], new AnonymousUserSession()));
    $this->assertSame($allowed, $access, $routeName . ' as anonymous');
  }

  /**
   * @return array<string, array{0: string, 1: string, 2: string, 3: bool, 4: bool}>
   */
  public static function anonymousAccessProvider(): array {
    return [
      'bind page' => ['dx_auth.bindings', '/dx/auth/bindings', 'GET', FALSE, FALSE],
      'bind status' => ['dx_auth.bindings_status', '/dx/auth/bindings/status', 'GET', FALSE, FALSE],
      'bind mobile' => ['dx_auth.bind_mobile', '/dx/auth/bind_mobile', 'POST', FALSE, FALSE],
      'bind mobile with csrf' => ['dx_auth.bind_mobile', '/dx/auth/bind_mobile', 'POST', TRUE, FALSE],
      'claim account' => ['dx_auth.claim_account', '/dx/auth/claim_account', 'POST', TRUE, FALSE],
      'admin bindings' => ['dx_auth.admin_bindings', '/admin/dx/auth/enterprise', 'GET', FALSE, FALSE],
      // Public by design — these are what the anonymous login page calls.
      'account login' => ['dx_auth.account_login', '/dx/auth/account_login', 'POST', TRUE, TRUE],
      'enterprise login' => ['dx_auth.enterprise_login', '/dx/auth/enterprise_login', 'POST', TRUE, TRUE],
      'enterprise lookup' => ['dx_auth.enterprise_lookup', '/dx/auth/enterprise_lookup', 'GET', FALSE, TRUE],
      'sms send' => ['dx_auth.sms_send', '/dx/auth/sms_send', 'GET', FALSE, TRUE],
      'wechat qrcode' => ['dx_auth.wechat_qrcode', '/dx/auth/wechat_qrcode', 'GET', FALSE, TRUE],
      'google jump' => ['dx_auth.google_jump', '/dx/auth/google_jump', 'GET', FALSE, TRUE],
    ];
  }

  /**
   * Logging in clears the login gate but not the CSRF gate: they are two
   * independent requirements on the same route.
   */
  public function testLoggedInUserStillNeedsCsrfHeaderForWrites(): void {
    $account = $this->createUser('bind_owner', 'bind@example.com');
    $this->container->get('current_user')->setAccount($account);

    $this->assertTrue($this->withRequest('/dx/auth/bindings', 'GET', FALSE, fn () => $this->container->get('access_manager')->checkNamedRoute('dx_auth.bindings', [], $account)));
    $this->assertFalse($this->withRequest('/dx/auth/bind_mobile', 'POST', FALSE, fn () => $this->container->get('access_manager')->checkNamedRoute('dx_auth.bind_mobile', [], $account)));
    $this->assertTrue($this->withRequest('/dx/auth/bind_mobile', 'POST', TRUE, fn () => $this->container->get('access_manager')->checkNamedRoute('dx_auth.bind_mobile', [], $account)));
    $this->assertFalse($this->withRequest('/admin/dx/auth/enterprise', 'GET', FALSE, fn () => $this->container->get('access_manager')->checkNamedRoute('dx_auth.admin_bindings', [], $account)), 'a plain authenticated user may not manage bindings');

    $admin = $this->createUser('root_admin', 'root@example.com');
    $admin->addRole('administrator');
    $admin->save();
    $this->assertTrue($this->withRequest('/admin/dx/auth/enterprise', 'GET', FALSE, fn () => $this->container->get('access_manager')->checkNamedRoute('dx_auth.admin_bindings', [], $admin)));
  }

  /**
   * The controller keeps its own guard, so a route misconfiguration alone can
   * not leak binding data.
   */
  public function testControllerRefusesAnonymous(): void {
    $controller = BindingsController::create($this->container);

    $status = json_decode((string) $controller->status()->getContent(), TRUE);
    $this->assertSame(0, $status['code']);
    $this->assertSame('请先登录', $status['msg']);

    $bind = json_decode((string) $controller->bindMobile(Request::create('/dx/auth/bind_mobile', 'POST', [
      'mobile' => '13800138000',
      'code' => '123456',
    ]))->getContent(), TRUE);
    $this->assertSame(0, $bind['code']);
    $this->assertSame('请先登录', $bind['msg']);

    $claim = json_decode((string) $controller->claimAccount(Request::create('/dx/auth/claim_account', 'POST', [
      'login' => 'someone',
      'password' => 'whatever',
    ]))->getContent(), TRUE);
    $this->assertSame(0, $claim['code']);
    $this->assertSame('请先登录', $claim['msg']);
    $this->assertSame(0, $this->mobileRows(), 'no identity row was written for an anonymous visitor');
  }

  /**
   * A bad OTP stops before the writer.
   */
  public function testBindMobileRejectsBadOtp(): void {
    $account = $this->createUser('otp_owner', 'otp@example.com');
    $this->container->get('current_user')->setAccount($account);
    $controller = BindingsController::create($this->container);

    $response = json_decode((string) $controller->bindMobile(Request::create('/dx/auth/bind_mobile', 'POST', [
      'mobile' => '13800138000',
      'code' => '000000',
    ]))->getContent(), TRUE);
    $this->assertSame(0, $response['code']);
    $this->assertSame('验证码错误或已过期', $response['msg']);
    $this->assertSame(0, $this->mobileRows());
  }

  // -------------------------------------------------------------------------
  // 重复绑定
  // -------------------------------------------------------------------------

  /**
   * The same number written the same way binds once and then answers
   * already_bound; a '+86' spelling is a different key today and adds a row.
   */
  public function testRepeatMobileBind(): void {
    $account = $this->createUser('repeat_owner', 'repeat@example.com');

    $this->assertSame(
      ['ok' => TRUE, 'msg' => 'bound'],
      $this->social->bindMobileToUser($account, '138 0013 8000')
    );
    $this->assertSame(1, $this->mobileRows((int) $account->id()));

    $this->assertSame(
      ['ok' => TRUE, 'msg' => 'already_bound'],
      $this->social->bindMobileToUser($account, '  138-0013-8000  ')
    );
    $this->assertSame(1, $this->mobileRows(), 're-binding the same normalized number adds nothing');

    $this->assertSame(
      ['ok' => TRUE, 'msg' => 'bound'],
      $this->social->bindMobileToUser($account, '+86 138-0013-8000')
    );
    $this->assertSame(2, $this->mobileRows(), 'the country-coded spelling is stored as a second row (present-day behaviour)');
    $this->assertSame(2, $this->mobileRows((int) $account->id()), 'both belong to the same account, so no account is lost');
  }

  /**
   * A number that lives on another account merges that account into the
   * requesting one and never duplicates the identity row.
   */
  public function testNumberOnAnotherAccountMergesIt(): void {
    $alice = $this->createUser('alice', 'alice@example.com');
    $bob = $this->createUser('bob', 'bob@example.com');

    $this->social->bindMobileToUser($alice, '13900139000');
    $result = $this->social->bindMobileToUser($bob, '13900139000');

    $this->assertSame(['ok' => TRUE, 'msg' => 'bound'], $result, 'the caller only sees 手机绑定成功; the merge is silent today');
    $this->assertSame(1, $this->mobileRows(), 'still exactly one row for the number');
    $uid = (int) \Drupal::database()->select('dx_auth_mobile', 'm')->fields('m', ['uid'])->execute()->fetchField();
    $this->assertSame((int) $bob->id(), $uid, 'the row now belongs to the requesting account');

    $orphan = $this->container->get('entity_type.manager')->getStorage('user')->loadUnchanged((int) $alice->id());
    $this->assertTrue($orphan->isBlocked(), 'the previous owner is blocked');
  }

  // -------------------------------------------------------------------------
  // 同标识冲突归并
  // -------------------------------------------------------------------------

  /**
   * uid 1 is never a merge target.
   */
  public function testUidOneCannotBeMergedAway(): void {
    $admin = $this->createUser('root', 'root@example.com');
    $this->assertSame(1, (int) $admin->id(), 'the first account in a kernel install is uid 1');
    $this->social->bindMobile(1, '13700137000');

    $intruder = $this->createUser('intruder', 'intruder@example.com');
    $this->assertSame(
      ['ok' => FALSE, 'msg' => 'protected_account'],
      $this->social->bindMobileToUser($intruder, '13700137000')
    );
    $this->assertSame(1, $this->mobileRows(1), 'the admin row is untouched');
    $this->assertSame(0, $this->mobileRows((int) $intruder->id()));
    $reloaded = $this->container->get('entity_type.manager')->getStorage('user')->loadUnchanged(1);
    $this->assertTrue($reloaded->isActive(), 'the admin account was not blocked');
  }

  /**
   * Two different numbers on both sides is a hard conflict.
   */
  public function testConflictingNumbersBlockTheMerge(): void {
    $a = $this->createUser('conflict_a', 'a@example.com');
    $b = $this->createUser('conflict_b', 'b@example.com');
    $this->social->bindMobile((int) $a->id(), '13600136000');
    $this->social->bindMobile((int) $b->id(), '13500135000');

    $this->assertSame(
      ['ok' => FALSE, 'msg' => 'mobile_conflict'],
      $this->social->mergeUsers($a, $b)
    );
    $this->assertTrue($this->container->get('entity_type.manager')->getStorage('user')->loadUnchanged((int) $b->id())->isActive());
    $this->assertSame(2, $this->mobileRows(), 'nothing moved');
  }

  /**
   * The same number on both accounts merges and moves the social identities.
   */
  public function testMergeReassignsSocialIdentities(): void {
    $primary = $this->createUser('primary', 'primary@example.com');
    $orphan = $this->createUser('orphan', 'orphan@example.com');
    $this->social->bindMobile((int) $orphan->id(), '13400134000');
    $this->social->bindWechat((int) $orphan->id(), 'oShared');
    $this->social->bindGoogle((int) $orphan->id(), 'gShared', 'orphan@example.com');

    $result = $this->social->mergeUsers($primary, $orphan);
    $this->assertSame(['ok' => TRUE, 'msg' => 'merged', 'merged_uid' => (int) $orphan->id()], $result);

    $db = \Drupal::database();
    $this->assertSame((int) $primary->id(), (int) $db->select('dx_auth_mobile', 'm')->fields('m', ['uid'])->execute()->fetchField(), 'the number moves to the survivor');
    $this->assertSame((int) $primary->id(), (int) $db->select('dx_auth_wechat', 'w')->fields('w', ['uid'])->condition('openid', 'oShared')->execute()->fetchField());
    $this->assertSame((int) $primary->id(), (int) $db->select('dx_auth_google', 'g')->fields('g', ['uid'])->condition('google_sub', 'gShared')->execute()->fetchField());
    $this->assertSame(1, (int) $db->select('dx_auth_wechat', 'w')->countQuery()->execute()->fetchField(), 'no duplicate identity rows are created');

    $reloaded = $this->container->get('entity_type.manager')->getStorage('user')->loadUnchanged((int) $orphan->id());
    $this->assertTrue($reloaded->isBlocked(), 'the orphan is blocked');
    $this->assertStringStartsWith('merged_' . $orphan->id() . '_', (string) $reloaded->getAccountName(), 'and renamed, releasing the username');
  }

  /**
   * After a merge the survivor answers the merged status payload in one call.
   */
  public function testStatusPayloadAfterMerge(): void {
    $primary = $this->createUser('survivor', 'survivor@example.com');
    $orphan = $this->createUser('gone', 'gone@example.com');
    $this->social->bindMobile((int) $orphan->id(), '13300133000');
    $this->social->mergeUsers($primary, $orphan);
    $this->social->bindWechat((int) $primary->id(), 'oMine');
    $this->enterprise->bind('91110000MA0123456P', (int) $primary->id(), '北京某某科技有限公司');

    $this->container->get('current_user')->setAccount($primary);
    $payload = json_decode((string) BindingsController::create($this->container)->status()->getContent(), TRUE);
    $this->assertSame(1, $payload['code']);
    $this->assertSame(['name' => 'survivor', 'mail' => 'survivor@example.com'], $payload['data']['account']);
    $this->assertSame(['bound' => TRUE, 'value' => '133****3000'], $payload['data']['mobile']);
    $this->assertSame(['bound' => TRUE], $payload['data']['wechat']);
    $this->assertSame([['credit_code' => '91110000MA0123456P', 'company_name' => '北京某某科技有限公司']], $payload['data']['enterprise']);
    $this->assertStringNotContainsString('13300133000', (string) json_encode($payload));
  }

  // -------------------------------------------------------------------------
  // 解绑
  // -------------------------------------------------------------------------

  /**
   * 企业ID 是唯一可解绑的通道；社交身份没有解绑接口。
   */
  public function testEnterpriseBindAndUnbind(): void {
    $account = $this->createUser('ent_owner', 'ent@example.com');
    $code = '91110000MA0123456P';

    $this->assertTrue($this->enterprise->bind($code, (int) $account->id(), '北京某某科技有限公司'));
    $this->assertSame([['credit_code' => $code, 'company_name' => '北京某某科技有限公司']], $this->enterprise->creditCodesForUid((int) $account->id()));

    $rows = $this->enterprise->listBindings();
    $this->assertCount(1, $rows);
    $id = (int) $rows[0]['id'];

    $this->assertFalse($this->enterprise->unbind(0), 'id 0 is refused before any query');
    $this->assertFalse($this->enterprise->unbind(-5));
    $this->assertFalse($this->enterprise->unbind(9999), 'an unknown id reports failure');
    $this->assertTrue($this->enterprise->unbind($id));
    $this->assertSame([], $this->enterprise->creditCodesForUid((int) $account->id()));
    $this->assertSame(0, (int) \Drupal::database()->select('dx_auth_enterprise', 'e')->countQuery()->execute()->fetchField());
    $this->assertFalse($this->social->statusForUid((int) $account->id())['wechat'], 'unbinding one channel leaves the others readable');
  }

  /**
   * Re-binding a credit code to another account silently moves it: an upsert,
   * not a conflict rejection. Pinned as present-day behaviour.
   */
  public function testEnterpriseRebindMovesTheBinding(): void {
    $first = $this->createUser('ent_a', 'enta@example.com');
    $second = $this->createUser('ent_b', 'entb@example.com');
    $code = '91310000MA1FL2X47K';

    $this->assertTrue($this->enterprise->bind($code, (int) $first->id(), '甲公司'));
    $this->assertTrue($this->enterprise->bind($code, (int) $second->id(), '乙公司'));
    $this->assertSame([], $this->enterprise->creditCodesForUid((int) $first->id()));
    $this->assertSame($code, $this->enterprise->creditCodesForUid((int) $second->id())[0]['credit_code']);
    $this->assertSame(1, (int) \Drupal::database()->select('dx_auth_enterprise', 'e')->countQuery()->execute()->fetchField());
  }

  /**
   * One account may own several credit codes (uid is indexed, not unique).
   */
  public function testOneAccountCanHoldSeveralCreditCodes(): void {
    $account = $this->createUser('multi', 'multi@example.com');
    $this->assertTrue($this->enterprise->bind('91110000MA0123456P', (int) $account->id(), '甲公司'));
    $this->assertTrue($this->enterprise->bind('91330100MA2GHTJ1KK', (int) $account->id(), '乙公司'));
    $codes = array_column($this->enterprise->creditCodesForUid((int) $account->id()), 'credit_code');
    $this->assertCount(2, $codes);
    $this->assertContains('91110000MA0123456P', $codes);
    $this->assertContains('91330100MA2GHTJ1KK', $codes);
  }

  /**
   * An invalid checksum can never be bound.
   */
  public function testInvalidCreditCodeCannotBeBound(): void {
    $account = $this->createUser('ent_c', 'entc@example.com');
    $this->assertFalse($this->enterprise->bind('91110000MA0123456X', (int) $account->id(), '坏公司'));
    $this->assertFalse($this->enterprise->bind('91110000MA0123456P', 0, '无主'), 'uid 0 is refused');
    $this->assertFalse($this->enterprise->bind('', (int) $account->id(), '空'));
    $this->assertSame(0, (int) \Drupal::database()->select('dx_auth_enterprise', 'e')->countQuery()->execute()->fetchField());
  }

  /**
   * The unique keys really are enforced by the database, which is what keeps a
   * repeat bind from creating a second owner for one identity.
   */
  public function testIdentityTablesRejectDuplicateKeys(): void {
    $account = $this->createUser('unique_owner', 'unique@example.com');
    $db = \Drupal::database();
    $fields = ['uid' => (int) $account->id(), 'created' => time()];

    $db->insert('dx_auth_wechat')->fields(['openid' => 'oDup'] + $fields)->execute();
    $this->expectException(\Drupal\Core\Database\IntegrityConstraintViolationException::class);
    $db->insert('dx_auth_wechat')->fields(['openid' => 'oDup'] + $fields)->execute();
  }

  /**
   * The render array of the bind page carries only masked identifiers.
   */
  public function testBindingsPageExposesMaskedValuesOnly(): void {
    $account = $this->createUser('masked_owner', 'masked@example.com');
    $this->container->get('current_user')->setAccount($account);
    $this->social->bindMobile((int) $account->id(), '13800138000');
    $this->enterprise->bind('92440300MA5F1AB7Q4', (int) $account->id(), '深圳市某某科技有限公司');

    $build = $this->withRequest('/dx/auth/bindings', 'GET', FALSE, fn () => BindingsController::create($this->container)->page());

    $this->assertSame('138****8000', $build['#mobile_masked']);
    $this->assertSame(TRUE, $build['#mobile']);
    $this->assertCount(1, $build['#enterprise']);
    // The owner of the page sees their own binding in full (the twig prints
    // company_name + credit_code); only third-party-facing lookups mask.
    $this->assertSame('深圳市某某科技有限公司', $build['#enterprise'][0]['company_name']);
    $this->assertSame('92440300MA5F1AB7Q4', $build['#enterprise'][0]['credit_code']);
    $encoded = (string) json_encode($build);
    $this->assertStringNotContainsString('13800138000', $encoded, 'the raw mobile number never reaches the render array');
  }

}
