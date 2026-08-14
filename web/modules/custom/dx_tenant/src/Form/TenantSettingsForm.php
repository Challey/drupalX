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
    $state = \Drupal::state();

    $form['company'] = [
      '#type' => 'details',
      '#title' => $this->t('Company profile'),
      '#open' => TRUE,
    ];

    $form['company']['company_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Company name'),
      '#default_value' => $config->get('company_name'),
      '#required' => TRUE,
    ];

    $form['company']['industry'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Industry'),
      '#default_value' => $config->get('industry'),
    ];

    $form['company']['logo_fid'] = [
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
      '#title' => $this->t('AI configuration & knowledge'),
      '#open' => TRUE,
    ];

    $form['ai']['ai_quota_monthly'] = [
      '#type' => 'number',
      '#title' => $this->t('Monthly AI quota (tokens)'),
      '#description' => $this->t('Set 0 to fallback to platform default quota.'),
      '#default_value' => $config->get('ai_quota_monthly') ?? 100000,
      '#min' => 0,
      '#step' => 1000,
    ];

    $form['ai']['ai_default_provider'] = [
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

    $form['ai']['ai_system_prompt'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Custom AI system prompt'),
      '#description' => $this->t('Overrides or enhances the platform AI system prompt for this tenant.'),
      '#default_value' => $config->get('ai_system_prompt') ?: '',
      '#rows' => 3,
    ];

    $form['ai']['ai_knowledge_intro'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Company & products background knowledge'),
      '#description' => $this->t('Key company facts, business hours, FAQ, and core offerings injected directly into AI context.'),
      '#default_value' => $config->get('ai_knowledge_intro') ?: '',
      '#rows' => 4,
    ];

    $form['ai']['keys'] = [
      '#type' => 'details',
      '#title' => $this->t('Tenant-specific API keys (optional override)'),
      '#open' => FALSE,
      '#description' => $this->t('Leave blank to use platform-level API keys.'),
    ];

    $providers = ['deepseek' => 'DeepSeek', 'qwen' => '通义千问', 'zhipu' => '智谱 GLM', 'openai' => 'OpenAI'];
    foreach ($providers as $pid => $pname) {
      $hasKey = (bool) $state->get('dx_tenant.ai_keys.' . $pid);
      $form['ai']['keys']['key_' . $pid] = [
        '#type' => 'password',
        '#title' => $this->t('@name API Key', ['@name' => $pname]),
        '#description' => $hasKey ? $this->t('Custom key is configured. Leave blank to keep.') : $this->t('Using platform default key.'),
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

    $state = \Drupal::state();
    $providers = ['deepseek', 'qwen', 'zhipu', 'openai'];
    foreach ($providers as $pid) {
      $keyVal = $form_state->getValue('key_' . $pid);
      if (!empty($keyVal)) {
        $state->set('dx_tenant.ai_keys.' . $pid, (string) $keyVal);
      }
    }

    $this->config('dx_tenant.settings')
      ->set('company_name', $form_state->getValue('company_name'))
      ->set('industry', $form_state->getValue('industry'))
      ->set('logo_fid', $logoFid)
      ->set('ai_quota_monthly', (int) $form_state->getValue('ai_quota_monthly'))
      ->set('ai_default_provider', (string) $form_state->getValue('ai_default_provider'))
      ->set('ai_system_prompt', (string) $form_state->getValue('ai_system_prompt'))
      ->set('ai_knowledge_intro', (string) $form_state->getValue('ai_knowledge_intro'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}

