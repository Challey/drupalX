<?php

declare(strict_types=1);

namespace Drupal\dcn_tenant\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;

/**
 * Tenant settings configuration form.
 */
class TenantSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['dcn_tenant.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'dcn_tenant_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('dcn_tenant.settings');

    $form['company_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Company name'),
      '#default_value' => $config->get('company_name'),
      '#required' => TRUE,
    ];

    $form['industry'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Industry'),
      '#default_value' => $config->get('industry'),
    ];

    $form['logo_fid'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Company logo'),
      '#default_value' => $config->get('logo_fid') ? [$config->get('logo_fid')] : [],
      '#upload_location' => 'public://dcn-tenant/',
      '#upload_validators' => [
        'file_validate_extensions' => ['png jpg jpeg svg webp'],
      ],
    ];

    $form['ai_quota_monthly'] = [
      '#type' => 'number',
      '#title' => $this->t('Monthly AI quota (tokens)'),
      '#default_value' => $config->get('ai_quota_monthly') ?: 100000,
      '#min' => 0,
      '#step' => 1000,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $logoFid = NULL;
    $logoValues = $form_state->getValue('logo_fid');
    if (!empty($logoValues[0])) {
      $logoFid = (int) $logoValues[0];
      $file = File::load($logoFid);
      if ($file) {
        $file->setPermanent();
        $file->save();
      }
    }

    $this->config('dcn_tenant.settings')
      ->set('company_name', $form_state->getValue('company_name'))
      ->set('industry', $form_state->getValue('industry'))
      ->set('logo_fid', $logoFid)
      ->set('ai_quota_monthly', (int) $form_state->getValue('ai_quota_monthly'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
