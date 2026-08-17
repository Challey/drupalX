<?php

declare(strict_types=1);

namespace Drupal\dx_auth\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use GuzzleHttp\ClientInterface;

/**
 * Aliyun Dysmsapi OTP sender (Topstar aliyunsms port, HTTP RPC without SDK).
 */
class SmsAuthService {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected ClientInterface $httpClient,
    protected SocialAccountLinker $linker,
    protected FloodInterface $flood,
    protected LoggerChannelInterface $logger,
  ) {}

  /**
   * Whether SMS login is configured.
   */
  public function isEnabled(): bool {
    $c = $this->configFactory->get('dx_auth.settings');
    return (bool) $c->get('sms_enabled')
      && trim((string) $c->get('sms_access_key')) !== ''
      && trim((string) $c->get('sms_access_secret')) !== ''
      && trim((string) $c->get('sms_template_code')) !== '';
  }

  /**
   * Sends a 6-digit OTP. Returns TRUE on success.
   */
  public function sendCode(string $mobile, string $ip = '0.0.0.0'): bool|string {
    if (!$this->isEnabled()) {
      return 'sms_disabled';
    }
    $mobile = $this->linker->normalizeMobile($mobile);
    if ($mobile === '' || !preg_match('/^\+?\d{6,20}$/', $mobile)) {
      return 'invalid_mobile';
    }
    if (!$this->flood->isAllowed('dx_auth.sms_send_ip', 10, 3600, $ip)) {
      return 'flood';
    }
    if (!$this->flood->isAllowed('dx_auth.sms_send_mobile', 5, 3600, $mobile)) {
      return 'flood';
    }

    $code = (string) random_int(100000, 999999);
    $c = $this->configFactory->get('dx_auth.settings');
    $sign = trim((string) $c->get('sms_sign_name')) ?: 'DrupalX';
    $template = trim((string) $c->get('sms_template_code'));
    $phone = str_starts_with($mobile, '+86') ? substr($mobile, 3) : $mobile;
    $phone = ltrim($phone, '+');

    $ok = $this->sendAliyun($phone, $sign, $template, json_encode(['code' => $code], JSON_UNESCAPED_UNICODE));
    if ($ok !== TRUE) {
      return is_string($ok) ? $ok : 'send_failed';
    }

    $this->linker->storeSmsCode($mobile, $code);
    $this->flood->register('dx_auth.sms_send_ip', 3600, $ip);
    $this->flood->register('dx_auth.sms_send_mobile', 3600, $mobile);
    return TRUE;
  }

  /**
   * Aliyun Dysmsapi SendSms via RPC signature.
   */
  protected function sendAliyun(string $phone, string $sign, string $template, string $paramJson): bool|string {
    $c = $this->configFactory->get('dx_auth.settings');
    $accessKey = trim((string) $c->get('sms_access_key'));
    $accessSecret = trim((string) $c->get('sms_access_secret'));

    $params = [
      'AccessKeyId' => $accessKey,
      'Action' => 'SendSms',
      'Format' => 'JSON',
      'PhoneNumbers' => $phone,
      'RegionId' => 'cn-hangzhou',
      'SignName' => $sign,
      'SignatureMethod' => 'HMAC-SHA1',
      'SignatureNonce' => bin2hex(random_bytes(8)),
      'SignatureVersion' => '1.0',
      'TemplateCode' => $template,
      'TemplateParam' => $paramJson,
      'Timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
      'Version' => '2017-05-25',
    ];
    ksort($params);
    $canonical = [];
    foreach ($params as $k => $v) {
      $canonical[] = $this->percentEncode($k) . '=' . $this->percentEncode((string) $v);
    }
    $stringToSign = 'GET&%2F&' . $this->percentEncode(implode('&', $canonical));
    $signature = base64_encode(hash_hmac('sha1', $stringToSign, $accessSecret . '&', TRUE));
    $params['Signature'] = $signature;

    try {
      $response = $this->httpClient->request('GET', 'https://dysmsapi.aliyuncs.com/', [
        'query' => $params,
        'http_errors' => FALSE,
        'timeout' => 20,
      ]);
      $data = json_decode((string) $response->getBody(), TRUE) ?: [];
      if (($data['Code'] ?? '') === 'OK') {
        return TRUE;
      }
      $this->logger->error('Aliyun SMS failed: @b', ['@b' => (string) $response->getBody()]);
      return (string) ($data['Message'] ?? 'send_failed');
    }
    catch (\Throwable $e) {
      $this->logger->error('Aliyun SMS exception: @m', ['@m' => $e->getMessage()]);
      return 'send_failed';
    }
  }

  /**
   * Percent-encode per Aliyun RPC rules.
   */
  protected function percentEncode(string $value): string {
    $encoded = rawurlencode($value);
    return str_replace(['+', '*', '%7E'], ['%20', '%2A', '~'], $encoded);
  }

}
