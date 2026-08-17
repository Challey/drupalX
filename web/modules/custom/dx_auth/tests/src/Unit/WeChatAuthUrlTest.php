<?php

declare(strict_types=1);

namespace Drupal\Tests\dx_auth\Unit;

use Drupal\dx_auth\Service\WeChatAuthService;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\dx_auth\Service\WeChatAuthService
 * @group dx_auth
 */
class WeChatAuthUrlTest extends UnitTestCase {

  /**
   * @covers ::buildQrConnectUrl
   */
  public function testQrConnectUrl(): void {
    $url = WeChatAuthService::buildQrConnectUrl(
      'wx123',
      'https://www.drupal.org.cn/dx/auth/wechat/callback',
      'state-token',
    );
    $this->assertStringStartsWith('https://open.weixin.qq.com/connect/qrconnect?', $url);
    $this->assertStringContainsString('appid=wx123', $url);
    $this->assertStringContainsString('scope=snsapi_login', $url);
    $this->assertStringContainsString('state=state-token', $url);
    $this->assertStringContainsString(rawurlencode('https://www.drupal.org.cn/dx/auth/wechat/callback'), $url);
    $this->assertStringEndsWith('#wechat_redirect', $url);
  }

  /**
   * @covers ::buildMpAuthorizeUrl
   */
  public function testMpAuthorizeUrl(): void {
    $url = WeChatAuthService::buildMpAuthorizeUrl(
      'wxmp456',
      'https://www.drupal.org.cn/dx/auth/wechat/callback',
      'mp-state',
    );
    $this->assertStringStartsWith('https://open.weixin.qq.com/connect/oauth2/authorize?', $url);
    $this->assertStringContainsString('appid=wxmp456', $url);
    $this->assertStringContainsString('scope=snsapi_userinfo', $url);
    $this->assertStringEndsWith('#wechat_redirect', $url);
  }

}
