<?php

declare(strict_types=1);

namespace Drupal\dx_appstore\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * App Store whitelist / policy settings.
 */
class AppStoreSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['dx_appstore.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'dx_appstore_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('dx_appstore.settings');
    $form['allowed_modules'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Extra allowed modules'),
      '#description' => $this->t('One module machine name per line. Catalog apps are already whitelisted by module_name.'),
      '#default_value' => implode("\n", $config->get('allowed_modules') ?: []),
      '#rows' => 6,
    ];
    $form['allow_community_install'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow installing community-trust catalog apps'),
      '#default_value' => (bool) $config->get('allow_community_install'),
      '#description' => $this->t('When off, only security/stable trust levels can be enabled via pm:enable gate.'),
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $raw = (string) $form_state->getValue('allowed_modules');
    $modules = array_values(array_filter(array_map('trim', preg_split('/\R/', $raw) ?: [])));
    $this->config('dx_appstore.settings')
      ->set('allowed_modules', $modules)
      ->set('allow_community_install', (bool) $form_state->getValue('allow_community_install'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
