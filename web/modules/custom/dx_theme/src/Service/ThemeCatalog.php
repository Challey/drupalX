<?php

declare(strict_types=1);

namespace Drupal\dx_theme\Service;

use Drupal\Core\Extension\ModuleExtensionList;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads the curated theme pack catalog from module data/catalog.yml.
 */
final class ThemeCatalog {

  /**
   * @var array<string, mixed>|null
   */
  private ?array $data = NULL;

  public function __construct(
    protected ModuleExtensionList $moduleList,
  ) {}

  /**
   * Default skin machine name.
   */
  public function defaultSkinId(): string {
    $data = $this->load();
    $default = (string) ($data['default'] ?? 'portal');
    return $this->has($default) ? $default : 'portal';
  }

  /**
   * Whether a skin id exists in the catalog.
   */
  public function has(string $id): bool {
    return isset($this->all()[$id]);
  }

  /**
   * All skins keyed by machine name.
   *
   * @return array<string, array<string, mixed>>
   */
  public function all(): array {
    $skins = $this->load()['skins'] ?? [];
    return is_array($skins) ? $skins : [];
  }

  /**
   * Family definitions keyed by id, sorted by weight.
   *
   * @return array<string, array{id: string, label: string, summary: string, weight: int}>
   */
  public function families(): array {
    $raw = $this->load()['families'] ?? [];
    if (!is_array($raw) || $raw === []) {
      return [
        'universal' => [
          'id' => 'universal',
          'label' => 'Universal',
          'summary' => '',
          'weight' => 100,
        ],
      ];
    }
    $out = [];
    foreach ($raw as $id => $meta) {
      if (!is_array($meta)) {
        continue;
      }
      $out[(string) $id] = [
        'id' => (string) $id,
        'label' => (string) ($meta['label'] ?? $id),
        'summary' => (string) ($meta['summary'] ?? ''),
        'weight' => (int) ($meta['weight'] ?? 50),
      ];
    }
    uasort($out, static fn(array $a, array $b): int => $a['weight'] <=> $b['weight']);
    return $out;
  }

  /**
   * Skins grouped by family (order follows families() then catalog order).
   *
   * @param bool $include_legacy
   *   When FALSE, skins marked legacy are omitted.
   *
   * @return array<string, list<array<string, mixed>>>
   */
  public function byFamily(bool $include_legacy = TRUE): array {
    $grouped = [];
    foreach (array_keys($this->families()) as $familyId) {
      $grouped[$familyId] = [];
    }
    foreach ($this->all() as $id => $skin) {
      if (!$include_legacy && !empty($skin['legacy'])) {
        continue;
      }
      $family = (string) ($skin['family'] ?? 'universal');
      if (!isset($grouped[$family])) {
        $grouped[$family] = [];
      }
      $skin['id'] = $id;
      $grouped[$family][] = $skin;
    }
    return $grouped;
  }

  /**
   * Single skin definition or NULL.
   *
   * @return array<string, mixed>|null
   */
  public function get(string $id): ?array {
    $skins = $this->all();
    if (!isset($skins[$id]) || !is_array($skins[$id])) {
      return NULL;
    }
    $skin = $skins[$id];
    $skin['id'] = $id;
    return $skin;
  }

  /**
   * Featured skins for gallery highlight (order preserved).
   *
   * @return list<array<string, mixed>>
   */
  public function featured(): array {
    $out = [];
    foreach ($this->all() as $id => $skin) {
      if (!empty($skin['featured'])) {
        $skin['id'] = $id;
        $out[] = $skin;
      }
    }
    return $out;
  }

  /**
   * @return array<string, mixed>
   */
  protected function load(): array {
    if ($this->data !== NULL) {
      return $this->data;
    }
    $path = $this->moduleList->getPath('dx_theme') . '/data/catalog.yml';
    if (!is_readable($path)) {
      $this->data = ['default' => 'portal', 'skins' => [], 'families' => []];
      return $this->data;
    }
    $parsed = Yaml::parseFile($path);
    $this->data = is_array($parsed) ? $parsed : ['default' => 'portal', 'skins' => [], 'families' => []];
    return $this->data;
  }

}
