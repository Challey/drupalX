<?php

declare(strict_types=1);

namespace Drupal\dx_theme\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\dx_theme\Service\ThemeCatalog;
use Drupal\dx_theme\Service\ThemeStudio;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin Theme Studio gallery — one-click apply + preview.
 */
final class ThemeStudioForm extends FormBase {

  public function __construct(
    protected ThemeStudio $studio,
    protected ThemeCatalog $catalog,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('dx_theme.studio'),
      $container->get('dx_theme.catalog'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'dx_theme_studio_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $active = $this->studio->getActiveId();
    $preview = $this->studio->getPreviewId();
    $form['#attached']['library'][] = 'dx_theme/studio';
    $form['#attributes']['class'][] = 'dx-theme-studio';

    $form['intro'] = [
      '#markup' => '<div class="dx-theme-studio__intro"><p>' . $this->t(
        'Theme Studio is the portal façade: curated looks and interaction density you can switch in one click. White-label colors still overlay the active pack. Partner self-serve: <a href=":partner">/dx/themes</a>. CLI: <code>drush dx:theme-list</code> · <code>drush dx:theme-apply SKIN</code>.',
        [':partner' => Url::fromRoute('dx_theme.partner')->toString()],
      ) . '</p></div>',
    ];

    if ($preview) {
      $form['preview_banner'] = [
        '#markup' => '<div class="dx-theme-studio__banner" role="status">' . $this->t(
          'Previewing <strong>@skin</strong> (not saved). <a href=":front" target="_blank" rel="noopener">Open front page</a> · <a href=":clear">Exit preview</a>',
          [
            '@skin' => $preview,
            ':front' => Url::fromRoute('<front>', [], [
              'query' => [ThemeStudio::PREVIEW_QUERY => $preview],
              'absolute' => TRUE,
            ])->toString(),
            ':clear' => Url::fromRoute('dx_theme.studio_clear_preview')->toString(),
          ],
        ) . '</div>',
      ];
    }

    $form['status'] = [
      '#markup' => '<p class="dx-theme-studio__status">' . $this->t('Active pack: <strong>@label</strong> (<code>@id</code>)', [
        '@label' => (string) ($this->catalog->get($active)['label'] ?? $active),
        '@id' => $active,
      ]) . '</p>',
    ];

    $form['gallery'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['dx-theme-gallery'], 'role' => 'list'],
    ];

    foreach ($this->catalog->all() as $id => $skin) {
      $swatches = is_array($skin['swatches'] ?? NULL) ? $skin['swatches'] : [];
      $paper = htmlspecialchars((string) ($swatches['paper'] ?? '#f5f6f8'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
      $ink = htmlspecialchars((string) ($swatches['ink'] ?? '#0f1419'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
      $accent = htmlspecialchars((string) ($swatches['accent'] ?? '#0d6e6d'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
      $label = (string) ($skin['label'] ?? $id);
      $summary = (string) ($skin['summary'] ?? '');
      $density = (string) ($skin['density'] ?? '');
      $mood = (string) ($skin['mood'] ?? '');
      $isActive = $id === $active;

      $form['gallery'][$id] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => array_filter([
            'dx-theme-tile',
            $isActive ? 'is-active' : NULL,
            $preview === $id ? 'is-preview' : NULL,
          ]),
          'role' => 'listitem',
          'style' => '--dx-tile-paper:' . $paper . ';--dx-tile-ink:' . $ink . ';--dx-tile-accent:' . $accent . ';',
        ],
        'visual' => [
          '#markup' => '<div class="dx-theme-tile__visual" aria-hidden="true">'
            . '<span class="dx-theme-tile__plane"></span>'
            . '<span class="dx-theme-tile__accent"></span>'
            . '<span class="dx-theme-tile__brand">' . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>'
            . '</div>',
        ],
        'meta' => [
          '#markup' => '<div class="dx-theme-tile__meta">'
            . '<h3 class="dx-theme-tile__title">' . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h3>'
            . '<p class="dx-theme-tile__summary">' . htmlspecialchars($summary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
            . '<p class="dx-theme-tile__tags"><span>' . htmlspecialchars($mood, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>'
            . '<span>' . htmlspecialchars($density, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span></p>'
            . '</div>',
        ],
        'actions' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['dx-theme-tile__actions']],
          'apply' => [
            '#type' => 'submit',
            '#value' => $isActive ? $this->t('Active') : $this->t('Apply'),
            '#name' => 'apply_' . $id,
            '#skin_id' => $id,
            '#op' => 'apply',
            '#disabled' => $isActive,
            '#attributes' => ['class' => ['dx-theme-btn', 'dx-theme-btn--primary']],
          ],
          'preview' => [
            '#type' => 'submit',
            '#value' => $this->t('Preview'),
            '#name' => 'preview_' . $id,
            '#skin_id' => $id,
            '#op' => 'preview',
            '#attributes' => ['class' => ['dx-theme-btn', 'dx-theme-btn--ghost']],
          ],
        ],
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $trigger = $form_state->getTriggeringElement();
    $op = (string) ($trigger['#op'] ?? '');
    $skin = (string) ($trigger['#skin_id'] ?? '');
    if ($skin === '') {
      return;
    }
    if ($op === 'preview') {
      $result = $this->studio->startPreview($skin);
      if ($result['ok']) {
        $this->messenger()->addStatus($this->t('Preview started for @skin. Open the front page to review, then Apply to save.', [
          '@skin' => $skin,
        ]));
        $form_state->setRedirect('dx_theme.studio');
      }
      else {
        $this->messenger()->addError($result['message']);
      }
      return;
    }
    if ($op === 'apply') {
      $result = $this->studio->apply($skin);
      if ($result['ok']) {
        $this->messenger()->addStatus($this->t('Theme pack @label is now active.', [
          '@label' => $result['label'],
        ]));
      }
      else {
        $this->messenger()->addError($result['message']);
      }
    }
  }

}
