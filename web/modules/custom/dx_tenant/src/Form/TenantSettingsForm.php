<?php

declare(strict_types=1);

namespace Drupal\dx_tenant\Form;

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
    return ['dx_tenant.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'dx_tenant_settings_form';
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

    $form['ai_quota_override'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Override the platform AI quota'),
      '#default_value' => (bool) $config->get('ai_quota_override'),
      '#description' => $this->t('When disabled, this tenant follows the AI gateway platform default.'),
    ];

    $quota = $config->get('ai_quota_monthly');
    $form['ai_quota_monthly'] = [
      '#type' => 'number',
      '#title' => $this->t('Tenant monthly AI quota (tokens)'),
      '#default_value' => $quota === NULL ? 100000 : $quota,
      '#min' => 0,
      '#step' => 1000,
      '#description' => $this->t('Set to 0 to disable AI requests for this tenant.'),
      '#states' => [
        'enabled' => [
          ':input[name="ai_quota_override"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['ai_default_provider'] = [
      '#type' => 'select',
      '#title' => $this->t('Default AI provider override'),
      '#options' => [
        '' => $this->t('- Use platform default -'),
        'deepseek' => 'DeepSeek',
        'qwen' => '通义千问 (Qwen)',
        'zhipu' => '智谱 GLM',
        'openai' => 'OpenAI',
      ],
      '#default_value' => $config->get('ai_default_provider') ?: '',
    ];

    $form['ai_system_prompt'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Custom AI system prompt'),
      '#description' => $this->t('Overrides the platform AI system prompt for this tenant.'),
      '#default_value' => $config->get('ai_system_prompt') ?: '',
      '#rows' => 3,
    ];

    $form['ai_knowledge_intro'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Company and product background knowledge'),
      '#description' => $this->t('Company facts, business hours, FAQ, and core offerings injected into AI context.'),
      '#default_value' => $config->get('ai_knowledge_intro') ?: '',
      '#rows' => 4,
    ];

    $state = \Drupal::state();
    $form['ai_keys'] = [
      '#type' => 'details',
      '#title' => $this->t('Tenant-specific API keys'),
      '#description' => $this->t('Leave blank to keep the current site override or inherit the platform environment key.'),
      '#open' => FALSE,
    ];
    foreach (['deepseek' => 'DeepSeek', 'qwen' => '通义千问', 'zhipu' => '智谱 GLM', 'openai' => 'OpenAI'] as $id => $label) {
      $hasKey = (bool) $state->get('dx_ai_gateway.api_keys.' . $id);
      $form['ai_keys']['key_' . $id] = [
        '#type' => 'password',
        '#title' => $this->t('@label API key', ['@label' => $label]),
        '#description' => $hasKey
          ? $this->t('A site override is configured. Leave blank to keep it.')
          : $this->t('No site override; the platform environment key is inherited when available.'),
      ];
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

    foreach (['deepseek', 'qwen', 'zhipu', 'openai'] as $id) {
      $key = $form_state->getValue('key_' . $id);
      if (is_string($key) && $key !== '') {
        \Drupal::state()->set('dx_ai_gateway.api_keys.' . $id, $key);
      }
    }

    $this->config('dx_tenant.settings')
      ->set('company_name', $form_state->getValue('company_name'))
      ->set('industry', $form_state->getValue('industry'))
      ->set('logo_fid', $logoFid)
      ->set('ai_quota_override', (bool) $form_state->getValue('ai_quota_override'))
      ->set('ai_quota_monthly', (int) $form_state->getValue('ai_quota_monthly'))
      ->set('ai_default_provider', (string) $form_state->getValue('ai_default_provider'))
      ->set('ai_system_prompt', (string) $form_state->getValue('ai_system_prompt'))
      ->set('ai_knowledge_intro', (string) $form_state->getValue('ai_knowledge_intro'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
