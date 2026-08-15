<?php

declare(strict_types=1);

namespace Drupal\dx_theme\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\dx_theme\Service\ThemeStudio;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Start / clear theme pack preview sessions.
 */
final class ThemePreviewController extends ControllerBase {

  public function __construct(
    protected ThemeStudio $studio,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('dx_theme.studio'));
  }

  /**
   * Start preview and redirect to front with query override.
   */
  public function start(string $skin): RedirectResponse {
    $result = $this->studio->startPreview($skin);
    if (!$result['ok']) {
      $this->messenger()->addError($result['message']);
      return new RedirectResponse(Url::fromRoute('dx_theme.studio')->toString());
    }
    $this->messenger()->addStatus($this->t('Previewing @skin. Apply from Theme Studio when ready.', [
      '@skin' => $skin,
    ]));
    return new RedirectResponse(Url::fromRoute('<front>', [], [
      'query' => [ThemeStudio::PREVIEW_QUERY => $skin],
    ])->toString());
  }

  /**
   * Clear preview and return to studio.
   */
  public function clear(): RedirectResponse {
    $this->studio->clearPreview();
    $this->messenger()->addStatus($this->t('Theme preview cleared.'));
    return new RedirectResponse(Url::fromRoute('dx_theme.studio')->toString());
  }

}
