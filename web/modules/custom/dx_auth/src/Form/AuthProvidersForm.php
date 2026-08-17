<?php

declare(strict_types=1);

namespace Drupal\dx_auth\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * WeChat and SMS login provider settings.
 */
class AuthProvidersForm extends ConfigFormBase {

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
    return 'dx_auth_providers_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('dx_auth.settings');
    $wechat = $config->get('wechat') ?: [];
    $sms = $config->get('sms') ?: [];

    $form['wechat'] = [
      '#type' => 'details',
      '#title' => $this->t('WeChat login'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];
    $form['wechat']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable WeChat login'),
      '#default_value' => !empty($wechat['enabled']),
      '#description' => $this->t('Shows a live QR (Open Platform website app) and in-WeChat one-tap (Official Account). Callback: /dx/auth/wechat/callback — whitelist this exact URL in the WeChat console.'),
    ];
    $form['wechat']['app_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Open Platform website AppID'),
      '#default_value' => $wechat['app_id'] ?? '',
      '#description' => $this->t('开放平台网站应用 AppID（扫码登录 / WxLogin）。'),
    ];
    $form['wechat']['app_secret'] = [
      '#type' => 'password',
      '#title' => $this->t('Open Platform website AppSecret'),
      '#description' => !empty($wechat['app_secret'])
        ? $this->t('Leave blank to keep the stored secret.')
        : $this->t('Website application AppSecret.'),
    ];
    $form['wechat']['mp_app_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Official Account AppID'),
      '#default_value' => $wechat['mp_app_id'] ?? '',
      '#description' => $this->t('公众号 AppID，用于微信内一键授权（snsapi_userinfo）。'),
    ];
    $form['wechat']['mp_app_secret'] = [
      '#type' => 'password',
      '#title' => $this->t('Official Account AppSecret'),
      '#description' => !empty($wechat['mp_app_secret'])
        ? $this->t('Leave blank to keep the stored secret.')
        : $this->t('Official Account AppSecret.'),
    ];

    $form['sms'] = [
      '#type' => 'details',
      '#title' => $this->t('SMS / mobile login'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];
    $form['sms']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable mobile SMS login'),
      '#default_value' => !empty($sms['enabled']),
    ];
    $form['sms']['test_mode'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Test mode (do not call Aliyun; return the code in the JSON for QA)'),
      '#default_value' => !empty($sms['test_mode']),
      '#description' => $this->t('Turn this off in production. Test mode lets the login page show the OTP so you can verify the flow without SMS credentials.'),
    ];
    $form['sms']['access_key_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Aliyun AccessKey ID'),
      '#default_value' => $sms['access_key_id'] ?? '',
    ];
    $form['sms']['access_key_secret'] = [
      '#type' => 'password',
      '#title' => $this->t('Aliyun AccessKey secret'),
      '#description' => !empty($sms['access_key_secret'])
        ? $this->t('Leave blank to keep the stored secret.')
        : $this->t('Aliyun RAM AccessKey secret.'),
    ];
    $form['sms']['sign_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('SMS sign name'),
      '#default_value' => $sms['sign_name'] ?? '',
    ];
    $form['sms']['template_code'] = [
      '#type' => 'textfield',
      '#title' => $this->t('SMS template code'),
      '#default_value' => $sms['template_code'] ?? '',
      '#description' => $this->t('Template must include a numeric code variable (default key: code).'),
    ];
    $form['sms']['template_param_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Template param key'),
      '#default_value' => $sms['template_param_key'] ?? 'code',
    ];
    $form['sms']['region'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Region'),
      '#default_value' => $sms['region'] ?? 'cn-hangzhou',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('dx_auth.settings');
    $wechat = $form_state->getValue('wechat') ?: [];
    $sms = $form_state->getValue('sms') ?: [];
    $storedWechat = $config->get('wechat') ?: [];
    $storedSms = $config->get('sms') ?: [];

    $config->set('wechat', [
      'enabled' => !empty($wechat['enabled']),
      'app_id' => trim((string) ($wechat['app_id'] ?? '')),
      'app_secret' => $this->keepSecret((string) ($wechat['app_secret'] ?? ''), (string) ($storedWechat['app_secret'] ?? '')),
      'mp_app_id' => trim((string) ($wechat['mp_app_id'] ?? '')),
      'mp_app_secret' => $this->keepSecret((string) ($wechat['mp_app_secret'] ?? ''), (string) ($storedWechat['mp_app_secret'] ?? '')),
    ]);
    $config->set('sms', [
      'enabled' => !empty($sms['enabled']),
      'test_mode' => !empty($sms['test_mode']),
      'provider' => 'aliyun',
      'access_key_id' => trim((string) ($sms['access_key_id'] ?? '')),
      'access_key_secret' => $this->keepSecret((string) ($sms['access_key_secret'] ?? ''), (string) ($storedSms['access_key_secret'] ?? '')),
      'sign_name' => trim((string) ($sms['sign_name'] ?? '')),
      'template_code' => trim((string) ($sms['template_code'] ?? '')),
      'template_param_key' => trim((string) ($sms['template_param_key'] ?? 'code')) ?: 'code',
      'region' => trim((string) ($sms['region'] ?? 'cn-hangzhou')) ?: 'cn-hangzhou',
    ]);
    $config->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * Password elements submit empty when unchanged.
   */
  protected function keepSecret(string $submitted, string $stored): string {
    $submitted = trim($submitted);
    return $submitted === '' ? $stored : $submitted;
  }

}
