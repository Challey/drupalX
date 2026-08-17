<?php

declare(strict_types=1);

namespace Drupal\dx_auth\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Resolves and validates market-supervision unified social credit codes.
 */
class EnterpriseIdentityService {

  /**
   * GB 32100-2015 character set (excludes I/O/Z/S/V).
   */
  private const CHARSET = '0123456789ABCDEFGHJKLMNPQRTUWXY';

  /**
   * Checksum weights for positions 1–17.
   *
   * @var int[]
   */
  private const WEIGHTS = [1, 3, 9, 27, 19, 26, 16, 17, 20, 29, 25, 13, 8, 24, 10, 30, 28];

  public function __construct(
    protected Connection $database,
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LoggerChannelInterface $logger,
  ) {}

  /**
   * Normalizes a credit code (uppercase, strip spaces/hyphens).
   */
  public function normalize(?string $code): string {
    if ($code === NULL || $code === '') {
      return '';
    }
    $normalized = preg_replace('/[\s\-]+/', '', $code);
    return strtoupper($normalized ?? '');
  }

  /**
   * Validates format and checksum of a unified social credit code.
   */
  public function validate(?string $code): bool {
    $normalized = $this->normalize($code);
    if ($normalized === '' || strlen($normalized) !== 18) {
      return FALSE;
    }
    if (!preg_match('/^[0-9A-HJ-NPQRTUWXY]{2}\d{6}[0-9A-HJ-NPQRTUWXY]{10}$/', $normalized)) {
      return FALSE;
    }
    return $this->checksumValid($normalized);
  }

  /**
   * Masks a credit code for display (keeps prefix + suffix).
   */
  public function mask(?string $code): string {
    $normalized = $this->normalize($code);
    if (strlen($normalized) < 8) {
      return $normalized;
    }
    return substr($normalized, 0, 4) . str_repeat('*', max(0, strlen($normalized) - 8)) . substr($normalized, -4);
  }

  /**
   * Masks a company name for anonymous preview.
   */
  public function maskCompanyName(string $name): string {
    $name = trim($name);
    if ($name === '') {
      return '已登记企业';
    }
    $len = mb_strlen($name);
    if ($len <= 2) {
      return mb_substr($name, 0, 1) . '*';
    }
    if ($len <= 4) {
      return mb_substr($name, 0, 1) . str_repeat('*', $len - 2) . mb_substr($name, -1);
    }
    return mb_substr($name, 0, 2) . str_repeat('*', min(4, $len - 4)) . mb_substr($name, -2);
  }

  /**
   * Resolves enterprise identity from bindings, tenant settings, or platform entity.
   *
   * @return array{
   *   found: bool,
   *   credit_code: string,
   *   credit_code_masked: string,
   *   company_name: string,
   *   company_name_masked: string,
   *   uid: int|null,
   *   source: string|null,
   *   portal_url: string
   * }
   */
  public function resolve(?string $code): array {
    $normalized = $this->normalize($code);
    $empty = [
      'found' => FALSE,
      'credit_code' => $normalized,
      'credit_code_masked' => $this->mask($normalized),
      'company_name' => '',
      'company_name_masked' => '',
      'uid' => NULL,
      'source' => NULL,
      'portal_url' => '',
    ];

    if ($normalized === '' || !$this->validate($normalized)) {
      return $empty;
    }

    // 1) Explicit binding table.
    try {
      $row = $this->database->select('dx_auth_enterprise', 'e')
        ->fields('e', ['uid', 'company_name', 'credit_code'])
        ->condition('credit_code', $normalized)
        ->range(0, 1)
        ->execute()
        ->fetchAssoc();
      if ($row) {
        $name = (string) $row['company_name'];
        return [
          'found' => TRUE,
          'credit_code' => (string) $row['credit_code'],
          'credit_code_masked' => $this->mask((string) $row['credit_code']),
          'company_name' => $name,
          'company_name_masked' => $this->maskCompanyName($name),
          'uid' => (int) $row['uid'],
          'source' => 'binding',
          'portal_url' => '',
        ];
      }
    }
    catch (\Throwable $e) {
      $this->logger->warning('Enterprise binding lookup failed: @m', ['@m' => $e->getMessage()]);
    }

    // 2) Current tenant settings — lookup preview only (no uid).
    // Login requires an explicit dx_auth_enterprise binding so credit codes
    // cannot authenticate as the first administrator by default.
    $settings = $this->configFactory->get('dx_tenant.settings');
    $settingsCode = $this->normalize((string) ($settings->get('credit_code') ?? ''));
    if ($settingsCode !== '' && $settingsCode === $normalized) {
      $name = (string) ($settings->get('company_name') ?? '');
      return [
        'found' => TRUE,
        'credit_code' => $settingsCode,
        'credit_code_masked' => $this->mask($settingsCode),
        'company_name' => $name,
        'company_name_masked' => $this->maskCompanyName($name),
        'uid' => NULL,
        'source' => 'tenant_settings',
        'portal_url' => '',
      ];
    }

    // 3) Platform dx_tenant entity (when available on this site).
    try {
      if ($this->entityTypeManager->hasDefinition('dx_tenant')) {
        $storage = $this->entityTypeManager->getStorage('dx_tenant');
        $entities = $storage->loadByProperties(['credit_code' => $normalized]);
        if (!empty($entities)) {
          /** @var \Drupal\dx_platform\Entity\Tenant $tenant */
          $tenant = reset($entities);
          $name = (string) $tenant->label();
          $portal = '';
          if ($tenant->hasField('portal_url') && !$tenant->get('portal_url')->isEmpty()) {
            $portal = (string) $tenant->get('portal_url')->value;
          }
          return [
            'found' => TRUE,
            'credit_code' => $normalized,
            'credit_code_masked' => $this->mask($normalized),
            'company_name' => $name,
            'company_name_masked' => $this->maskCompanyName($name),
            'uid' => NULL,
            'source' => 'platform_tenant',
            'portal_url' => $portal,
          ];
        }
      }
    }
    catch (\Throwable $e) {
      $this->logger->debug('Platform tenant credit lookup skipped: @m', ['@m' => $e->getMessage()]);
    }

    return $empty;
  }

  /**
   * Default owner uid for tenant-settings based login.
   */
  public function defaultOwnerUid(): int {
    try {
      $uids = $this->entityTypeManager->getStorage('user')->getQuery()
        ->accessCheck(FALSE)
        ->condition('status', 1)
        ->condition('roles', 'administrator')
        ->range(0, 1)
        ->execute();
      if ($uids) {
        return (int) reset($uids);
      }
    }
    catch (\Throwable $e) {
      $this->logger->debug('defaultOwnerUid query failed: @m', ['@m' => $e->getMessage()]);
    }
    return 1;
  }

  /**
   * Validates GB 32100-2015 checksum digit.
   */
  protected function checksumValid(string $code): bool {
    $sum = 0;
    for ($i = 0; $i < 17; $i++) {
      $pos = strpos(self::CHARSET, $code[$i]);
      if ($pos === FALSE) {
        return FALSE;
      }
      $sum += $pos * self::WEIGHTS[$i];
    }
    $checkPos = 31 - ($sum % 31);
    if ($checkPos === 31) {
      $checkPos = 0;
    }
    $expected = self::CHARSET[$checkPos] ?? '';
    return $expected !== '' && $code[17] === $expected;
  }

}
