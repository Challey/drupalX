<?php

declare(strict_types=1);

namespace Drupal\Tests\dx_auth\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Delete;
use Drupal\Core\Database\Query\Insert;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\Query\Update;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\TempStore\SharedTempStoreFactory;
use Drupal\dx_auth\Service\SocialAccountLinker;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserAuthInterface;
use Drupal\user\UserInterface;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * R2 · /dx/auth/bindings 边界用例（重复绑定 / 冲突归并 / 归属校验）。
 *
 * 全部在 unit 层用查询桩驱动：断言的是"读什么、写不写、返回哪个 msg 键"，
 * 真正落库的归并效果由 BindingsKernelTest 覆盖（需站点/DB）。
 *
 * @coversDefaultClass \Drupal\dx_auth\Service\SocialAccountLinker
 * @group dx_auth
 */
class SocialAccountLinkerBindingTest extends UnitTestCase {

  /**
   * Builds a select chain stub: ->fields()->condition()->range()->execute().
   */
  private function select(mixed $field = FALSE, mixed $assoc = FALSE, array $all = []): MockObject {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchField')->willReturn($field);
    $statement->method('fetchAssoc')->willReturn($assoc);
    $statement->method('fetchAll')->willReturn($all);

    $query = $this->createMock(SelectInterface::class);
    $query->method('fields')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('range')->willReturnSelf();
    $query->method('execute')->willReturn($statement);
    return $query;
  }

  /**
   * Insert stub that records what would have been written.
   *
   * @param array<int, array<string, mixed>> $log
   */
  private function insert(array &$log): MockObject {
    $query = $this->createMock(Insert::class);
    $self = $query;
    $query->method('fields')->willReturnCallback(static function (array $fields) use (&$log, $self) {
      $log[] = ['op' => 'insert', 'fields' => $fields];
      return $self;
    });
    $query->method('execute')->willReturn(1);
    return $query;
  }

  /**
   * Update stub that records what would have been written.
   *
   * @param array<int, array<string, mixed>> $log
   */
  private function update(array &$log): MockObject {
    $query = $this->createMock(Update::class);
    $self = $query;
    $fields = [];
    $query->method('fields')->willReturnCallback(static function (array $set) use ($self, &$fields) {
      $fields = $set;
      return $self;
    });
    $query->method('condition')->willReturnCallback(static function ($field, $value = NULL) use ($self, &$fields, &$log) {
      return $self;
    });
    $query->method('execute')->willReturnCallback(static function () use (&$log, &$fields) {
      $log[] = ['op' => 'update', 'fields' => $fields];
      return 1;
    });
    return $query;
  }

  /**
   * Delete stub that records what would have been removed.
   *
   * @param array<int, array<string, mixed>> $log
   */
  private function delete(array &$log): MockObject {
    $query = $this->createMock(Delete::class);
    $self = $query;
    $query->method('condition')->willReturnCallback(static fn ($field, $value = NULL) => $self);
    $query->method('execute')->willReturnCallback(static function () use (&$log) {
      $log[] = ['op' => 'delete'];
      return 1;
    });
    return $query;
  }

  /**
   * Wires one linker.
   *
   * @param array<int, \PHPUnit\Framework\MockObject\MockObject> $selects
   * @param array<int, int|string|null>                         $loadedUsers
   * @param array<int, array<string, mixed>>                    $writes
   */
  private function linker(array $selects, array $loadedUsers = [], array &$writes = []): SocialAccountLinker {
    $db = $this->createMock(Connection::class);
    if ($selects !== []) {
      $db->method('select')->willReturnOnConsecutiveCalls(...$selects);
    }
    $db->method('insert')->willReturn($this->insert($writes));
    $db->method('update')->willReturn($this->update($writes));
    $db->method('delete')->willReturn($this->delete($writes));

    $storage = $this->createMock(EntityStorageInterface::class);
    $queue = $loadedUsers;
    $storage->method('load')->willReturnCallback(static function ($id) use (&$queue) {
      return $queue === [] ? NULL : array_shift($queue);
    });
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->with('user')->willReturn($storage);

    return new SocialAccountLinker(
      $db,
      $etm,
      $this->createMock(SharedTempStoreFactory::class),
      $this->createMock(ConfigFactoryInterface::class),
      $this->createMock(LoggerChannelInterface::class),
      $this->createMock(AccountProxyInterface::class),
      $this->createMock(UserAuthInterface::class),
    );
  }

