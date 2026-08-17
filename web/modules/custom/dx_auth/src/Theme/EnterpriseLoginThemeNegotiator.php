<?php

declare(strict_types=1);

namespace Drupal\dx_auth\Theme;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Theme\ThemeNegotiatorInterface;

/**
 * Forces the portal theme on /user/login so enterprise ID UI is available
 * even when the tenant default theme is gavias_kiamo (or another pack).
 */
class EnterpriseLoginThemeNegotiator implements ThemeNegotiatorInterface {

  private const LOGIN_THEME = 'dx_portal_theme';

  public function __construct(
    protected ThemeHandlerInterface $themeHandler,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match): bool {
    $route = $route_match->getRouteName();
    if ($route !== 'user.login') {
      return FALSE;
    }
    if (!$this->themeHandler->themeExists(self::LOGIN_THEME)) {
      return FALSE;
    }
    // Respect sites that already use the portal theme as default.
    $default = (string) $this->configFactory->get('system.theme')->get('default');
    return $default !== self::LOGIN_THEME;
  }

  /**
   * {@inheritdoc}
   */
  public function determineActiveTheme(RouteMatchInterface $route_match): ?string {
    return self::LOGIN_THEME;
  }

}
