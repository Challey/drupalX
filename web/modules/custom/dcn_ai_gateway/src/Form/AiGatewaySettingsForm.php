<?php

declare(strict_types=1);

namespace Drupal\dcn_ai_gateway\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * AI Gateway settings form.
 */
class AiGatewaySettingsForm extends ConfigFormBase {

  /**
   * Constructs AiGatewaySettingsForm.
   */
  public function __construct(
    protected StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('state'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['dcn_ai_gateway.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'dcn_ai_gateway_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('dcn_ai_gateway.settings');
    $providers = $config->get('providers') ?: [];

    $form['default_provider'] = [
      '#type' => 'select',
      '#title' => $this->t('Default provider'),
      '#options' => array_combine(array_keys($providers), array_column($providers, 'label')),
      '#default_value' => $config->get('default_provider'),
    ];

    $form['monthly_quota'] = [
      '#type' => 'number',
      '#title' => $this->t('Monthly token quota'),
      '#default_value' => $config->get('monthly_quota') ?: 100000,
      '#min' => 0,
    ];

    $form['api_keys'] = [
      '#type' => 'details',
      '#title' => $this->t('API keys'),
      '#open' => TRUE,
    ];

    foreach ($providers as $id => $provider) {
      $form['api_keys'][$id] = [
        '#type' => 'password',
        '#title' => $this->t('@label API key', ['@label' => $provider['label']]),
        '#description' => $this->t('Leave blank to keep the existing key.'),
        '#default_value' => '',
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('dcn_ai_gateway.settings')
      ->set('default_provider', $form_state->getValue('default_provider'))
      ->set('monthly_quota', (int) $form_state->getValue('monthly_quota'))
      ->save();

    $providers = $this->config('dcn_ai_gateway.settings')->get('providers') ?: [];
    foreach (array_keys($providers) as $id) {
      $key = $form_state->getValue($id);
      if ($key) {
        $this->state->set('dcn_ai_gateway.api_keys.' . $id, $key);
      }
    }

    parent::submitForm($form, $form_state);
  }

}
