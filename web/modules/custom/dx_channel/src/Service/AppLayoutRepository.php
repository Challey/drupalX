<?php

declare(strict_types=1);

namespace Drupal\dx_channel\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\dx_theme\Service\ThemeStudio;

/**
 * Loads and versions App Layout (L1) for Flutter / mini-program shells.
 */
final class AppLayoutRepository {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected ModuleExtensionList $moduleList,
    protected ThemeStudio $themeStudio,
  ) {}

  /**
   * Current revision number.
   */
  public function getRevision(): int {
    return (int) ($this->configFactory->get('dx_channel.settings')->get('revision') ?: 1);
  }

  /**
   * Full layout document for Channel API.
   *
   * @return array<string, mixed>
   */
  public function getLayout(): array {
    $config = $this->configFactory->get('dx_channel.settings');
    $profile = (string) ($config->get('layout_profile') ?: 'gov_default');
    $custom = $config->get('custom_layout');
    if (is_array($custom) && $custom !== []) {
      $layout = $custom;
    }
    else {
      $layout = $this->loadProfile($profile);
    }

    $tenantId = $this->tenantId();
    $skin = $this->themeStudio->getActiveId();
    $company = $this->configFactory->get('dx_tenant.settings');
    $displayName = (string) ($company->get('company_name') ?: ($layout['theme']['display_name'] ?? 'DrupalX'));

    $layout['spec'] = 'DX-APP-LAYOUT';
    $layout['spec_version'] = '1.0';
    $layout['tenant_id'] = $tenantId;
    $layout['revision'] = $this->getRevision();
    $layout['min_shell_version'] = (string) ($config->get('min_shell_version') ?: '1.0.0');
    $layout['capabilities'] = array_values(array_map('strval', $config->get('capabilities') ?: ['share']));
    $layout['theme'] = array_merge(
      is_array($layout['theme'] ?? NULL) ? $layout['theme'] : [],
      [
        'pack' => $skin,
        'display_name' => $displayName,
      ],
    );
    if (empty($layout['layout_id'])) {
      $layout['layout_id'] = 'lay_' . $tenantId . '_' . $profile;
    }

    $checksumPayload = $layout;
    unset($checksumPayload['checksum']);
    $layout['checksum'] = 'sha256:' . hash('sha256', $this->canonicalJson($checksumPayload));

    return $layout;
  }

  /**
   * Bump revision after layout edits.
   */
  public function bumpRevision(): int {
    $editable = $this->configFactory->getEditable('dx_channel.settings');
    $next = $this->getRevision() + 1;
    $editable->set('revision', $next)->save();
    return $next;
  }

  /**
   * @return array<string, mixed>
   */
  protected function loadProfile(string $profile): array {
    $allowed = ['gov_default', 'ent_default'];
    if (!in_array($profile, $allowed, TRUE)) {
      $profile = 'gov_default';
    }
    $path = $this->moduleList->getPath('dx_channel') . '/data/layouts/' . $profile . '.json';
    if (!is_readable($path)) {
      return $this->fallbackLayout($profile);
    }
    $raw = file_get_contents($path);
    $data = json_decode($raw ?: '', TRUE);
    return is_array($data) ? $data : $this->fallbackLayout($profile);
  }

  /**
   * @return array<string, mixed>
   */
  protected function fallbackLayout(string $profile): array {
    return [
      'layout_id' => 'lay_fallback_' . $profile,
      'navigation' => [
        'type' => 'tab',
        'items' => [
          ['id' => 'home', 'label' => '首页', 'icon' => 'home', 'page' => 'page_home'],
        ],
      ],
      'pages' => [
        'page_home' => [
          'blocks' => [
            ['type' => 'hero_banner', 'props' => ['source' => 'channel:site.brand']],
            ['type' => 'article_list', 'props' => ['query' => ['type' => 'article', 'limit' => 10]]],
          ],
        ],
      ],
      'routes' => [
        'article_detail' => ['type' => 'article_detail', 'id_param' => 'id'],
      ],
      'theme' => [
        'pack' => $profile === 'ent_default' ? 'ent_trust' : 'gov_steady',
        'primary' => $profile === 'ent_default' ? '#0F766E' : '#1A365D',
      ],
    ];
  }

  protected function canonicalJson(array $data): string {
    return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  }

  protected function tenantId(): string {
    try {
      $sitePath = \Drupal::getContainer()->getParameter('site.path');
      if (is_string($sitePath) && $sitePath !== '') {
        return basename($sitePath);
      }
    }
    catch (\Throwable) {
      // Fall through.
    }
    return 'default';
  }

}
