<?php

declare(strict_types=1);

namespace Drupal\dx_channel\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\dx_theme\Service\ThemeStudio;

/**
 * Projects Channel /site payload from tenant + theme + layout.
 */
final class SiteProjector {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected AppLayoutRepository $appLayout,
    protected ThemeStudio $themeStudio,
  ) {}

  /**
   * @return array<string, mixed>
   */
  public function project(): array {
    $tenant = $this->configFactory->get('dx_tenant.settings');
    $layout = $this->appLayout->getLayout();
    $skin = $this->themeStudio->getEffectiveSkin();
    $name = (string) ($tenant->get('company_name') ?: ($layout['theme']['display_name'] ?? 'DrupalX'));

    return [
      'org_profile' => [
        'id' => 'org_' . ($layout['tenant_id'] ?? 'default'),
        'type' => 'org_profile',
        'title' => $name,
        'org_type' => str_starts_with((string) ($layout['theme']['pack'] ?? ''), 'gov_') ? 'government' : 'enterprise',
        'contact' => [
          'website' => NULL,
        ],
        'brand' => [
          'display_name' => $name,
          'logo_url' => NULL,
          'theme_pack' => $this->themeStudio->getActiveId(),
        ],
      ],
      'theme' => [
        'pack' => $this->themeStudio->getActiveId(),
        'label' => (string) ($skin['label'] ?? $this->themeStudio->getActiveId()),
        'primary' => $layout['theme']['primary'] ?? NULL,
      ],
      'layout' => [
        'revision' => $this->appLayout->getRevision(),
        'min_shell_version' => $layout['min_shell_version'] ?? '1.0.0',
        'checksum' => $layout['checksum'] ?? NULL,
      ],
      'capabilities' => $layout['capabilities'] ?? [],
      'channels' => ['web', 'miniprogram', 'app'],
    ];
  }

}
