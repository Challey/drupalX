<?php

declare(strict_types=1);

namespace Drupal\dx_trust\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Trust policy settings form.
 */
final class TrustSettingsForm extends ConfigFormBase {

  public function getFormId(): string {
    return 'dx_trust_settings';
  }

  protected function getEditableConfigNames(): array {
    return ['dx_trust.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $c = $this->config('dx_trust.settings');
    $form['profile'] = [
      '#type' => 'select',
      '#title' => $this->t('策略档案'),
      '#options' => [
        'government_default' => $this->t('政务默认（收紧）'),
        'enterprise_default' => $this->t('企业默认'),
        'custom' => $this->t('自定义'),
      ],
      '#default_value' => $c->get('profile') ?: 'government_default',
    ];
    $form['allowed_trust_tiers'] = [
      '#type' => 'textfield',
      '#title' => $this->t('允许的 trust tiers（逗号分隔）'),
      '#default_value' => implode(',', $c->get('allowed_trust_tiers') ?? []),
      '#description' => $this->t('例如 platform,security,curated,demo'),
    ];
    $form['block_community'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('阻断 community 自动安装'),
      '#default_value' => (bool) $c->get('block_community'),
    ];
    $form['require_manual_approve_community'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('community 须人工批准'),
      '#default_value' => (bool) $c->get('require_manual_approve_community'),
    ];
    $form['require_content_review'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('内容导入默认进入审核'),
      '#default_value' => (bool) $c->get('require_content_review'),
    ];
    $form['audit_retention_days'] = [
      '#type' => 'number',
      '#title' => $this->t('审计保留天数'),
      '#default_value' => (int) ($c->get('audit_retention_days') ?: 365),
      '#min' => 30,
    ];
    $form['notes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('说明'),
      '#default_value' => $c->get('notes') ?: '',
    ];
    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $tiers = array_values(array_filter(array_map('trim', explode(',', (string) $form_state->getValue('allowed_trust_tiers')))));
    $this->config('dx_trust.settings')
      ->set('profile', $form_state->getValue('profile'))
      ->set('allowed_trust_tiers', $tiers)
      ->set('block_community', (bool) $form_state->getValue('block_community'))
      ->set('require_manual_approve_community', (bool) $form_state->getValue('require_manual_approve_community'))
      ->set('require_content_review', (bool) $form_state->getValue('require_content_review'))
      ->set('audit_retention_days', (int) $form_state->getValue('audit_retention_days'))
      ->set('notes', (string) $form_state->getValue('notes'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
