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
        '#markup' => '<p>' . $this->t('Tokens used: @used / @quota (remaining @remain, source: @source). Calls: @calls (@ok ok).', [
          '@used' => number_format($summary['tokens_used']),
          '@quota' => number_format($summary['quota']),
          '@remain' => number_format($summary['remaining']),
          '@source' => $summary['quota_source'] ?? 'platform',
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
      '#title' => $this->t('Monthly token quota (platform default)'),
      '#default_value' => $config->get('monthly_quota') ?: 100000,
      '#min' => 0,
      '#description' => $this->t('Tenants may override this under Tenant settings when enabled.'),
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

    $form['phase_b'] = [
      '#type' => 'details',
      '#title' => $this->t('Chat experience (Phase B)'),
      '#open' => TRUE,
    ];

    $form['phase_b']['prefer_ai_module'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Prefer Drupal AI provider manager when available'),
      '#default_value' => (bool) $config->get('prefer_ai_module'),
      '#description' => $this->t('Uses ai.provider (e.g. openai) with ChatInput/ChatMessage; falls back to HTTP.'),
    ];

    $form['phase_b']['enable_streaming'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable SSE streaming replies'),
      '#default_value' => (bool) $config->get('enable_streaming'),
    ];

    $form['phase_b']['max_history_turns'] = [
      '#type' => 'number',
      '#title' => $this->t('Max multi-turn history pairs'),
      '#default_value' => $config->get('max_history_turns') ?: 10,
      '#min' => 1,
      '#max' => 50,
    ];

    $form['phase_b']['inject_knowledge_base'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Inject product / company knowledge into system prompt'),
      '#default_value' => (bool) $config->get('inject_knowledge_base'),
    ];

    $form['phase_b']['knowledge_max_products'] = [
      '#type' => 'number',
      '#title' => $this->t('Max products in knowledge context'),
      '#default_value' => $config->get('knowledge_max_products') ?: 20,
      '#min' => 1,
      '#max' => 50,
      '#states' => [
        'visible' => [
          ':input[name="inject_knowledge_base"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['providers'] = [
      '#type' => 'details',
      '#title' => $this->t('Providers'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];

    foreach ($providers as $id => $provider) {
      $source = $this->aiGateway->keySource($id);
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
          '#title' => $this->t('Platform API key'),
          '#description' => match ($source) {
            'tenant' => $this->t('Tenant override is active for this site.'),
            'platform' => $this->t('Platform key is set. Leave blank to keep.'),
            default => $this->t('No key configured yet.'),
          },
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
      '#value' => $this->t('Load keys from environment'),
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
      if (!empty($row['api_key'])) {
        $this->aiGateway->setApiKey($id, (string) $row['api_key']);
      }
    }

    $this->config('dx_ai_gateway.settings')
      ->set('default_provider', $form_state->getValue('default_provider'))
      ->set('monthly_quota', (int) $form_state->getValue('monthly_quota'))
      ->set('system_prompt', (string) $form_state->getValue('system_prompt'))
      ->set('failover_order', $order)
      ->set('providers', $clean)
      ->set('prefer_ai_module', (bool) $form_state->getValue('prefer_ai_module'))
      ->set('enable_streaming', (bool) $form_state->getValue('enable_streaming'))
      ->set('max_history_turns', (int) $form_state->getValue('max_history_turns'))
      ->set('inject_knowledge_base', (bool) $form_state->getValue('inject_knowledge_base'))
      ->set('knowledge_max_products', (int) $form_state->getValue('knowledge_max_products'))
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
    $providers = $form_state->getValue('providers') ?: [];
    if (!empty($providers[$id]['api_key'])) {
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
   * Loads keys from DX_AI_*_KEY env vars.
   */
  public function submitLoadEnv(array &$form, FormStateInterface $form_state): void {
    $loaded = $this->aiGateway->loadKeysFromEnv();
    if ($loaded) {
      $this->messenger()->addStatus($this->t('Loaded keys for: @list', [
        '@list' => implode(', ', $loaded),
      ]));
    }
    else {
      $this->messenger()->addWarning($this->t('No DX_AI_*_KEY environment variables found.'));
    }
  }

}
