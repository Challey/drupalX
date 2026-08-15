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

  use ThemeGalleryTrait;

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
        'Theme Studio groups curated façades into <strong>government</strong> (leader persona) and <strong>enterprise</strong> (company culture). One-click switch; white-label colors still overlay the active pack. Partner: <a href=":partner">/dx/themes</a>. CLI: <code>drush dx:theme-list</code> · <code>drush dx:theme-apply SKIN</code>.',
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

    $activeSkin = $this->catalog->get($active);
    $familyId = (string) ($activeSkin['family'] ?? 'universal');
    $familyLabel = (string) ($this->catalog->families()[$familyId]['label'] ?? $familyId);
    $form['status'] = [
      '#markup' => '<p class="dx-theme-studio__status">' . $this->t('Active pack: <strong>@label</strong> (<code>@id</code>) · @family · @persona', [
        '@label' => (string) ($activeSkin['label'] ?? $active),
        '@id' => $active,
        '@family' => $familyLabel,
        '@persona' => (string) ($activeSkin['persona'] ?? '—'),
      ]) . '</p>',
    ];

    // Primary gallery: government + enterprise + universal (no legacy).
    $form = $this->buildFamilyGallery($form, $active, $preview, TRUE, FALSE);

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
