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
      $this->data = ['default' => 'portal', 'skins' => []];
      return $this->data;
    }
    $parsed = Yaml::parseFile($path);
    $this->data = is_array($parsed) ? $parsed : ['default' => 'portal', 'skins' => []];
    return $this->data;
  }

}