  /**
   * A logged-in account with a fixed uid.
   */
  private function user(int $uid): MockObject {
    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn((string) $uid);
    return $user;
  }

  // -------------------------------------------------------------------------
  // 纯函数：归一化 / 脱敏 / 文案
  // -------------------------------------------------------------------------

  /**
   * @covers ::normalizeMobile
   * @dataProvider mobileProvider
   */
  public function testNormalizeMobile(string $input, string $expected): void {
    $writes = [];
    $svc = $this->linker([], [], $writes);
    $this->assertSame($expected, $svc->normalizeMobile($input));
    $this->assertSame([], $writes, 'normalisation is a pure step');
  }

  /**
   * @return array<string, array{0: string, 1: string}>
   */
  public static function mobileProvider(): array {
    return [
      'bare' => ['13800138000', '13800138000'],
      'spaces and dashes' => ['+86 138-0013-8000', '+8613800138000'],
      'international 00' => ['008613800138000', '008613800138000'],
      'trailing junk' => ['13800138000a', '13800138000'],
      'letters only' => ['abcdefgh', ''],
      'dashes only' => ['  -- ', ''],
      'lone plus' => ['+', '+'],
    ];
  }

  /**
   * @covers ::maskMobile
   */
  public function testMaskMobile(): void {
    $svc = $this->linker([]);
    $method = new \ReflectionMethod(SocialAccountLinker::class, 'maskMobile');
    $method->setAccessible(TRUE);
    $this->assertSame('138****8000', $method->invoke($svc, '13800138000'));
    $this->assertSame('861****8000', $method->invoke($svc, '+8613800138000'), 'a country code shifts the visible window');
    $this->assertSame('12345', $method->invoke($svc, '12345'), 'numbers shorter than 7 digits are shown as-is');
  }

  /**
   * Every msg key the bindings page can surface has Chinese copy; unknown keys
   * pass through so a new key never renders an empty toast.
   *
   * @covers ::messageFor
   * @dataProvider messageProvider
   */
  public function testMessageFor(string $key, string $expected): void {
    $svc = $this->linker([]);
    $this->assertSame($expected, $svc->messageFor($key));
  }

  /**
   * @return array<string, array{0: string, 1: string}>
   */
  public static function messageProvider(): array {
    return [
      'already_bound' => ['already_bound', '该手机已绑定当前账号'],
      'bound' => ['bound', '手机绑定成功'],
      'already_linked' => ['already_linked', '已绑定到当前账号'],
      'linked' => ['linked', '绑定成功'],
      'merged' => ['merged', '已验证并合并到当前账号'],
      'same' => ['same', '已是同一账号'],
      'empty_credentials' => ['empty_credentials', '请填写账号和密码'],
      'account_not_found' => ['account_not_found', '未找到该用户名或邮箱'],
      'bad_password' => ['bad_password', '密码错误，无法验证归属'],
      'mobile_conflict' => ['mobile_conflict', '两边账号手机号冲突，请联系客服'],
      'protected_account' => ['protected_account', '不能合并管理员账号，请联系客服'],
      'invalid_mobile' => ['invalid_mobile', '手机号格式不正确'],
      'user_not_found' => ['user_not_found', '用户不存在'],
      'unknown key passes through' => ['sms_disabled', 'sms_disabled'],
      'empty key' => ['', ''],
    ];
  }

  // -------------------------------------------------------------------------
  // 未授权 / 脏输入：不得触库
  // -------------------------------------------------------------------------

  /**
   * @covers ::statusForUid
   */
  public function testStatusForAnonymousIsAllEmptyWithoutQuery(): void {
    $db = $this->createMock(Connection::class);
    $db->expects($this->never())->method('select');
    $svc = new SocialAccountLinker(
      $db,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(SharedTempStoreFactory::class),
      $this->createMock(ConfigFactoryInterface::class),
      $this->createMock(LoggerChannelInterface::class),
      $this->createMock(AccountProxyInterface::class),
      $this->createMock(UserAuthInterface::class),
    );
    $this->assertSame(
      ['wechat' => FALSE, 'google' => FALSE, 'google_email' => '', 'mobile' => FALSE, 'mobile_masked' => ''],
      $svc->statusForUid(0)
    );
    $this->assertSame($svc->statusForUid(0), $svc->statusForUid(-7));
  }

