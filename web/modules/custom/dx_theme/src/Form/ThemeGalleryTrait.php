<?php

declare(strict_types=1);

namespace Drupal\dx_theme\Form;

/**
 * Shared Theme Studio gallery tile builder (admin + partner).
 */
trait ThemeGalleryTrait {

  /**
   * Build a family-grouped gallery of skin tiles.
   *
   * @param array<string, mixed> $form
   * @param string $active
   * @param string|null $preview
   * @param bool $with_preview_button
   * @param bool $include_legacy
   *
   * @return array<string, mixed>
   */
  protected function buildFamilyGallery(
    array $form,
    string $active,
    ?string $preview,
    bool $with_preview_button,
    bool $include_legacy = FALSE,
  ): array {
    $families = $this->catalog->families();
    $grouped = $this->catalog->byFamily($include_legacy);

    $form['families'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['dx-theme-families']],
    ];

    foreach ($families as $familyId => $family) {
      $skins = $grouped[$familyId] ?? [];
      if ($skins === []) {
        continue;
      }
      $section = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['dx-theme-family', 'dx-theme-family--' . preg_replace('/[^a-z0-9_-]+/', '-', $familyId)],
          'data-family' => $familyId,
        ],
        'heading' => [
          '#markup' => '<div class="dx-theme-family__head">'
            . '<h2 class="dx-theme-family__title">' . $this->escape((string) $family['label']) . '</h2>'
            . '<p class="dx-theme-family__summary">' . $this->escape((string) $family['summary']) . '</p>'
            . '</div>',
        ],
        'gallery' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['dx-theme-gallery'], 'role' => 'list'],
        ],
      ];

      foreach ($skins as $skin) {
        $id = (string) ($skin['id'] ?? '');
        if ($id === '') {
          continue;
        }
        $section['gallery'][$id] = $this->buildSkinTile($id, $skin, $active, $preview, $with_preview_button);
      }

      $form['families'][$familyId] = $section;
    }

    return $form;
  }

  /**
   * @param array<string, mixed> $skin
   *
   * @return array<string, mixed>
   */
  protected function buildSkinTile(
    string $id,
    array $skin,
    string $active,
    ?string $preview,
    bool $with_preview_button,
  ): array {
    $swatches = is_array($skin['swatches'] ?? NULL) ? $skin['swatches'] : [];
    $paper = $this->escape((string) ($swatches['paper'] ?? '#f5f6f8'));
    $ink = $this->escape((string) ($swatches['ink'] ?? '#0f1419'));
    $accent = $this->escape((string) ($swatches['accent'] ?? '#0d6e6d'));
    $label = (string) ($skin['label'] ?? $id);
    $summary = (string) ($skin['summary'] ?? '');
    $persona = (string) ($skin['persona'] ?? '');
    $density = (string) ($skin['density'] ?? '');
    $mood = (string) ($skin['mood'] ?? '');
    $isActive = $id === $active;

    $tags = array_filter([$persona, $mood, $density]);
    $tagHtml = '';
    foreach ($tags as $tag) {
      $tagHtml .= '<span>' . $this->escape($tag) . '</span>';
    }

    $tile = [
      '#type' => 'container',
      '#attributes' => [
        'class' => array_filter([
          'dx-theme-tile',
          $isActive ? 'is-active' : NULL,
          $preview === $id ? 'is-preview' : NULL,
          !empty($skin['legacy']) ? 'is-legacy' : NULL,
        ]),
        'role' => 'listitem',
        'style' => '--dx-tile-paper:' . $paper . ';--dx-tile-ink:' . $ink . ';--dx-tile-accent:' . $accent . ';',
      ],
      'visual' => [
        '#markup' => '<div class="dx-theme-tile__visual" aria-hidden="true">'
          . '<span class="dx-theme-tile__plane"></span>'
          . '<span class="dx-theme-tile__accent"></span>'
          . '<span class="dx-theme-tile__brand">' . $this->escape($label) . '</span>'
          . '</div>',
      ],
      'meta' => [
        '#markup' => '<div class="dx-theme-tile__meta">'
          . '<h3 class="dx-theme-tile__title">' . $this->escape($label) . '</h3>'
          . '<p class="dx-theme-tile__summary">' . $this->escape($summary) . '</p>'
          . ($tagHtml !== '' ? '<p class="dx-theme-tile__tags">' . $tagHtml . '</p>' : '')
          . '</div>',
      ],
      'actions' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['dx-theme-tile__actions']],
        'apply' => [
          '#type' => 'submit',
          '#value' => $isActive
            ? ($with_preview_button ? $this->t('Active') : $this->t('In use'))
            : ($with_preview_button ? $this->t('Apply') : $this->t('Use this theme')),
          '#name' => 'apply_' . $id,
          '#skin_id' => $id,
          '#op' => 'apply',
          '#disabled' => $isActive,
          '#attributes' => ['class' => ['dx-theme-btn', 'dx-theme-btn--primary']],
        ],
      ],
    ];

    if ($with_preview_button) {
      $tile['actions']['preview'] = [
        '#type' => 'submit',
        '#value' => $this->t('Preview'),
        '#name' => 'preview_' . $id,
        '#skin_id' => $id,
        '#op' => 'preview',
        '#attributes' => ['class' => ['dx-theme-btn', 'dx-theme-btn--ghost']],
      ];
    }

    return $tile;
  }

  protected function escape(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }

}
