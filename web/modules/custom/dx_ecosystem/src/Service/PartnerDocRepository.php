<?php

declare(strict_types=1);

namespace Drupal\dx_ecosystem\Service;

use Symfony\Component\Yaml\Yaml;

/**
 * Partner (L2) documentation catalog — gated by DeveloperGate.
 */
final class PartnerDocRepository {

  public function dataDir(): string {
    return dirname(__DIR__, 2) . '/data/partner';
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
    $list = $data['docs'] ?? [];
    $out = [];
    foreach ($list as $row) {
      if (!empty($row['id'])) {
        $out[(string) $row['id']] = $row;
      }
    }
    return $out;
  }

  /**
   * @return array{id: string, title: string, version: string, visibility: string, body: string}|null
   */
  public function load(string $id): ?array {
    $meta = $this->manifest()[$id] ?? NULL;
    if ($meta === NULL) {
      return NULL;
    }
    $file = (string) ($meta['file'] ?? '');
    $path = $this->dataDir() . '/' . $file;
    $body = is_readable($path) ? (string) file_get_contents($path) : '';
    return [
      'id' => (string) $meta['id'],
      'title' => (string) ($meta['title'] ?? $id),
      'version' => (string) ($meta['version'] ?? '1.0'),
      'visibility' => (string) ($meta['visibility'] ?? 'partner'),
      'body' => $body,
    ];
  }

}
