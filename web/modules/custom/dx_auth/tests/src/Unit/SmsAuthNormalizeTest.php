<?php

declare(strict_types=1);

namespace Drupal\Tests\dx_auth\Unit;

use Drupal\dx_auth\Service\SmsAuthService;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\dx_auth\Service\SmsAuthService
 * @group dx_auth
 */
class SmsAuthNormalizeTest extends UnitTestCase {

  /**
   * @covers ::normalizeMobile
   * @dataProvider mobileProvider
   */
  public function testNormalizeMobile(string $raw, string $expected): void {
    $this->assertSame($expected, SmsAuthService::normalizeMobile($raw));
  }

  /**
   * @return array<string, array{0: string, 1: string}>
   */
  public static function mobileProvider(): array {
    return [
      'plain' => ['13800138000', '13800138000'],
      'plus86' => ['+86 138-0013-8000', '13800138000'],
      '0086' => ['008613800138000', '13800138000'],
      '86prefix' => ['8613800138000', '13800138000'],
    ];
  }

  /**
   * @covers ::isValidCnMobile
   */
  public function testValidCnMobile(): void {
    $this->assertTrue(SmsAuthService::isValidCnMobile('13800138000'));
    $this->assertFalse(SmsAuthService::isValidCnMobile('1380013800'));
    $this->assertFalse(SmsAuthService::isValidCnMobile('03800138000'));
    $this->assertFalse(SmsAuthService::isValidCnMobile(''));
  }

  /**
   * @covers ::generateNumericCode
   */
  public function testGenerateNumericCodeLength(): void {
    $code = SmsAuthService::generateNumericCode(6);
    $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
  }

  /**
   * @covers ::aliyunSignature
   */
  public function testAliyunSignatureStable(): void {
    $params = [
      'AccessKeyId' => 'testid',
      'Action' => 'SendSms',
      'Format' => 'JSON',
      'PhoneNumbers' => '13800138000',
      'RegionId' => 'cn-hangzhou',
      'SignName' => 'DrupalX',
      'SignatureMethod' => 'HMAC-SHA1',
      'SignatureNonce' => 'abc123',
      'SignatureVersion' => '1.0',
      'TemplateCode' => 'SMS_0000',
      'TemplateParam' => '{"code":"123456"}',
      'Timestamp' => '2026-08-17T00:00:00Z',
      'Version' => '2017-05-25',
    ];
    $sig = SmsAuthService::aliyunSignature($params, 'testsecret');
    $this->assertNotSame('', $sig);
    $this->assertSame($sig, SmsAuthService::aliyunSignature($params, 'testsecret'));
    $this->assertNotSame($sig, SmsAuthService::aliyunSignature($params, 'othersecret'));
  }

}
