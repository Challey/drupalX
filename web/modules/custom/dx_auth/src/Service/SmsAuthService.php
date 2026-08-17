<?php

declare(strict_types=1);

namespace Drupal\dx_auth\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\PrivateKey;
use Drupal\Core\Site\Settings;
use Drupal\user\UserInterface;
use GuzzleHttp\ClientInterface;

/**
 * Mobile SMS login (Aliyun Dysmsapi, plus test mode).
 */
class SmsAuthService {

  public const CODE_TTL = 300;

  public const RESEND_SECONDS = 60;

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected Connection $database,
    protected CacheBackendInterface $cache,
    protected ClientInterface $httpClient,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LoggerChannelInterface $logger,
    protected PrivateKey $privateKey,
  ) {}

  /**
   * Whether SMS login is enabled (test mode counts as enabled).
   */
  public function isEnabled(): bool {
    $cfg = $this->smsConfig();
    if (empty($cfg['enabled'])) {
      return FALSE;
    }
    if (!empty($cfg['test_mode'])) {
      return TRUE;
    }
    return $cfg['access_key_id'] !== '' && $cfg['access_key_secret'] !== ''
      && $cfg['sign_name'] !== '' && $cfg['template_code'] !== '';
  }

  /**
   * Whether codes are generated locally instead of sent via Aliyun.
   */
  public function isTestMode(): bool {
    return !empty($this->smsConfig()['test_mode']);
  }

  /**
   * Normalizes a mobile number to 11-digit CN form when possible.
   */
  public static function normalizeMobile(string $raw): string {
    $value = preg_replace('/[\s\-\(\)]+/', '', $raw) ?? '';
    if (str_starts_with($value, '+86')) {
      $value = substr($value, 3);
    }
    elseif (str_starts_with($value, '0086')) {
      $value = substr($value, 4);
    }
    elseif (str_starts_with($value, '86') && strlen($value) === 13) {
      $value = substr($value, 2);
    }
    return $value;
  }

  /**
   * Mainland China mobile numbers (11 digits, 1[3-9]).
   */
  public static function isValidCnMobile(string $normalized): bool {
    return (bool) preg_match('/^1[3-9]\d{9}$/', $normalized);
  }

  /**
   * Generates a numeric OTP.
   */
  public static function generateNumericCode(int $length = 6): string {
    $length = max(4, min(8, $length));
    $max = (10 ** $length) - 1;
    return str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);
  }

  /**
   * Sends (or locally stores, in test mode) a login code.
   *
   * @return array{ok: bool, msg: string, ttl?: int, retry_after?: int, debug_code?: string}
   */
  public function send(string $mobile): array {
    if (!$this->isEnabled()) {
      return ['ok' => FALSE, 'msg' => 'sms_unavailable'];
    }
    $normalized = self::normalizeMobile($mobile);
    if (!self::isValidCnMobile($normalized)) {
      return ['ok' => FALSE, 'msg' => 'invalid_mobile'];
    }

    $lockKey = 'dx_auth:sms_lock:' . $normalized;
    if ($this->cache->get($lockKey)) {
      return ['ok' => FALSE, 'msg' => 'sms_rate_limited', 'retry_after' => self::RESEND_SECONDS];
    }

    $code = self::generateNumericCode(6);
    $this->cache->set('dx_auth:sms:' . $normalized, [
      'hash' => $this->hashCode($normalized, $code),
      'attempts' => 0,
    ], time() + self::CODE_TTL);
    $this->cache->set($lockKey, TRUE, time() + self::RESEND_SECONDS);

    $cfg = $this->smsConfig();
    if (empty($cfg['test_mode'])) {
      $sent = $this->sendAliyun($normalized, $code);
      if (!$sent) {
        $this->cache->delete('dx_auth:sms:' . $normalized);
        $this->cache->delete($lockKey);
        return ['ok' => FALSE, 'msg' => 'sms_send_failed'];
      }
    }
    else {
      $this->logger->notice('SMS test-mode code for @m: (redacted, length @n)', [
        '@m' => substr($normalized, 0, 3) . '****' . substr($normalized, -4),
        '@n' => strlen($code),
      ]);
    }

    $out = [
      'ok' => TRUE,
      'msg' => 'ok',
      'ttl' => self::CODE_TTL,
      'retry_after' => self::RESEND_SECONDS,
    ];
    if (!empty($cfg['test_mode'])) {
      $out['debug_code'] = $code;
    }
    return $out;
  }

  /**
   * Verifies the OTP and returns the Drupal user to log in.
   *
   * @return array{ok: bool, msg: string, user?: \Drupal\user\UserInterface}
   */
  public function login(string $mobile, string $code): array {
    if (!$this->isEnabled()) {
      return ['ok' => FALSE, 'msg' => 'sms_unavailable'];
    }
    $normalized = self::normalizeMobile($mobile);
    $code = preg_replace('/\D+/', '', $code) ?? '';
    if (!self::isValidCnMobile($normalized) || strlen($code) < 4) {
      return ['ok' => FALSE, 'msg' => 'invalid_code'];
    }

    $cid = 'dx_auth:sms:' . $normalized;
    $item = $this->cache->get($cid);
    if (!$item || empty($item->data) || !is_array($item->data)) {
      return ['ok' => FALSE, 'msg' => 'code_expired'];
    }
    $data = $item->data;
    $attempts = (int) ($data['attempts'] ?? 0);
    if ($attempts >= 5) {
      $this->cache->delete($cid);
      return ['ok' => FALSE, 'msg' => 'code_locked'];
    }
    if (!hash_equals((string) $data['hash'], $this->hashCode($normalized, $code))) {
      $data['attempts'] = $attempts + 1;
      $this->cache->set($cid, $data, $item->expire ?: (time() + 60));
      return ['ok' => FALSE, 'msg' => 'invalid_code'];
    }
    $this->cache->delete($cid);
    $this->cache->delete('dx_auth:sms_lock:' . $normalized);

    $user = $this->findOrCreateUser($normalized);
    if (!$user instanceof UserInterface || !$user->isActive()) {
      return ['ok' => FALSE, 'msg' => 'account_unavailable'];
    }
    return ['ok' => TRUE, 'msg' => 'ok', 'user' => $user];
  }

  /**
   * HMAC-SHA256 of the OTP using site keys (not reversible from logs).
   */
  public function hashCode(string $mobile, string $code): string {
    $secret = $this->privateKey->get() . Settings::getHashSalt();
    return hash_hmac('sha256', $mobile . ':' . $code, $secret);
  }

  /**
   * Percent-encode for Aliyun RPC signatures.
   */
  public static function aliyunPercentEncode(string $value): string {
    $encoded = rawurlencode($value);
    return str_replace(['+', '*', '%7E'], ['%20', '%2A', '~'], $encoded);
  }

  /**
   * Builds the Aliyun RPC signature (HMAC-SHA1).
   *
   * @param array<string, string> $params
   */
  public static function aliyunSignature(array $params, string $accessKeySecret, string $method = 'POST'): string {
    unset($params['Signature']);
    ksort($params);
    $canonical = [];
    foreach ($params as $key => $value) {
      $canonical[] = self::aliyunPercentEncode((string) $key) . '=' . self::aliyunPercentEncode((string) $value);
    }
    $canonicalized = implode('&', $canonical);
    $stringToSign = $method . '&' . self::aliyunPercentEncode('/') . '&' . self::aliyunPercentEncode($canonicalized);
    return base64_encode(hash_hmac('sha1', $stringToSign, $accessKeySecret . '&', TRUE));
  }

  /**
   * @return array<string, mixed>
   */
  protected function smsConfig(): array {
    $raw = $this->configFactory->get('dx_auth.settings')->get('sms') ?: [];
    return [
      'enabled' => !empty($raw['enabled']),
      'test_mode' => !empty($raw['test_mode']),
      'provider' => (string) ($raw['provider'] ?? 'aliyun'),
      'access_key_id' => trim((string) ($raw['access_key_id'] ?? '')),
      'access_key_secret' => trim((string) ($raw['access_key_secret'] ?? '')),
      'sign_name' => trim((string) ($raw['sign_name'] ?? '')),
      'template_code' => trim((string) ($raw['template_code'] ?? '')),
      'template_param_key' => trim((string) ($raw['template_param_key'] ?? 'code')) ?: 'code',
      'region' => trim((string) ($raw['region'] ?? 'cn-hangzhou')) ?: 'cn-hangzhou',
    ];
  }

  protected function sendAliyun(string $mobile, string $code): bool {
    $cfg = $this->smsConfig();
    $params = [
      'AccessKeyId' => $cfg['access_key_id'],
      'Action' => 'SendSms',
      'Format' => 'JSON',
      'PhoneNumbers' => $mobile,
      'RegionId' => $cfg['region'],
      'SignName' => $cfg['sign_name'],
      'SignatureMethod' => 'HMAC-SHA1',
      'SignatureNonce' => bin2hex(random_bytes(8)),
      'SignatureVersion' => '1.0',
      'TemplateCode' => $cfg['template_code'],
      'TemplateParam' => json_encode([$cfg['template_param_key'] => $code], JSON_UNESCAPED_UNICODE),
      'Timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
      'Version' => '2017-05-25',
    ];
    $params['Signature'] = self::aliyunSignature($params, $cfg['access_key_secret']);

    try {
      $response = $this->httpClient->request('POST', 'https://dysmsapi.aliyuncs.com/', [
        'form_params' => $params,
        'timeout' => 12,
      ]);
      $data = json_decode((string) $response->getBody(), TRUE);
      $ok = is_array($data) && (($data['Code'] ?? '') === 'OK');
      if (!$ok) {
        $this->logger->error('Aliyun SMS failed: @c @m', [
          '@c' => (string) ($data['Code'] ?? 'unknown'),
          '@m' => (string) ($data['Message'] ?? ''),
        ]);
      }
      return $ok;
    }
    catch (\Throwable $e) {
      $this->logger->error('Aliyun SMS request failed: @m', ['@m' => $e->getMessage()]);
      return FALSE;
    }
  }

  protected function findOrCreateUser(string $mobile): ?UserInterface {
    $uid = (int) $this->database->select('dx_auth_mobile', 'm')
      ->fields('m', ['uid'])
      ->condition('mobile', $mobile)
      ->range(0, 1)
      ->execute()
      ->fetchField();

    $storage = $this->entityTypeManager->getStorage('user');
    if ($uid > 0) {
      $user = $storage->load($uid);
      if ($user instanceof UserInterface) {
        return $user;
      }
    }

    $existing = $storage->loadByProperties(['name' => $mobile]);
    if ($existing) {
      $user = reset($existing);
      if ($user instanceof UserInterface) {
        $this->bind((int) $user->id(), $mobile);
        return $user;
      }
    }

    /** @var \Drupal\user\UserInterface $user */
    $user = $storage->create([
      'name' => $mobile,
      'status' => 1,
    ]);
    $user->save();
    $this->bind((int) $user->id(), $mobile);
    return $user;
  }

  protected function bind(int $uid, string $mobile): void {
    $now = time();
    $existing = $this->database->select('dx_auth_mobile', 'm')
      ->fields('m', ['id'])
      ->condition('mobile', $mobile)
      ->range(0, 1)
      ->execute()
      ->fetchField();
    if ($existing) {
      $this->database->update('dx_auth_mobile')
        ->fields(['uid' => $uid, 'changed' => $now])
        ->condition('id', (int) $existing)
        ->execute();
      return;
    }
    $this->database->insert('dx_auth_mobile')->fields([
      'uid' => $uid,
      'mobile' => $mobile,
      'created' => $now,
      'changed' => $now,
    ])->execute();
  }

}
