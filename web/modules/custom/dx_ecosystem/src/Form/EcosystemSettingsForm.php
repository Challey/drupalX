<?php

declare(strict_types=1);

namespace Drupal\dx_ecosystem\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Product switches for open ecosystem (personal registration off by default).
 */
final class EcosystemSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['dx_ecosystem.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'dx_ecosystem_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('dx_ecosystem.settings');
    $form['personal_registration_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable personal tenant registration (Wave P)'),
      '#description' => $this->t('O6-A: architecture reserved; keep disabled until personal platform opens.'),
      '#default_value' => (bool) $config->get('personal_registration_enabled'),
    ];
    $form['require_ral_on_install'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Require DX-RAL acknowledgment on App Store install'),
      '#default_value' => (bool) $config->get('require_ral_on_install'),
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('dx_ecosystem.settings')
      ->set('personal_registration_enabled', (bool) $form_state->getValue('personal_registration_enabled'))
      ->set('require_ral_on_install', (bool) $form_state->getValue('require_ral_on_install'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
