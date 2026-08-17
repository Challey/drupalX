<?php

declare(strict_types=1);

namespace Drupal\dx_ecosystem\Service;

use Symfony\Component\Yaml\Yaml;

/**
 * Loads versioned DX-RAL / DPA agreement texts from module data.
 */
final class AgreementRepository {

  /**
   * Returns absolute path to agreements data directory.
   */
  public function dataDir(): string {
    return dirname(__DIR__, 2) . '/data/agreements';
  }

  /**
   * @return array<string, array<string, mixed>>
   */
  public function manifest(): array {
    $path = $this->dataDir() . '/manifest.yml';
    if (!is_readable($path)) {
      return [];
    }
    $data = Yaml::parseFile($path);
    $list = $data['agreements'] ?? [];
    $out = [];
    foreach ($list as $row) {
      if (!empty($row['id'])) {
        $out[(string) $row['id']] = $row;
      }
    }
    return $out;
  }

  /**
   * @return array<string, mixed>|null
   */
  public function get(string $id): ?array {
    $all = $this->manifest();
    return $all[$id] ?? NULL;
  }

  /**
   * Current DX-RAL metadata + body.
   *
   * @return array{id: string, version: string, title: string, body: string}|null
   */
  public function currentRal(?string $id = NULL): ?array {
    $id = $id ?: 'dx_ral';
    return $this->loadBody($id);
  }

  /**
   * Current DPA metadata + body.
   *
   * @return array{id: string, version: string, title: string, body: string}|null
   */
  public function currentDpa(?string $id = NULL): ?array {
    $id = $id ?: 'dpa';
    return $this->loadBody($id);
  }

  /**
   * @return array{id: string, version: string, title: string, body: string}|null
   */
  public function loadBody(string $id): ?array {
    $meta = $this->get($id);
    if ($meta === NULL) {
      return NULL;
    }
    $file = (string) ($meta['file'] ?? '');
    $path = $this->dataDir() . '/' . $file;
    $body = is_readable($path) ? (string) file_get_contents($path) : '';
    return [
      'id' => (string) $meta['id'],
      'version' => (string) ($meta['version'] ?? ''),
      'title' => (string) ($meta['title'] ?? $id),
      'body' => $body,
    ];
  }

}
