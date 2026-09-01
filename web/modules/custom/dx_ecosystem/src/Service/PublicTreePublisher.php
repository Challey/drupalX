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

  /**
   * Dry-run the export: which documents would ship, which get stripped.
   *
   * Reads only docs/l0-whitelist.yml and docs/visibility.yml, so it is safe on
   * a checkout with no database and no .env.
   *
   * @return array<string,mixed>
   *   Shape documented by dx_l0_plan(): ok, code, entries, published,
   *   stripped, unregistered, stale, issues, counts.
   */
  public function plan(): array {
    $this->loadLibrary();
    return dx_l0_plan($this->repoRoot());
  }

  /**
   * The CI verdict for the current whitelist and visibility registry.
   *
   * @return array{ok:bool,code:string,exit:int,issues:list<array<string,mixed>>,warnings:list<array<string,mixed>>}
   */
  public function gate(): array {
    $this->loadLibrary();
    return dx_l0_gate(dx_l0_plan($this->repoRoot()));
  }

  /**
   * One-line gate summary for log and Drush output.
   */
  public function renderPlan(array $plan, string $visibility = ''): string {
    $this->loadLibrary();
    return dx_l0_render_text($plan, $visibility);
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
