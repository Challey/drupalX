<?php

declare(strict_types=1);

namespace Drupal\dx_theme\Form;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\dx_theme\Service\ThemeCatalog;
use Drupal\dx_theme\Service\ThemeStudio;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Partner self-serve theme gallery (/dx/themes).
 */
final class ThemePartnerForm extends FormBase {

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
   * Route access: administer OR manage theme pack OR brand pack.
   */
  public function access(AccountInterface $account): AccessResult {
    return AccessResult::allowedIfHasPermissions($account, [
      'administer dx theme studio',
      'manage dx theme pack',
      'manage dx brand pack',
      'administer dx tenant settings',
    ], 'OR');
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'dx_theme_partner_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $active = $this->studio->getActiveId();
    $form['#attached']['library'][] = 'dx_theme/studio';
    $form['#attributes']['class'][] = 'dx-theme-studio';
    $form['#attributes']['class'][] = 'dx-theme-studio--partner';

    $form['intro'] = [
      '#markup' => '<div class="dx-theme-studio__intro"><p>' . $this->t(
        'Pick a curated portal look. Switching updates the site façade immediately. Fine-tune colors and logo in <a href=":brand">Brand portal</a>.',
        [':brand' => Url::fromRoute('dx_tenant.brand_portal')->toString()],
      ) . '</p></div>',
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
      $isActive = $id === $active;

      $form['gallery'][$id] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => array_filter([
            'dx-theme-tile',
            $isActive ? 'is-active' : NULL,
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
            . '</div>',
        ],
        'actions' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['dx-theme-tile__actions']],
          'apply' => [
            '#type' => 'submit',
            '#value' => $isActive ? $this->t('In use') : $this->t('Use this theme'),
            '#name' => 'apply_' . $id,
            '#skin_id' => $id,
            '#disabled' => $isActive,
            '#attributes' => ['class' => ['dx-theme-btn', 'dx-theme-btn--primary']],
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
    $skin = (string) ($trigger['#skin_id'] ?? '');
    if ($skin === '') {
      return;
    }
    $result = $this->studio->apply($skin);
    if ($result['ok']) {
      $this->messenger()->addStatus($this->t('Theme updated to @label.', [
        '@label' => $result['label'],
      ]));
      $form_state->setRedirect('<front>');
    }
    else {
      $this->messenger()->addError($result['message']);
    }
  }

}
