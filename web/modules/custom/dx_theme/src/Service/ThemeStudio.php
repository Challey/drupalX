<?php

declare(strict_types=1);

namespace Drupal\dx_theme\Service;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Active theme pack + session/query preview for Theme Studio.
 */
final class ThemeStudio {

  public const PREVIEW_QUERY = 'dx_skin';

  public const PREVIEW_STORE = 'dx_theme_preview';

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected ThemeCatalog $catalog,
    protected PrivateTempStoreFactory $tempStoreFactory,
    protected AccountProxyInterface $currentUser,
    protected RequestStack $requestStack,
    protected CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {}

  /**
   * Persisted active skin id.
   */
  public function getActiveId(): string {
    $id = (string) ($this->configFactory->get('dx_theme.settings')->get('active_skin') ?: '');
    if ($id === '' || !$this->catalog->has($id)) {
      return $this->catalog->defaultSkinId();
    }
    return $id;
  }

  /**
   * Effective skin for the current request (preview overrides active).
   */
  public function getEffectiveId(): string {
    $preview = $this->getPreviewId();
    if ($preview !== NULL) {
      return $preview;
    }
    return $this->getActiveId();
  }

  /**
   * @return array<string, mixed>
   */
  public function getEffectiveSkin(): array {
    $id = $this->getEffectiveId();
    $skin = $this->catalog->get($id);
    if ($skin === NULL) {
      $skin = $this->catalog->get($this->catalog->defaultSkinId()) ?? [
        'id' => 'portal',
        'label' => 'Portal Teal',
        'body_class' => 'dx-skin--portal',
        'library' => NULL,
      ];
    }
    return $skin;
  }

  /**
   * Apply a curated skin (persisted).
   *
   * @return array{ok: bool, skin: string, label: string, message: string}
   */
  public function apply(string $id): array {
    if (!$this->catalog->has($id)) {
      return [
        'ok' => FALSE,
        'skin' => $this->getActiveId(),
        'label' => '',
        'message' => 'Unknown theme pack: ' . $id,
      ];
    }
    $this->configFactory->getEditable('dx_theme.settings')
      ->set('active_skin', $id)
      ->save();
    $this->clearPreview();
    $this->cacheTagsInvalidator->invalidateTags(['dx_theme', 'config:dx_theme.settings']);
    $skin = $this->catalog->get($id);
    $label = (string) ($skin['label'] ?? $id);
    return [
      'ok' => TRUE,
      'skin' => $id,
      'label' => $label,
      'message' => 'Applied theme pack: ' . $label,
    ];
  }

  /**
   * Start a private preview session for the given skin.
   *
   * @return array{ok: bool, skin: string, message: string}
   */
  public function startPreview(string $id): array {
    if (!$this->catalog->has($id)) {
      return [
        'ok' => FALSE,
        'skin' => $this->getActiveId(),
        'message' => 'Unknown theme pack: ' . $id,
      ];
    }
    $ttl = (int) ($this->configFactory->get('dx_theme.settings')->get('preview_ttl') ?: 1800);
    $store = $this->tempStoreFactory->get(self::PREVIEW_STORE);
    $store->set('skin', $id);
    $store->set('expires', time() + max(60, $ttl));
    return [
      'ok' => TRUE,
      'skin' => $id,
      'message' => 'Previewing theme pack: ' . $id,
    ];
  }

  /**
   * Clear session preview.
   */
  public function clearPreview(): void {
    try {
      $store = $this->tempStoreFactory->get(self::PREVIEW_STORE);
      $store->delete('skin');
      $store->delete('expires');
    }
    catch (\Throwable) {
      // Anonymous / no store — ignore.
    }
  }

  /**
   * Preview skin id if active, else NULL.
   */
  public function getPreviewId(): ?string {
    $request = $this->requestStack->getCurrentRequest();
    if ($request !== NULL) {
      $q = trim((string) $request->query->get(self::PREVIEW_QUERY, ''));
      if ($q !== '' && $this->catalog->has($q)) {
        return $q;
      }
    }

    if ($this->currentUser->isAnonymous()) {
      return NULL;
    }
    try {
      $store = $this->tempStoreFactory->get(self::PREVIEW_STORE);
      $id = $store->get('skin');
      $expires = (int) ($store->get('expires') ?: 0);
      if (!is_string($id) || $id === '' || !$this->catalog->has($id)) {
        return NULL;
      }
      if ($expires > 0 && time() > $expires) {
        $this->clearPreview();
        return NULL;
      }
      return $id;
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  /**
   * Whether the current request is in preview mode.
   */
  public function isPreviewing(): bool {
    return $this->getPreviewId() !== NULL;
  }

  /**
   * Status payload for CLI / dashboards (no secrets).
   *
   * @return array<string, mixed>
   */
  public function status(): array {
    $active = $this->getActiveId();
    $effective = $this->getEffectiveId();
    $skin = $this->catalog->get($active);
    return [
      'active_skin' => $active,
      'active_label' => (string) ($skin['label'] ?? $active),
      'effective_skin' => $effective,
      'previewing' => $this->isPreviewing(),
      'preview_skin' => $this->getPreviewId(),
      'catalog_count' => count($this->catalog->all()),
      'shell_theme' => 'dx_portal_theme',
    ];
  }

}
