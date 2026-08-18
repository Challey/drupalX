<?php

declare(strict_types=1);

namespace Drupal\dx_auth\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provider credentials for WeChat / Aliyun SMS / Google (Topstar-compatible).
 */
class AuthProviderSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['dx_auth.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'dx_auth_provider_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    // Ensure config object exists (module may have been enabled without install config).
    $editable = $this->configFactory()->getEditable('dx_auth.settings');
    if ($editable->isNew()) {
      $editable
        ->set('wechat_enabled', FALSE)
        ->set('wechat_app_id', '')
        ->set('wechat_secret', '')
        ->set('wechat_token', '')
        ->set('wechat_switch', FALSE)
        ->set('sms_enabled', FALSE)
        ->set('sms_access_key', '')
        ->set('sms_access_secret', '')
        ->set('sms_sign_name', 'DrupalX')
        ->set('sms_template_code', '')
        ->set('google_enabled', FALSE)
        ->set('google_client_id', '')
        ->set('google_client_secret', '')
        ->set('google_redirect_uri', '')
        ->set('google_ignore_geo', FALSE)
        ->set('account_auto_register', TRUE)
        ->save();
    }
    $c = $this->config('dx_auth.settings');

    $form['account'] = [
      '#type' => 'details',
      '#title' => $this->t('Account login (email / username)'),
      '#open' => TRUE,
    ];
    $form['account']['account_auto_register'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Auto-register when email+password is used and the account does not exist'),
      '#default_value' => $c->get('account_auto_register') === NULL ? TRUE : (bool) $c->get('account_auto_register'),
      '#description' => $this->t('Matches WeChat / SMS / Google first-time create. Username (non-email) never auto-registers.'),
    ];

    $form['wechat'] = [
      '#type' => 'details',
      '#title' => $this->t('WeChat Official Account (Topstar wechatquery)'),
      '#open' => TRUE,
    ];
    $form['wechat']['wechat_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable WeChat login'),
      '#default_value' => (bool) $c->get('wechat_enabled'),
    ];
    $form['wechat']['wechat_app_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('AppID'),
      '#default_value' => $c->get('wechat_app_id'),
    ];
    $form['wechat']['wechat_secret'] = [
      '#type' => 'password',
      '#title' => $this->t('AppSecret'),
      '#description' => $this->t('Leave blank to keep the current secret.'),
    ];
    $form['wechat']['wechat_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Token (MP server URL verify)'),
      '#default_value' => $c->get('wechat_token'),
    ];
    $form['wechat']['wechat_switch'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Echostr verify mode (enable briefly when binding MP URL)'),
      '#default_value' => (bool) $c->get('wechat_switch'),
    ];
    $form['wechat']['callback'] = [
      '#type' => 'item',
      '#markup' => $this->t('MP server URL: <code>@u</code>', [
        '@u' => \Drupal::request()->getSchemeAndHttpHost() . '/dx/auth/wechat_callback',
      ]),
    ];

    $form['sms'] = [
      '#type' => 'details',
      '#title' => $this->t('Aliyun SMS OTP (Topstar aliyunsms)'),
      '#open' => TRUE,
    ];
    $form['sms']['sms_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable mobile SMS login'),
      '#default_value' => (bool) $c->get('sms_enabled'),
    ];
    $form['sms']['sms_access_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('AccessKey ID'),
      '#default_value' => $c->get('sms_access_key'),
    ];
    $form['sms']['sms_access_secret'] = [
      '#type' => 'password',
      '#title' => $this->t('AccessKey Secret'),
      '#description' => $this->t('Leave blank to keep the current secret. Reuse Topstar aliyunsms.settings keys if desired.'),
    ];
    $form['sms']['sms_sign_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Sign name'),
      '#default_value' => $c->get('sms_sign_name') ?: 'DrupalX',
      '#description' => $this->t('Topstar uses 跑者之星 — configure a DrupalX-approved sign in Aliyun console.'),
    ];
    $form['sms']['sms_template_code'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Template code'),
      '#default_value' => $c->get('sms_template_code'),
      '#description' => $this->t('Topstar template SMS_465170903; use a template with ${code}.'),
    ];

    $form['google'] = [
      '#type' => 'details',
      '#title' => $this->t('Google OAuth (solo block below other methods)'),
      '#open' => TRUE,
    ];
    $form['google']['google_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Google login'),
      '#default_value' => (bool) $c->get('google_enabled'),
    ];
    $form['google']['google_client_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Client ID'),
      '#default_value' => $c->get('google_client_id'),
    ];
    $form['google']['google_client_secret'] = [
      '#type' => 'password',
      '#title' => $this->t('Client secret'),
      '#description' => $this->t('Leave blank to keep the current secret.'),
    ];
    $form['google']['google_redirect_uri'] = [
      '#type' => 'url',
      '#title' => $this->t('Redirect URI override'),
      '#default_value' => $c->get('google_redirect_uri'),
      '#description' => $this->t('Default: @u — must match Google Console exactly.', [
        '@u' => \Drupal::request()->getSchemeAndHttpHost() . '/dx/auth/google_jump',
      ]),
    ];
    $form['google']['google_ignore_geo'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Ignore mainland China geo gate (debug)'),
      '#default_value' => (bool) $c->get('google_ignore_geo'),
      '#description' => $this->t('Topstar hides Google for mainland CN; HK/MO/TW/overseas shown.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->configFactory()->getEditable('dx_auth.settings');
    $config
      ->set('wechat_enabled', (bool) $form_state->getValue('wechat_enabled'))
      ->set('wechat_app_id', trim((string) $form_state->getValue('wechat_app_id')))
      ->set('wechat_token', trim((string) $form_state->getValue('wechat_token')))
      ->set('wechat_switch', (bool) $form_state->getValue('wechat_switch'))
      ->set('sms_enabled', (bool) $form_state->getValue('sms_enabled'))
      ->set('sms_access_key', trim((string) $form_state->getValue('sms_access_key')))
      ->set('sms_sign_name', trim((string) $form_state->getValue('sms_sign_name')))
      ->set('sms_template_code', trim((string) $form_state->getValue('sms_template_code')))
      ->set('google_enabled', (bool) $form_state->getValue('google_enabled'))
      ->set('google_client_id', trim((string) $form_state->getValue('google_client_id')))
      ->set('google_redirect_uri', trim((string) $form_state->getValue('google_redirect_uri')))
      ->set('google_ignore_geo', (bool) $form_state->getValue('google_ignore_geo'))
      ->set('account_auto_register', (bool) $form_state->getValue('account_auto_register'));

    $secret = (string) $form_state->getValue('wechat_secret');
    if ($secret !== '') {
      $config->set('wechat_secret', $secret);
    }
    $smsSecret = (string) $form_state->getValue('sms_access_secret');
    if ($smsSecret !== '') {
      $config->set('sms_access_secret', $smsSecret);
    }
    $gSecret = (string) $form_state->getValue('google_client_secret');
    if ($gSecret !== '') {
      $config->set('google_client_secret', $gSecret);
    }
    $config->save();
    parent::submitForm($form, $form_state);
  }

}
