<?php

declare(strict_types=1);

namespace Drupal\dx_tenant\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\dx_ai_gateway\Service\AiGateway;
use Drupal\file\Entity\File;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Tenant settings configuration form.
 */
class TenantSettingsForm extends ConfigFormBase {

  public function __construct(
    protected AiGateway $aiGateway,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('dx_ai_gateway.gateway'),
    );
  }

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

    $form['ai_quota_monthly'] = [
      '#type' => 'number',
      '#title' => $this->t('Monthly AI quota (tokens)'),
      '#default_value' => (int) $config->get('ai_quota_monthly'),
      '#min' => 0,
      '#step' => 1000,
      '#description' => $this->t('Set a positive number to override the platform default. Set 0 to inherit it.'),
    ];

    $providers = $this->aiGateway->getProviders();
    $form['ai_keys'] = [
      '#type' => 'details',
      '#title' => $this->t('Tenant AI API key overrides'),
      '#description' => $this->t('A tenant key takes precedence over the platform key. Leave blank to retain the current value or inherit the platform key. Keys are stored outside configuration exports.'),
      '#tree' => TRUE,
    ];
    foreach ($providers as $id => $provider) {
      $form['ai_keys'][$id] = [
        '#type' => 'password',
        '#title' => $this->t('@provider API key', ['@provider' => $provider['label'] ?? $id]),
        '#description' => $this->aiGateway->hasTenantApiKey($id)
          ? $this->t('Tenant key is set. Leave blank to keep it.')
          : ($this->aiGateway->hasApiKey($id)
            ? $this->t('Using the platform key. Enter a value to override it.')
            : $this->t('No key configured. Enter a tenant key or configure a platform key.')),
      ];
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

    foreach ($form_state->getValue('ai_keys') ?: [] as $providerId => $apiKey) {
      if ($apiKey !== '') {
        $this->aiGateway->setTenantApiKey($providerId, (string) $apiKey);
      }
    }

    $this->config('dx_tenant.settings')
      ->set('company_name', $form_state->getValue('company_name'))
      ->set('industry', $form_state->getValue('industry'))
      ->set('logo_fid', $logoFid)
      ->set('ai_quota_monthly', (int) $form_state->getValue('ai_quota_monthly'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
