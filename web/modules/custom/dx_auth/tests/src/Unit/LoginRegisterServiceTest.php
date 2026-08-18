<?php

declare(strict_types=1);

namespace Drupal\Tests\dx_auth\Unit;

use Drupal\Component\Utility\EmailValidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\dx_auth\Service\LoginRegisterService;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserStorageInterface;

/**
 * @coversDefaultClass \Drupal\dx_auth\Service\LoginRegisterService
 * @group dx_auth
 */
class LoginRegisterServiceTest extends UnitTestCase {

  /**
   * @covers ::isEmail
   */
  public function testIsEmail(): void {
    $validator = $this->createMock(EmailValidatorInterface::class);
    $validator->method('isValid')->willReturnCallback(static fn (string $v): bool => str_contains($v, '@'));

    $storage = $this->createMock(UserStorageInterface::class);
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->with('user')->willReturn($storage);

    $svc = new LoginRegisterService(
      $etm,
      $validator,
      $this->createMock(ConfigFactoryInterface::class),
      $this->createMock(LoggerChannelInterface::class),
    );

    $this->assertTrue($svc->isEmail('a@example.com'));
    $this->assertFalse($svc->isEmail('admin'));
  }

}
