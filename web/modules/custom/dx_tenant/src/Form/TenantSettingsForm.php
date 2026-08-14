<?php

declare(strict_types=1);

namespace Drupal\dx_tenant\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\dx_ai_gateway\Service\AiGateway;
use Drupal\file\Entity\File;

/**
 * Tenant settings configuration form.
 */
class TenantSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['dx_tenant.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'dx_tenant_settings_form';
  }

  /**
   * Optional AI gateway service when the module is enabled.
   */
  protected function aiGateway(): ?AiGateway {
    if (!\Drupal::hasService('dx_ai_gateway.gateway')) {
      return NULL;
    }
    $gateway = \Drupal::service('dx_ai_gateway.gateway');
    return $gateway instanceof AiGateway ? $gateway : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('dx_tenant.settings');

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
      '#upload_location' => 'public://dx-tenant/',
      '#upload_validators' => [
        'file_validate_extensions' => ['png jpg jpeg svg webp'],
      ],
    ];

    $form['ai'] = [
      '#type' => 'details',
      '#title' => $this->t('AI overrides (tenant)'),
      '#open' => TRUE,
      '#description' => $this->t('Platform defaults apply unless overrides are enabled below.'),
    ];

    $form['ai']['ai_quota_override'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Override monthly AI quota'),
      '#default_value' => (bool) $config->get('ai_quota_override'),
    ];

    $form['ai']['ai_quota_monthly'] = [
      '#type' => 'number',
      '#title' => $this->t('Monthly AI quota (tokens)'),
      '#default_value' => $config->get('ai_quota_monthly') ?: 100000,
      '#min' => 0,
      '#step' => 1000,
      '#states' => [
        'visible' => [
          ':input[name="ai_quota_override"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['ai']['ai_keys_override'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Override AI provider API keys'),
      '#default_value' => (bool) $config->get('ai_keys_override'),
      '#description' => $this->t('When enabled, tenant keys take precedence over platform keys for this site.'),
    ];

    $gateway = $this->aiGateway();
    if ($gateway) {
      $form['ai']['keys'] = [
        '#type' => 'details',
        '#title' => $this->t('Tenant API keys'),
        '#open' => FALSE,
        '#tree' => TRUE,
        '#states' => [
          'visible' => [
            ':input[name="ai_keys_override"]' => ['checked' => TRUE],
          ],
        ],
      ];
      foreach ($gateway->getProviders() as $id => $provider) {
        $source = $gateway->keySource($id);
        $form['ai']['keys'][$id] = [
          '#type' => 'password',
          '#title' => $this->t('@label key', ['@label' => $provider['label'] ?? $id]),
          '#description' => $source === 'tenant'
            ? $this->t('Tenant key is set. Leave blank to keep.')
            : ($source === 'platform'
              ? $this->t('Using platform key. Enter a value to override.')
              : $this->t('No key configured.')),
        ];
      }
    }

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

    $this->config('dx_tenant.settings')
      ->set('company_name', $form_state->getValue('company_name'))
      ->set('industry', $form_state->getValue('industry'))
      ->set('logo_fid', $logoFid)
      ->set('ai_quota_override', (bool) $form_state->getValue('ai_quota_override'))
      ->set('ai_quota_monthly', (int) $form_state->getValue('ai_quota_monthly'))
      ->set('ai_keys_override', (bool) $form_state->getValue('ai_keys_override'))
      ->save();

    $gateway = $this->aiGateway();
    if ($gateway && $form_state->getValue('ai_keys_override')) {
      $keys = $form_state->getValue('keys') ?: [];
      foreach ($keys as $id => $value) {
        if (is_string($value) && $value !== '') {
          $gateway->setTenantApiKey((string) $id, $value);
        }
      }
    }

    parent::submitForm($form, $form_state);
  }

}
