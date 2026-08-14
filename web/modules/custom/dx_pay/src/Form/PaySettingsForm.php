<?php

declare(strict_types=1);

namespace Drupal\dx_pay\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Payment gateway settings.
 */
class PaySettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['dx_pay.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'dx_pay_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('dx_pay.settings');
    $state = \Drupal::state();

    $form['sandbox_mode'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Sandbox mode'),
      '#default_value' => (bool) $config->get('sandbox_mode'),
      '#description' => $this->t('Uses an on-site mock payment page instead of real WeChat/Alipay APIs.'),
    ];
    $form['default_gateway'] = [
      '#type' => 'select',
      '#title' => $this->t('Default gateway'),
      '#options' => [
        'wechat' => $this->t('WeChat Pay'),
        'alipay' => $this->t('Alipay'),
      ],
      '#default_value' => $config->get('default_gateway') ?: 'wechat',
    ];
    $form['currency'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Currency'),
      '#default_value' => $config->get('currency') ?: 'CNY',
      '#size' => 8,
    ];

    $form['wechat'] = [
      '#type' => 'details',
      '#title' => $this->t('WeChat Pay credentials'),
      '#open' => FALSE,
    ];
    $form['wechat']['wechat_mch_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Merchant ID'),
      '#default_value' => $state->get('dx_pay.wechat.mch_id') ?: '',
    ];
    $form['wechat']['wechat_api_key'] = [
      '#type' => 'password',
      '#title' => $this->t('API key'),
      '#description' => $state->get('dx_pay.wechat.api_key') ? $this->t('Key is set. Leave blank to keep.') : '',
    ];

    $form['alipay'] = [
      '#type' => 'details',
      '#title' => $this->t('Alipay credentials'),
      '#open' => FALSE,
    ];
    $form['alipay']['alipay_app_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('App ID'),
      '#default_value' => $state->get('dx_pay.alipay.app_id') ?: '',
    ];
    $form['alipay']['alipay_private_key'] = [
      '#type' => 'textarea',
      '#title' => $this->t('App private key'),
      '#rows' => 4,
      '#description' => $state->get('dx_pay.alipay.private_key') ? $this->t('Key is set. Leave blank to keep.') : '',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('dx_pay.settings')
      ->set('sandbox_mode', (bool) $form_state->getValue('sandbox_mode'))
      ->set('default_gateway', (string) $form_state->getValue('default_gateway'))
      ->set('currency', (string) $form_state->getValue('currency'))
      ->save();

    $state = \Drupal::state();
    if ($mch = trim((string) $form_state->getValue('wechat_mch_id'))) {
      $state->set('dx_pay.wechat.mch_id', $mch);
    }
    if ($key = (string) $form_state->getValue('wechat_api_key')) {
      $state->set('dx_pay.wechat.api_key', $key);
    }
    if ($app = trim((string) $form_state->getValue('alipay_app_id'))) {
      $state->set('dx_pay.alipay.app_id', $app);
    }
    if ($pk = trim((string) $form_state->getValue('alipay_private_key'))) {
      $state->set('dx_pay.alipay.private_key', $pk);
    }

    parent::submitForm($form, $form_state);
  }

}
