<?php

declare(strict_types=1);

namespace Drupal\dx_certs\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Certificate vault metadata (path refs + readiness probes).
 *
 * Private key material is never stored in config — only path references and
 * non-secret fingerprints of the referenced file when readable.
 */
final class CertVault {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * @return array{vault_root: string, vault_root_resolved: string, entries: list<array<string, mixed>>, ready_count: int, missing_count: int}
   */
  public function status(bool $probe = TRUE): array {
    $c = $this->configFactory->get('dx_certs.settings');
    $vaultRoot = (string) ($c->get('vault_root') ?: '~/staging/drupalX/certs');
    $entries = $c->get('entries') ?? [];
    if (!is_array($entries)) {
      $entries = [];
    }
    $out = [];
    $ready = 0;
    $missing = 0;
    foreach ($entries as $entry) {
      if (!is_array($entry)) {
        continue;
      }
      $view = $probe ? $this->enrichEntry($entry) : $entry;
      $out[] = $view;
      if (!empty($view['ready'])) {
        $ready++;
      }
      else {
        $missing++;
      }
    }
    return [
      'vault_root' => $vaultRoot,
      'vault_root_resolved' => $this->resolvePath($vaultRoot),
      'entries' => array_values($out),
      'ready_count' => $ready,
      'missing_count' => $missing,
    ];
  }

  /**
   * Register a cert path reference.
   *
   * @return array<string, mixed>
   */
  public function register(string $id, string $platform, string $pathRef, string $label = '', string $expiresAt = ''): array {
    $id = preg_replace('/[^a-z0-9_\-]/i', '', $id) ?: ('cert_' . substr(bin2hex(random_bytes(4)), 0, 8));
    $platform = strtolower(trim($platform));
    if (!in_array($platform, ['android', 'ios', 'wechat'], TRUE)) {
      $platform = 'android';
    }
    $entry = [
      'id' => $id,
      'platform' => $platform,
      'label' => $label !== '' ? $label : $id,
      'path_ref' => $pathRef,
      'expires_at' => $expiresAt,
    ];
    $editable = $this->configFactory->getEditable('dx_certs.settings');
    $entries = $editable->get('entries') ?? [];
    if (!is_array($entries)) {
      $entries = [];
    }
    $replaced = FALSE;
    foreach ($entries as $i => $existing) {
      if (is_array($existing) && ($existing['id'] ?? '') === $id) {
        $entries[$i] = $entry;
        $replaced = TRUE;
        break;
      }
    }
    if (!$replaced) {
      $entries[] = $entry;
    }
    $editable->set('entries', array_values($entries))->save();
    return $this->enrichEntry($entry);
  }

  /**
   * Remove a cert entry by id.
   */
  public function revoke(string $id): bool {
    $editable = $this->configFactory->getEditable('dx_certs.settings');
    $entries = $editable->get('entries') ?? [];
    if (!is_array($entries)) {
      return FALSE;
    }
    $next = [];
    $found = FALSE;
    foreach ($entries as $existing) {
      if (!is_array($existing)) {
        continue;
      }
      if (($existing['id'] ?? '') === $id) {
        $found = TRUE;
        continue;
      }
      $next[] = $existing;
    }
    if (!$found) {
      return FALSE;
    }
    $editable->set('entries', array_values($next))->save();
    return TRUE;
  }

  /**
   * Probe all entries (or one id) for packer readiness.
   *
   * @return array{ok: bool, checks: list<array<string, mixed>>}
   */
  public function check(?string $id = NULL): array {
    $status = $this->status(TRUE);
    $checks = [];
    foreach ($status['entries'] as $entry) {
      if ($id !== NULL && $id !== '' && ($entry['id'] ?? '') !== $id) {
        continue;
      }
      $checks[] = $entry;
    }
    $ok = TRUE;
    foreach ($checks as $c) {
      if (empty($c['ready'])) {
        $ok = FALSE;
        break;
      }
    }
    if ($id !== NULL && $id !== '' && $checks === []) {
      $ok = FALSE;
      $checks[] = [
        'id' => $id,
        'ready' => FALSE,
        'message' => 'not registered',
      ];
    }
    return ['ok' => $ok && $checks !== [], 'checks' => $checks];
  }

