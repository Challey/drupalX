<?php

declare(strict_types=1);

namespace Drupal\dx_appstore\Service;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\dx_appstore\Entity\License;
use Drupal\user\UserInterface;

/**
 * Builds tenant-visible (L3) source zips with DX-RAL notice and download audit.
 */
final class SourceBundleService {

  public const AUDIT_COLLECTION = 'dx_appstore.source_audit';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ModuleExtensionList $moduleList,
    protected KeyValueFactoryInterface $keyValueFactory,
  ) {}

  public function canDownload(License $license, AccountInterface $account): bool {
    if (!$this->licenseEligible($license)) {
      return FALSE;
    }
    $policy = (string) ($license->get('source_policy')->value ?? 'tenant_visible');
    if ($policy === 'partner_vault') {
      if ($account->hasPermission('administer dx appstore')) {
        return TRUE;
      }
      if (\Drupal::hasService('dx_ecosystem.gate') && $account instanceof UserInterface) {
        return \Drupal::service('dx_ecosystem.gate')->canAccessPartnerVault($account);
      }
      return FALSE;
    }
    if ($account->hasPermission('administer dx appstore')) {
      return TRUE;
    }
    if (!$account->hasPermission('download dx app source')) {
      return FALSE;
    }
    return $this->isRequester($license, (int) $account->id());
  }

  public function downloadAccess(License $dx_license, AccountInterface $account): AccessResult {
    return AccessResult::allowedIf($this->canDownload($dx_license, $account))
      ->cachePerUser()
      ->addCacheableDependency($dx_license);
  }

  /**
   * @return array{path: string, filename: string, bytes: int, module: string, tenant: string}
   */
  public function buildZip(License $license, AccountInterface $account): array {
    if (!$this->canDownload($license, $account)) {
      throw new \RuntimeException('L3 source download denied (license, DX-RAL, or identity).');
    }
    $app = $license->get('app_id')->entity;
    if (!$app) {
      throw new \RuntimeException('License has no app package.');
    }
    $module = trim((string) $app->get('module_name')->value);
    if ($module === '') {
      throw new \RuntimeException('App package has no module_name.');
    }
    $src = DRUPAL_ROOT . '/' . $this->moduleList->getPath($module);
    if (!is_dir($src)) {
      throw new \RuntimeException("Module path missing for {$module}");
    }

    $tenant = (string) $license->get('tenant_machine')->value;
    $filename = sprintf('dx-l3-%s-%s-%d.zip', $module, $tenant !== '' ? $tenant : 'platform', (int) $license->id());
    $tmp = sys_get_temp_dir() . '/' . $filename;
    if (is_file($tmp)) {
      unlink($tmp);
    }

    $zip = new \ZipArchive();
    if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== TRUE) {
      throw new \RuntimeException('Cannot create source zip');
    }
    $zip->addFromString('NOTICE-DX-RAL.txt', $this->notice($license, $account, $module));
    $this->addDirectory($zip, $src, $module);
    $zip->close();

    $bytes = (int) filesize($tmp);
    $this->recordAudit($license, $account, $module, $bytes);
    return [
      'path' => $tmp,
      'filename' => $filename,
      'bytes' => $bytes,
      'module' => $module,
      'tenant' => $tenant,
    ];
  }

  /**
   * @return list<array<string, mixed>>
   */
  public function auditLog(int $limit = 50): array {
    $store = $this->keyValueFactory->get(self::AUDIT_COLLECTION);
    $rows = $store->get('entries', []);
    if (!is_array($rows)) {
      return [];
    }
    return array_slice(array_reverse($rows), 0, max(1, $limit));
  }

  protected function licenseEligible(License $license): bool {
    if ((string) $license->get('status')->value !== 'active') {
      return FALSE;
    }
    $ral = trim((string) ($license->get('agreement_version')->value ?? ''));
    return $ral !== '';
  }

  protected function isRequester(License $license, int $uid): bool {
    if ($uid < 1) {
      return FALSE;
    }
    $appId = $license->get('app_id')->target_id;
    $tenant = (string) $license->get('tenant_machine')->value;
    $storage = $this->entityTypeManager->getStorage('dx_install_request');
    $requests = $storage->loadByProperties([
      'app_id' => $appId,
      'tenant_machine' => $tenant,
    ]);
    foreach ($requests as $request) {
      if ((int) $request->get('requester_uid')->target_id === $uid) {
        return TRUE;
      }
    }
    return FALSE;
  }

  protected function notice(License $license, AccountInterface $account, string $module): string {
    $tenant = (string) $license->get('tenant_machine')->value;
    $ral = (string) $license->get('agreement_version')->value;
    $when = gmdate('c');
    return <<<TXT
DrupalX L3 tenant source bundle
Module: {$module}
License: #{$license->id()}
Tenant: {$tenant}
Downloaded by uid {$account->id()} at {$when}
DX-RAL version: {$ral}

This tree is licensed to the installing tenant for self-use and operations.
You must not provide this source, build artifacts, or internal docs to a
fourth party, publish a public git mirror, or sideload outside the App Store.
See docs/open-ecosystem.md (DX-RAL). GPL files remain GPL.
TXT;
  }

  protected function addDirectory(\ZipArchive $zip, string $dir, string $prefix): void {
    $it = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
    );
    foreach ($it as $file) {
      /** @var \SplFileInfo $file */
      if (!$file->isFile()) {
        continue;
      }
      $rel = substr($file->getPathname(), strlen($dir) + 1);
      if ($rel === FALSE || str_starts_with($rel, '.git/')) {
        continue;
      }
      $zip->addFile($file->getPathname(), $prefix . '/' . $rel);
    }
  }

  protected function recordAudit(License $license, AccountInterface $account, string $module, int $bytes): void {
    $store = $this->keyValueFactory->get(self::AUDIT_COLLECTION);
    $rows = $store->get('entries', []);
    if (!is_array($rows)) {
      $rows = [];
    }
    $rows[] = [
      'ts' => time(),
      'license_id' => (int) $license->id(),
      'tenant' => (string) $license->get('tenant_machine')->value,
      'module' => $module,
      'uid' => (int) $account->id(),
      'bytes' => $bytes,
    ];
    if (count($rows) > 500) {
      $rows = array_slice($rows, -500);
    }
    $store->set('entries', $rows);
  }

}
