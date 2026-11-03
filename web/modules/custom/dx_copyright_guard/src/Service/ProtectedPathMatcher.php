<?php

declare(strict_types=1);

namespace Drupal\dx_copyright_guard\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Matches request paths against the configured protected path patterns.
 */
final class ProtectedPathMatcher {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Returns configured patterns, one per line.
   *
   * @return string[]
   *   Normalized patterns without surrounding slashes.
   */
  public function getPatterns(): array {
    $raw = (string) ($this->configFactory->get('dx_copyright_guard.settings')->get('protected_paths') ?? '');
    $patterns = [];
    foreach (preg_split('/[\r\n]+/', $raw) ?: [] as $line) {
      $line = trim($line);
      $line = trim($line, '/');
      if ($line !== '' && !str_starts_with($line, '#')) {
        $patterns[] = $line;
      }
    }
    return array_values(array_unique($patterns));
  }

  /**
   * Whether a request path is protected.
   */
  public function isProtected(string $path): bool {
    $path = trim($path, '/');
    if ($path === '') {
      return FALSE;
    }
    foreach ($this->getPatterns() as $pattern) {
      if ($path === $pattern) {
        return TRUE;
      }
      if (str_contains($pattern, '*') && fnmatch($pattern, $path, FNM_CASEFOLD)) {
        return TRUE;
      }
      if (!str_contains($pattern, '*') && str_starts_with($path, $pattern . '/')) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Paths that must never be intercepted (login and the agreement itself).
   */
  public function isExempt(string $path): bool {
    $path = trim($path, '/');
    if ($path === '') {
      return TRUE;
    }
    $exempt = [
      'dx-copyright',
      'user/login',
      'user/logout',
      'user/password',
      'admin',
    ];
    foreach ($exempt as $prefix) {
      if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