  /**
   * @covers ::bindMobileToUser
   */
  public function testBindMobileRejectsBlankNumberBeforeAnyLookup(): void {
    $writes = [];
    $svc = $this->linker([], [], $writes);
    $this->assertSame(
      ['ok' => FALSE, 'msg' => 'invalid_mobile'],
      $svc->bindMobileToUser($this->user(9), '  -- ')
    );
    $this->assertSame([], $writes);
  }

  /**
   * @covers ::linkOpenidToUser
   */
  public function testLinkOpenidWithoutAccount(): void {
    $svc = $this->linker([], [NULL]);
    $this->assertSame(
      ['ok' => FALSE, 'msg' => 'user_not_found'],
      $svc->linkOpenidToUser(424242, 'oABC-123')
    );
  }

  /**
   * An empty openid is reported as the same failure (one shared guard).
   *
   * @covers ::linkOpenidToUser
   */
  public function testLinkBlankOpenid(): void {
    $svc = $this->linker([], [$this->user(9)]);
    $this->assertSame(
      ['ok' => FALSE, 'msg' => 'user_not_found'],
      $svc->linkOpenidToUser(9, '   ')
    );
  }

  /**
   * @covers ::linkGoogleToUser
   */
  public function testLinkGoogleWithoutAccount(): void {
    $svc = $this->linker([], [NULL]);
    $this->assertSame(
      ['ok' => FALSE, 'msg' => 'user_not_found'],
      $svc->linkGoogleToUser(424242, '109876543210', 'a@b.example')
    );
  }

  /**
   * @covers ::linkGoogleToUser
   */
  public function testLinkGoogleWithBlankSubject(): void {
    $writes = [];
    $svc = $this->linker([], [$this->user(9)], $writes);
    $this->assertSame(
      ['ok' => FALSE, 'msg' => 'empty_sub'],
      $svc->linkGoogleToUser(9, ' ', 'a@b.example')
    );
    $this->assertSame([], $writes, 'a blank subject never reaches the identity table');
  }

  /**
   * @covers ::claimAccountByPassword
   */
  public function testClaimRequiresBothFields(): void {
    $svc = $this->linker([]);
    $this->assertSame(['ok' => FALSE, 'msg' => 'empty_credentials'], $svc->claimAccountByPassword($this->user(9), '', 'pw'));
    $this->assertSame(['ok' => FALSE, 'msg' => 'empty_credentials'], $svc->claimAccountByPassword($this->user(9), '   ', 'pw'));
    $this->assertSame(['ok' => FALSE, 'msg' => 'empty_credentials'], $svc->claimAccountByPassword($this->user(9), 'user', ''));
  }

  // -------------------------------------------------------------------------
  // 重复绑定：幂等，不产生第二行
  // -------------------------------------------------------------------------

  /**
   * Same number, same account → already_bound, zero writes.
   *
   * @covers ::bindMobileToUser
   */
  public function testRepeatMobileBindIsIdempotent(): void {
    $writes = [];
    $svc = $this->linker([$this->select('13800138000')], [], $writes);
    $this->assertSame(
      ['ok' => TRUE, 'msg' => 'already_bound'],
      $svc->bindMobileToUser($this->user(9), '138 0013 8000')
    );
    $this->assertSame([], $writes);
  }

  /**
   * Same openid, same account → already_linked, zero writes.
   *
   * @covers ::linkOpenidToUser
   */
  public function testRepeatWechatLinkIsIdempotent(): void {
    $writes = [];
    $svc = $this->linker([$this->select('9')], [$this->user(9)], $writes);
    $this->assertSame(
      ['ok' => TRUE, 'msg' => 'already_linked', 'uid' => 9],
      $svc->linkOpenidToUser(9, 'oABC-123')
    );
    $this->assertSame([], $writes);
  }

