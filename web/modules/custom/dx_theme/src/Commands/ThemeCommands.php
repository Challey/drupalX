<?php

declare(strict_types=1);

namespace Drupal\dx_theme\Commands;

use Drupal\dx_theme\Service\ThemeCatalog;
use Drupal\dx_theme\Service\ThemeStudio;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for Theme Studio.
 */
final class ThemeCommands extends DrushCommands {

  public function __construct(
    protected ThemeStudio $studio,
    protected ThemeCatalog $catalog,
  ) {
    parent::__construct();
  }

  /**
   * List curated portal theme packs.
   *
   * @command dx:theme-list
   * @aliases dx-theme-list
   * @option format table|json
   * @usage drush dx:theme-list
   * @usage drush dx:theme-list --format=json
   */
  public function themeList(array $options = ['format' => 'table']): void {
    $active = $this->studio->getActiveId();
    $rows = [];
    foreach ($this->catalog->all() as $id => $skin) {
      $rows[] = [
        'id' => $id,
        'label' => (string) ($skin['label'] ?? $id),
        'mood' => (string) ($skin['mood'] ?? ''),
        'density' => (string) ($skin['density'] ?? ''),
        'active' => $id === $active ? 'yes' : '',
        'library' => (string) ($skin['library'] ?? '—'),
      ];
    }
    if (($options['format'] ?? 'table') === 'json') {
      $this->output()->writeln(json_encode([
        'active' => $active,
        'skins' => $rows,
      ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
      return;
    }
    $this->io()->table(
      ['id', 'label', 'mood', 'density', 'active', 'library'],
      array_map(static fn(array $r): array => array_values($r), $rows),
    );
  }

  /**
   * Apply a curated theme pack.
   *
   * @command dx:theme-apply
   * @aliases dx-theme-apply
   * @param string $skin
   *   Theme pack machine name from dx:theme-list.
   * @usage drush dx:theme-apply harbor
   */
  public function themeApply(string $skin): void {
    $result = $this->studio->apply($skin);
    if (!$result['ok']) {
      throw new \RuntimeException($result['message']);
    }
    $this->logger()->success($result['message']);
  }

  /**
   * Theme Studio status (active / preview / catalog size).
   *
   * @command dx:theme-status
   * @aliases dx-theme-status
   * @option format table|json
   * @usage drush dx:theme-status
   * @usage drush dx:theme-status --format=json
   */
  public function themeStatus(array $options = ['format' => 'table']): void {
    $status = $this->studio->status();
    if (($options['format'] ?? 'table') === 'json') {
      $this->output()->writeln(json_encode($status, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
      return;
    }
    foreach ($status as $key => $value) {
      $display = is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value;
      $this->io()->writeln(sprintf('%s: %s', $key, $display));
    }
  }

}
