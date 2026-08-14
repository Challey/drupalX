<?php

declare(strict_types=1);

namespace Drupal\dx_ai_gateway\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\dx_ai_gateway\Service\AiGateway;
use Drupal\dx_ai_gateway\Service\UsageTracker;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * AI Gateway settings form.
 */
class AiGatewaySettingsForm extends ConfigFormBase {

  public function __construct(
    protected AiGateway $aiGateway,
    protected UsageTracker $usageTracker,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('dx_ai_gateway.gateway'),
      $container->get('dx_ai_gateway.usage_tracker'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['dx_ai_gateway.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'dx_ai_gateway_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('dx_ai_gateway.settings');
    $providers = $config->get('providers') ?: [];
    $summary = $this->usageTracker->summary();

    $form['usage'] = [
      '#type' => 'details',
      '#title' => $this->t('Usage (@period)', ['@period' => $summary['period']]),
      '#open' => TRUE,
      'stats' => [
        '#markup' => '<p>' . $this->t('Tokens used: @used / @quota (remaining @remain). Calls: @calls (@ok ok).', [
          '@used' => number_format($summary['tokens_used']),
          '@quota' => number_format($summary['quota']),
          '@remain' => number_format($summary['remaining']),
          '@calls' => $summary['calls'],
          '@ok' => $summary['ok_calls'],
        ]) . '</p>',
      ],
    ];

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

    $form['system_prompt'] = [
      '#type' => 'textarea',
      '#title' => $this->t('System prompt'),
      '#default_value' => $config->get('system_prompt') ?: '',
      '#rows' => 4,
      '#description' => $this->t('Injected as the system message for every chat request.'),
    ];

    $form['failover_order'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Failover order'),
      '#default_value' => implode(', ', $config->get('failover_order') ?: []),
      '#description' => $this->t('Comma-separated provider IDs, tried in order.'),
    ];

    $form['providers'] = [
      '#type' => 'details',
      '#title' => $this->t('Providers'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];

    foreach ($providers as $id => $provider) {
      $keySource = $this->aiGateway->getApiKeySource($id);
      $keyDescription = match ($keySource) {
        'site' => $this->t('A site-specific key override is set. Leave blank to keep it.'),
        'environment' => $this->t('Using the platform key from DX_AI_@provider_KEY. Enter a key to override it for this site.', [
          '@provider' => strtoupper($id),
        ]),
        default => $this->t('No site or platform key is configured.'),
      };
      $form['providers'][$id] = [
        '#type' => 'details',
        '#title' => $provider['label'] . ' (' . $id . ')',
        '#open' => FALSE,
        'label' => [
          '#type' => 'textfield',
          '#title' => $this->t('Label'),
          '#default_value' => $provider['label'] ?? $id,
          '#required' => TRUE,
        ],
        'base_url' => [
          '#type' => 'url',
          '#title' => $this->t('Base URL'),
          '#default_value' => $provider['base_url'] ?? '',
          '#required' => TRUE,
        ],
        'model' => [
          '#type' => 'textfield',
          '#title' => $this->t('Model'),
          '#default_value' => $provider['model'] ?? '',
          '#required' => TRUE,
        ],
        'api_key' => [
          '#type' => 'password',
          '#title' => $this->t('API key'),
          '#description' => $keyDescription,
        ],
        'clear_api_key' => [
          '#type' => 'checkbox',
          '#title' => $this->t('Remove site-specific key override'),
          '#description' => $this->t('The platform environment key will be used after removal, when available.'),
          '#access' => $keySource === 'site',
        ],
        'test' => [
          '#type' => 'submit',
          '#value' => $this->t('Test @label', ['@label' => $provider['label']]),
          '#name' => 'test_' . $id,
          '#submit' => ['::submitTestProvider'],
          '#limit_validation_errors' => [],
          '#provider_id' => $id,
        ],
      ];
    }

    $form['actions']['load_env'] = [
      '#type' => 'submit',
      '#value' => $this->t('Check platform environment keys'),
      '#submit' => ['::submitLoadEnv'],
      '#limit_validation_errors' => [],
      '#weight' => 20,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * Saves settings.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $order = array_values(array_filter(array_map('trim', explode(',', (string) $form_state->getValue('failover_order')))));
    $providers = $form_state->getValue('providers') ?: [];
    $clean = [];
    foreach ($providers as $id => $row) {
      $clean[$id] = [
        'label' => $row['label'],
        'base_url' => rtrim((string) $row['base_url'], '/'),
        'model' => $row['model'],
      ];
      if (!empty($row['clear_api_key'])) {
        $this->aiGateway->clearApiKey($id);
      }
      elseif (!empty($row['api_key'])) {
        $this->aiGateway->setApiKey($id, (string) $row['api_key']);
      }
    }

    $this->config('dx_ai_gateway.settings')
      ->set('default_provider', $form_state->getValue('default_provider'))
      ->set('monthly_quota', (int) $form_state->getValue('monthly_quota'))
      ->set('system_prompt', (string) $form_state->getValue('system_prompt'))
      ->set('failover_order', $order)
      ->set('providers', $clean)
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Tests one provider.
   */
  public function submitTestProvider(array &$form, FormStateInterface $form_state): void {
    $trigger = $form_state->getTriggeringElement();
    $id = $trigger['#provider_id'] ?? '';
    if ($id === '') {
      return;
    }
    // Persist any newly typed key for this provider before testing.
    $providers = $form_state->getValue('providers') ?: [];
    if (!empty($providers[$id]['clear_api_key'])) {
      $this->aiGateway->clearApiKey($id);
    }
    elseif (!empty($providers[$id]['api_key'])) {
      $this->aiGateway->setApiKey($id, (string) $providers[$id]['api_key']);
    }
    try {
      $result = $this->aiGateway->testProvider($id);
      $this->messenger()->addStatus($this->t('Test OK via @provider (@model): @content', [
        '@provider' => $result['provider'],
        '@model' => $result['model'] ?? '',
        '@content' => mb_substr((string) $result['content'], 0, 120),
      ]));
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Test failed: @message', ['@message' => $e->getMessage()]));
    }
  }

  /**
   * Reports keys inherited from DX_AI_*_KEY environment variables.
   */
  public function submitLoadEnv(array &$form, FormStateInterface $form_state): void {
    $loaded = $this->aiGateway->loadKeysFromEnv();
    if ($loaded) {
      $this->messenger()->addStatus($this->t('Platform environment keys are available for: @list', [
        '@list' => implode(', ', $loaded),
      ]));
    }
    else {
      $this->messenger()->addWarning($this->t('No DX_AI_*_KEY environment variables found.'));
    }
  }

}
