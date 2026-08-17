<?php

declare(strict_types=1);

namespace Drupal\Tests\dx_auth\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Delete;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Password\PasswordInterface;
use Drupal\dx_auth\Service\EnterpriseAccountLinker;
use Drupal\dx_auth\Service\EnterpriseIdentityService;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;

/**
 * @coversDefaultClass \Drupal\dx_auth\Service\EnterpriseAccountLinker
 * @group dx_auth
 */
class EnterpriseAccountLinkerTest extends UnitTestCase {

  /**
   * @covers ::loginByEnterprise
   */
  public function testLoginRejectsInvalidCreditCode(): void {
    $identity = $this->createMock(EnterpriseIdentityService::class);
    $identity->method('normalize')->willReturn('BAD');
    $identity->method('validate')->willReturn(FALSE);

    $linker = new EnterpriseAccountLinker(
      $this->createMock(Connection::class),
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(PasswordInterface::class),
      $this->createMock(LoggerChannelInterface::class),
      $identity,
    );

    $result = $linker->loginByEnterprise('BAD', 'secret');
    $this->assertFalse($result['ok']);
    $this->assertSame('invalid_credit_code', $result['msg']);
  }

  /**
   * @covers ::loginByEnterprise
   */
  public function testLoginPortalRedirect(): void {
    $identity = $this->createMock(EnterpriseIdentityService::class);
    $identity->method('normalize')->willReturn('91110000MA0123456P');
    $identity->method('validate')->willReturn(TRUE);
    $identity->method('resolve')->willReturn([
      'found' => TRUE,
      'source' => 'platform_tenant',
      'portal_url' => 'https://acme.example.com',
      'uid' => NULL,
    ]);

    $linker = new EnterpriseAccountLinker(
      $this->createMock(Connection::class),
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(PasswordInterface::class),
      $this->createMock(LoggerChannelInterface::class),
      $identity,
    );

    $result = $linker->loginByEnterprise('91110000MA0123456P', 'secret');
    $this->assertFalse($result['ok']);
    $this->assertSame('portal_redirect', $result['action']);
    $this->assertSame('https://acme.example.com/user/login#enterprise', $result['redirect']);
  }

  /**
   * @covers ::loginByEnterprise
   */
  public function testLoginSuccessWithPassword(): void {
    $identity = $this->createMock(EnterpriseIdentityService::class);
    $identity->method('normalize')->willReturn('91110000MA0123456P');
    $identity->method('validate')->willReturn(TRUE);
    $identity->method('resolve')->willReturn([
      'found' => TRUE,
      'source' => 'binding',
      'uid' => 5,
      'portal_url' => '',
    ]);

    $user = $this->createMock(UserInterface::class);
    $user->method('isActive')->willReturn(TRUE);
    $user->method('getPassword')->willReturn('hash');

    $storage = $this->createMock(\Drupal\Core\Entity\EntityStorageInterface::class);
    $storage->method('load')->with(5)->willReturn($user);

    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->with('user')->willReturn($storage);

    $password = $this->createMock(PasswordInterface::class);
    $password->method('check')->with('secret', 'hash')->willReturn(TRUE);
    $password->method('needsRehash')->willReturn(FALSE);

    $linker = new EnterpriseAccountLinker(
      $this->createMock(Connection::class),
      $etm,
      $password,
      $this->createMock(LoggerChannelInterface::class),
      $identity,
    );

    $result = $linker->loginByEnterprise('91110000MA0123456P', 'secret');
    $this->assertTrue($result['ok']);
    $this->assertSame($user, $result['user']);
  }

  /**
   * @covers ::unbind
   */
  public function testUnbind(): void {
    $delete = $this->createMock(Delete::class);
    $delete->method('condition')->willReturnSelf();
    $delete->method('execute')->willReturn(1);

    $database = $this->createMock(Connection::class);
    $database->method('delete')->with('dx_auth_enterprise')->willReturn($delete);

    $linker = new EnterpriseAccountLinker(
      $database,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(PasswordInterface::class),
      $this->createMock(LoggerChannelInterface::class),
      $this->createMock(EnterpriseIdentityService::class),
    );

    $this->assertTrue($linker->unbind(3));
    $this->assertFalse($linker->unbind(0));
  }

}
