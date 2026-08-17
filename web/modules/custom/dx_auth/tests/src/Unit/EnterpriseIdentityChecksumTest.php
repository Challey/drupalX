<?php

declare(strict_types=1);

namespace Drupal\Tests\dx_auth\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\StatementInterface;
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
  protected function service(
    ?Connection $database = NULL,
    ?ConfigFactoryInterface $configFactory = NULL,
    ?EntityTypeManagerInterface $entityTypeManager = NULL,
  ): EnterpriseIdentityService {
    return new EnterpriseIdentityService(
      $database ?? $this->createMock(Connection::class),
      $configFactory ?? $this->createMock(ConfigFactoryInterface::class),
      $entityTypeManager ?? $this->createMock(EntityTypeManagerInterface::class),
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
  public function testMask(): void {
    $svc = $this->service();
    $this->assertSame('9111**********456P', $svc->mask('91110000MA0123456P'));
  }

  /**
   * @covers ::maskCompanyName
   */
  public function testMaskCompanyName(): void {
    $svc = $this->service();
    $this->assertSame('北京****公司', $svc->maskCompanyName('北京某某科技有限公司'));
    $this->assertSame('已登记企业', $svc->maskCompanyName(''));
  }

  /**
   * @covers ::resolve
   */
  public function testResolveFromBinding(): void {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAssoc')->willReturn([
      'uid' => 7,
      'company_name' => '示例科技有限公司',
      'credit_code' => '91110000MA0123456P',
    ]);

    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $database = $this->createMock(Connection::class);
    $database->method('select')->willReturn($select);

    $svc = $this->service($database);
    $resolved = $svc->resolve('91110000MA0123456P');
    $this->assertTrue($resolved['found']);
    $this->assertSame(7, $resolved['uid']);
    $this->assertSame('binding', $resolved['source']);
    $this->assertSame('示例****公司', $resolved['company_name_masked']);
  }

  /**
   * @covers ::resolve
   */
  public function testResolveFromTenantSettings(): void {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAssoc')->willReturn(FALSE);

    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $database = $this->createMock(Connection::class);
    $database->method('select')->willReturn($select);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['credit_code', '91110000MA0123456P'],
      ['company_name', '租户企业'],
    ]);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('dx_tenant.settings')->willReturn($config);

    $query = $this->getMockBuilder(\Drupal\Core\Entity\Query\QueryInterface::class)
      ->disableOriginalConstructor()
      ->getMock();
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('range')->willReturnSelf();
    $query->method('execute')->willReturn([1 => '1']);

    $storage = $this->createMock(\Drupal\Core\Entity\EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('user')->willReturn($storage);

    $svc = $this->service($database, $configFactory, $entityTypeManager);
    $resolved = $svc->resolve('91110000MA0123456P');
    $this->assertTrue($resolved['found']);
    $this->assertSame('tenant_settings', $resolved['source']);
    $this->assertSame(1, $resolved['uid']);
  }

}
