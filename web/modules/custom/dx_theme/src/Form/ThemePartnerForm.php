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
        'Choose a façade by sector: <strong>government</strong> packs follow leader persona (steady, passionate, decisive…); <strong>enterprise</strong> packs follow company culture (driven, fashion, innovative…). Fine-tune colors in <a href=":brand">Brand portal</a>.',
        [':brand' => Url::fromRoute('dx_tenant.brand_portal')->toString()],
      ) . '</p></div>',
    ];

    $form = $this->buildFamilyGallery($form, $active, NULL, FALSE, FALSE);

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
