<?php

declare(strict_types=1);

namespace Drupal\dx_opinion\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Opinion settings form.
 */
final class OpinionSettingsForm extends ConfigFormBase {

  public function getFormId(): string {
    return 'dx_opinion_settings';
  }

  protected function getEditableConfigNames(): array {
    return ['dx_opinion.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('dx_opinion.settings');
    $form['data_source_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('数据源模式'),
      '#options' => [
        'demo' => $this->t('演示（内置条目）'),
        'licensed' => $this->t('授权合规源'),
      ],
      '#default_value' => $config->get('data_source_mode') ?: 'demo',
    ];
    $form['compliance_notice'] = [
      '#type' => 'textarea',
      '#title' => $this->t('合规提示'),
      '#default_value' => $config->get('compliance_notice') ?: '',
      '#rows' => 2,
    ];
    $form['licensed_endpoint'] = [
      '#type' => 'textfield',
      '#title' => $this->t('授权源 Endpoint'),
      '#default_value' => $config->get('licensed_endpoint') ?: '',
      '#description' => $this->t('HTTPS JSON；example.com / fixture:// 用于本地演示。'),
    ];
    $form['licensed_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('授权源 Token'),
      '#default_value' => $config->get('licensed_token') ?: '',
    ];
    $form['keywords'] = [
      '#type' => 'textarea',
      '#title' => $this->t('监测关键词（每行一个）'),
      '#default_value' => implode("\n", $config->get('keywords') ?? []),
      '#rows' => 4,
    ];
    $form['demo_items_json'] = [
      '#type' => 'textarea',
      '#title' => $this->t('演示条目（JSON 数组）'),
      '#default_value' => json_encode($config->get('demo_items') ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
      '#rows' => 16,
      '#description' => $this->t('每项含 title / source / sentiment / url。'),
    ];
    return parent::buildForm($form, $form_state);
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $raw = trim((string) $form_state->getValue('demo_items_json'));
    $decoded = json_decode($raw, TRUE);
    if (!is_array($decoded)) {
      $form_state->setErrorByName('demo_items_json', $this->t('JSON 无效。'));
      return;
    }
    $form_state->setValue('demo_items_decoded', $decoded);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $keywords = preg_split('/\R+/', (string) $form_state->getValue('keywords')) ?: [];
    $keywords = array_values(array_filter(array_map('trim', $keywords), static fn(string $k): bool => $k !== ''));
    $this->config('dx_opinion.settings')
      ->set('data_source_mode', (string) $form_state->getValue('data_source_mode'))
      ->set('compliance_notice', (string) $form_state->getValue('compliance_notice'))
      ->set('licensed_endpoint', (string) $form_state->getValue('licensed_endpoint'))
      ->set('licensed_token', (string) $form_state->getValue('licensed_token'))
      ->set('keywords', $keywords)
      ->set('demo_items', $form_state->getValue('demo_items_decoded') ?? [])
      ->save();
    parent::submitForm($form, $form_state);
  }

}
