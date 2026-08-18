<?php

declare(strict_types=1);

namespace Drupal\dx_ecosystem\Service;

/**
 * Drupal wrapper around the L0 publish library.
 */
final class PublicTreePublisher {

  /**
   * Repository root (parent of web/).
   */
  public function repoRoot(): string {
    return dirname(\Drupal::root());
  }

  public function openapiPath(): string {
    return $this->repoRoot() . '/docs/openapi/dxep-v1.yaml';
  }

  public function whitelistPath(): string {
    return $this->repoRoot() . '/docs/l0-whitelist.yml';
  }

  /**
   * @return array{dest:string,copied:int,removed:int}
   */
  public function publish(string $dest): array {
    $this->loadLibrary();
    $root = $this->repoRoot();
    $whitelist = dx_l0_load_whitelist($root);
    dx_l0_write_repo_api_docs($root);
    $report = dx_l0_publish($root, $dest, $whitelist);
    dx_l0_verify($dest, $whitelist);
    return $report;
  }

  public function writeRepoApiDocs(): string {
    $this->loadLibrary();
    return dx_l0_write_repo_api_docs($this->repoRoot());
  }

  public function openapiContents(): string {
    $path = $this->openapiPath();
    if (!is_file($path)) {
      throw new \RuntimeException('OpenAPI spec missing: ' . $path);
    }
    return (string) file_get_contents($path);
  }

  private function loadLibrary(): void {
    $lib = $this->repoRoot() . '/scripts/lib/l0_publish.php';
    if (!is_file($lib)) {
      throw new \RuntimeException('Missing ' . $lib);
    }
    require_once $lib;
  }

}
