<?php

declare(strict_types=1);

namespace Drupal\Tests\dx_auth\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Password\PasswordInterface;
use Drupal\dx_auth\Service\EnterpriseAccountLinker;
use Drupal\dx_auth\Service\EnterpriseIdentityService;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\dx_auth\Service\EnterpriseAccountLinker
 * @group dx_auth
 */
class EnterpriseAccountLinkerTest extends UnitTestCase {

  /**
   * @covers ::isSafePortalUrl
   * @dataProvider portalUrlProvider
   */
  public function testIsSafePortalUrl(string $url, bool $expected): void {
    $linker = new EnterpriseAccountLinker(
      $this->createMock(Connection::class),
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(PasswordInterface::class),
      $this->createMock(LoggerChannelInterface::class),
      $this->createMock(EnterpriseIdentityService::class),
    );

    $method = new \ReflectionMethod(EnterpriseAccountLinker::class, 'isSafePortalUrl');
    $method->setAccessible(TRUE);
    $this->assertSame($expected, $method->invoke($linker, $url));
  }

  /**
   * @return array<string, array{0: string, 1: bool}>
   */
  public static function portalUrlProvider(): array {
    return [
      'https ok' => ['https://tenant.example.com', TRUE],
      'https with path' => ['https://tenant.example.com/app', TRUE],
      'http rejected' => ['http://tenant.example.com', FALSE],
      'credentials rejected' => ['https://user:pass@tenant.example.com', FALSE],
      'fragment rejected' => ['https://tenant.example.com/#evil', FALSE],
      'relative rejected' => ['/user/login', FALSE],
      'javascript rejected' => ['javascript:alert(1)', FALSE],
    ];
  }

}