  /**
   * bindWechat() itself upserts: a second scan rewrites the row instead of
   * inserting a duplicate for the unique openid.
   *
   * @covers ::bindWechat
   */
  public function testBindWechatUpdatesExistingRow(): void {
    $writes = [];
    $svc = $this->linker([$this->select(FALSE, ['id' => 17, 'uid' => 3])], [], $writes);
    $svc->bindWechat(9, ' oABC-123 ');
    $this->assertCount(1, $writes);
    $this->assertSame('update', $writes[0]['op']);
    $this->assertSame(['uid' => 9], $writes[0]['fields']);
  }

  /**
   * @covers ::bindWechat
   */
  public function testBindWechatInsertsUnknownOpenid(): void {
    $writes = [];
    $svc = $this->linker([$this->select(FALSE, FALSE)], [], $writes);
    $svc->bindWechat(9, 'oNEW');
    $this->assertCount(1, $writes);
    $this->assertSame('insert', $writes[0]['op']);
    $this->assertSame(['openid' => 'oNEW', 'uid' => 9, 'created' => $writes[0]['fields']['created']], $writes[0]['fields']);
    $this->assertEqualsWithDelta(time(), $writes[0]['fields']['created'], 10);
  }

  /**
   * @covers ::bindMobile
   */
  public function testBindMobileMovesExistingRowToCurrentAccount(): void {
    $writes = [];
    $svc = $this->linker([$this->select(23)], [], $writes);
    $svc->bindMobile(9, '+86 138-0013-8000');
    $this->assertSame([['op' => 'update', 'fields' => ['uid' => 9]]], $writes);
  }

  // -------------------------------------------------------------------------
  // 同标识冲突归并
  // -------------------------------------------------------------------------

  /**
   * The number lives on the admin account: the merge is refused and nothing is
   * written, so a takeover attempt is a no-op.
   *
   * @covers ::bindMobileToUser
   * @covers ::mergeUsers
   */
  public function testMobileConflictWithAdminIsRefused(): void {
    $writes = [];
    $svc = $this->linker(
      [$this->select(FALSE), $this->select('1')],
      [$this->user(1)],
      $writes
    );
    $this->assertSame(
      ['ok' => FALSE, 'msg' => 'protected_account'],
      $svc->bindMobileToUser($this->user(9), '13800138000')
    );
    $this->assertSame([], $writes);
  }

  /**
   * Two different numbers on both accounts is a hard conflict.
   *
   * @covers ::mergeUsers
   */
  public function testMergeRefusesDifferentNumbers(): void {
    $writes = [];
    $svc = $this->linker([$this->select('13900139000'), $this->select('13800138000')], [], $writes);
    $this->assertSame(
      ['ok' => FALSE, 'msg' => 'mobile_conflict'],
      $svc->mergeUsers($this->user(9), $this->user(11))
    );
    $this->assertSame([], $writes);
  }

  /**
   * @covers ::mergeUsers
   */
  public function testMergeWithSelfIsANoOp(): void {
    $writes = [];
    $db = $this->createMock(Connection::class);
    $db->expects($this->never())->method('select');
    $svc = new SocialAccountLinker(
      $db,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(SharedTempStoreFactory::class),
      $this->createMock(ConfigFactoryInterface::class),
      $this->createMock(LoggerChannelInterface::class),
      $this->createMock(AccountProxyInterface::class),
      $this->createMock(UserAuthInterface::class),
    );
    $this->assertSame(['ok' => TRUE, 'msg' => 'same'], $svc->mergeUsers($this->user(9), $this->user(9)));
    $this->assertSame([], $writes);
  }

  /**
   * @covers ::mergeUsers
   */
  public function testMergeNeverConsumesUidOne(): void {
    $db = $this->createMock(Connection::class);
    $db->expects($this->never())->method('select');
    $db->expects($this->never())->method('update');
    $db->expects($this->never())->method('delete');
    $svc = new SocialAccountLinker(
      $db,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(SharedTempStoreFactory::class),
      $this->createMock(ConfigFactoryInterface::class),
      $this->createMock(LoggerChannelInterface::class),
      $this->createMock(AccountProxyInterface::class),
      $this->createMock(UserAuthInterface::class),
    );
    $this->assertSame(
      ['ok' => FALSE, 'msg' => 'protected_account'],
      $svc->mergeUsers($this->user(9), $this->user(1))
    );
  }