  /**
   * Resolve packer env hints for a tenant/platform.
   *
   * @return array<string, string>
   */
  public function packerEnv(string $platform): array {
    $status = $this->status(TRUE);
    $fallback = NULL;
    foreach ($status['entries'] as $entry) {
      if (($entry['platform'] ?? '') !== $platform) {
        continue;
      }
      if (!empty($entry['ready'])) {
        return [
          'DX_CERT_ID' => (string) ($entry['id'] ?? ''),
          'DX_CERT_PATH' => (string) ($entry['path_resolved'] ?? $entry['path_ref'] ?? ''),
          'DX_CERT_PATH_REF' => (string) ($entry['path_ref'] ?? ''),
          'DX_CERT_VAULT' => $status['vault_root_resolved'],
          'DX_CERT_READY' => '1',
          'DX_CERT_SHA256' => (string) ($entry['sha256'] ?? ''),
          'DX_CERT_PLATFORM' => $platform,
        ];
      }
      $fallback ??= $entry;
    }
    if ($fallback !== NULL) {
      return [
        'DX_CERT_ID' => (string) ($fallback['id'] ?? ''),
        'DX_CERT_PATH' => (string) ($fallback['path_resolved'] ?? $fallback['path_ref'] ?? ''),
        'DX_CERT_PATH_REF' => (string) ($fallback['path_ref'] ?? ''),
        'DX_CERT_VAULT' => $status['vault_root_resolved'],
        'DX_CERT_READY' => '0',
        'DX_CERT_SHA256' => (string) ($fallback['sha256'] ?? ''),
        'DX_CERT_PLATFORM' => $platform,
      ];
    }
    return [
      'DX_CERT_VAULT' => $status['vault_root_resolved'],
      'DX_CERT_READY' => '0',
      'DX_CERT_PLATFORM' => $platform,
    ];
  }

  /**
   * Expand ~ and return absolute-ish path for probes.
   */
  public function resolvePath(string $path): string {
    $path = trim($path);
    if ($path === '') {
      return '';
    }
    if (str_starts_with($path, '~/')) {
      $home = getenv('HOME') ?: (getenv('USERPROFILE') ?: '/home');
      return rtrim(str_replace('\\', '/', $home), '/') . '/' . substr($path, 2);
    }
    if ($path === '~') {
      return getenv('HOME') ?: (getenv('USERPROFILE') ?: '/home');
    }
    return $path;
  }

  /**
   * @param array<string, mixed> $entry
   *
   * @return array<string, mixed>
   */
  public function enrichEntry(array $entry): array {
    $pathRef = (string) ($entry['path_ref'] ?? '');
    $resolved = $this->resolvePath($pathRef);
    $exists = $resolved !== '' && is_file($resolved);
    $readable = $exists && is_readable($resolved);
    $sha = '';
    $size = 0;
    if ($readable) {
      $size = (int) (@filesize($resolved) ?: 0);
      $hash = @hash_file('sha256', $resolved);
      $sha = is_string($hash) ? $hash : '';
    }
    $expiresAt = trim((string) ($entry['expires_at'] ?? ''));
    $expired = FALSE;
    if ($expiresAt !== '') {
      $ts = strtotime($expiresAt);
      if ($ts !== FALSE && $ts < time()) {
        $expired = TRUE;
      }
    }
    $ready = $readable && !$expired && $size > 0;
    $message = 'ok';
    if (!$exists) {
      $message = 'path missing';
    }
    elseif (!$readable) {
      $message = 'not readable';
    }
    elseif ($size <= 0) {
      $message = 'empty file';
    }
    elseif ($expired) {
      $message = 'expired';
    }
    return [
      'id' => (string) ($entry['id'] ?? ''),
      'platform' => (string) ($entry['platform'] ?? ''),
      'label' => (string) ($entry['label'] ?? ''),
      'path_ref' => $pathRef,
      'path_resolved' => $resolved,
      'expires_at' => $expiresAt,
      'exists' => $exists,
      'readable' => $readable,
      'size' => $size,
      'sha256' => $sha,
      'expired' => $expired,
      'ready' => $ready,
      'message' => $message,
    ];
  }

}
