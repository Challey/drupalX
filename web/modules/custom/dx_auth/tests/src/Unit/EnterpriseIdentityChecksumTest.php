<?php

declare(strict_types=1);

namespace Drupal\Tests\dx_auth\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\dx_auth\Service\EnterpriseIdentityService;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\dx_auth\Service\EnterpriseIdentityService
 * @group dx_auth
 */
class EnterpriseIdentityChecksumTest extends UnitTestCase {

  /**
   * Builds a service with mocked deps.
   */
  protected function service(): EnterpriseIdentityService {
    return new EnterpriseIdentityService(
      $this->createMock(Connection::class),
      $this->createMock(ConfigFactoryInterface::class),
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(LoggerChannelInterface::class),
    );
  }

  /**
   * @covers ::validate
   */
  public function testValidChecksum(): void {
    $svc = $this->service();
    // Generated with GB 32100 weights in this change set.
    $this->assertTrue($svc->validate('91110000MA0123456P'));
  }

  /**
   * @covers ::validate
   */
  public function testInvalidChecksum(): void {
    $svc = $this->service();
    $this->assertFalse($svc->validate('91110000MA0123456X'));
  }

  /**
   * @covers ::normalize
   */
  public function testNormalize(): void {
    $svc = $this->service();
    $this->assertSame('91110000MA0123456P', $svc->normalize(' 9111-0000 ma0123456p '));
  }

  /**
   * @covers ::mask
   */
  public function testMaskCreditCode(): void {
    $svc = $this->service();
    $this->assertSame('9111**********456P', $svc->mask('91110000MA0123456P'));
  }

  /**
   * @covers ::maskCompanyName
   */
  public function testMaskCompanyName(): void {
    $svc = $this->service();
    $this->assertSame('已登记企业', $svc->maskCompanyName(''));
    $this->assertSame('中**司', $svc->maskCompanyName('中国公司'));
    $this->assertSame('北京****公司', $svc->maskCompanyName('北京示例科技有限公司'));
  }

  /**
   * @covers ::validate
   */
  public function testRejectsShortCode(): void {
    $svc = $this->service();
    $this->assertFalse($svc->validate('91110000'));
  }

}