  // -------------------------------------------------------------------------
  // claim_account 归属校验
  // -------------------------------------------------------------------------

  /**
   * @covers ::claimAccountByPassword
   */
  public function testClaimUnknownAccount(): void {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadByProperties')->willReturn([]);
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->with('user')->willReturn($storage);
    $svc = $this->linkerWithStorage($etm);
    $this->assertSame(
      ['ok' => FALSE, 'msg' => 'account_not_found'],
      $svc->claimAccountByPassword($this->user(9), 'ghost@example.com', 'pw')
    );
  }

  /**
   * Claiming the account you are already in is reported as 'same'.
   *
   * @covers ::claimAccountByPassword
   */
  public function testClaimOwnAccount(): void {
    $mine = $this->user(9);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadByProperties')->willReturn([9 => $mine]);
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->with('user')->willReturn($storage);
    $svc = $this->linkerWithStorage($etm);
    $this->assertSame(['ok' => TRUE, 'msg' => 'same'], $svc->claimAccountByPassword($mine, 'me@example.com', 'pw'));
  }

  /**
   * A wrong password stops before any merge write.
   *
   * @covers ::claimAccountByPassword
   */
  public function testClaimWithWrongPassword(): void {
    $other = $this->createMock(UserInterface::class);
    $other->method('id')->willReturn('11');
    $other->method('getAccountName')->willReturn('legacy_user');
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadByProperties')->willReturn([11 => $other]);
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->with('user')->willReturn($storage);

    $auth = $this->createMock(UserAuthInterface::class);
    $auth->expects($this->once())
      ->method('authenticate')
      ->with('legacy_user', 'wrong')
      ->willReturn(FALSE);

    $db = $this->createMock(Connection::class);
    $db->method('select')->willReturn($this->select());
    $writes = [];
    $db->method('insert')->willReturn($this->insert($writes));
    $db->method('update')->willReturn($this->update($writes));
    $svc = new SocialAccountLinker(
      $db,
      $etm,
      $this->createMock(SharedTempStoreFactory::class),
      $this->createMock(ConfigFactoryInterface::class),
      $this->createMock(LoggerChannelInterface::class),
      $this->createMock(AccountProxyInterface::class),
      $auth,
    );
    $this->assertSame(
      ['ok' => FALSE, 'msg' => 'bad_password'],
      $svc->claimAccountByPassword($this->user(9), 'legacy_user', 'wrong')
    );
    $this->assertSame([], $writes);
  }

  /**
   * @covers ::claimAccountByPassword
   */
  public function testClaimFallsBackToUsernameWhenMailLookupMisses(): void {
    $named = $this->createMock(UserInterface::class);
    $named->method('id')->willReturn('11');
    $named->method('getAccountName')->willReturn('legacy_user');
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadByProperties')->willReturnCallback(
      static fn (array $props) => isset($props['name']) ? [11 => $named] : []
    );
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->with('user')->willReturn($storage);

    $auth = $this->createMock(UserAuthInterface::class);
    $auth->method('authenticate')->willReturn(FALSE);
    $svc = $this->linkerWithStorage($etm, $auth);
    $this->assertSame(
      ['ok' => FALSE, 'msg' => 'bad_password'],
      $svc->claimAccountByPassword($this->user(9), 'legacy_user', 'wrong')
    );
  }

  /**
   * @return \Drupal\dx_auth\Service\SocialAccountLinker
   */
  private function linkerWithStorage(EntityTypeManagerInterface $etm, ?MockObject $auth = NULL): SocialAccountLinker {
    $writes = [];
    $db = $this->createMock(Connection::class);
    $db->method('select')->willReturn($this->select());
    $db->method('insert')->willReturn($this->insert($writes));
    $db->method('update')->willReturn($this->update($writes));
    return new SocialAccountLinker(
      $db,
      $etm,
      $this->createMock(SharedTempStoreFactory::class),
      $this->createMock(ConfigFactoryInterface::class),
      $this->createMock(LoggerChannelInterface::class),
      $this->createMock(AccountProxyInterface::class),
      $auth ?? $this->createMock(UserAuthInterface::class),
    );
  }

}
