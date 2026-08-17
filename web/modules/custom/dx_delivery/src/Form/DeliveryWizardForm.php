<?php

declare(strict_types=1);

namespace Drupal\dx_delivery\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\dx_delivery\Entity\Blueprint;
use Drupal\dx_theme\Service\ThemeCatalog;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Multi-step wizard to create a turnkey delivery blueprint.
 */
class DeliveryWizardForm extends FormBase {

  /**
   * Default theme skin per site type.
   *
   * @var array<string, string>
   */
  protected const DEFAULT_SKINS = [
    'government' => 'gov_steady',
    'enterprise' => 'ent_trust',
    'industry' => 'portal',
  ];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ThemeCatalog $themeCatalog,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('dx_theme.catalog'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'dx_delivery_wizard';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $step = (int) ($form_state->get('step') ?: 1);
    $form['#tree'] = TRUE;
    $form['step'] = ['#type' => 'value', '#value' => $step];

    $form['progress'] = [
      '#markup' => '<p class="dx-delivery-progress">' . $this->t('Step @current of 5', ['@current' => $step]) . '</p>',
    ];

    switch ($step) {
      case 1:
        $this->buildStepSite($form, $form_state);
        break;

      case 2:
        $this->buildStepTheme($form, $form_state);
        break;

      case 3:
        $this->buildStepContent($form, $form_state);
        break;

      case 4:
        $this->buildStepApps($form, $form_state);
        break;

      case 5:
        $this->buildStepConfirm($form, $form_state);
        break;
    }

    $form['actions'] = ['#type' => 'actions'];
    if ($step > 1) {
      $form['actions']['back'] = [
        '#type' => 'submit',
        '#value' => $this->t('Back'),
        '#submit' => ['::backStep'],
        '#limit_validation_errors' => [],
      ];
    }
    if ($step < 5) {
      $form['actions']['next'] = [
        '#type' => 'submit',
        '#value' => $this->t('Next'),
        '#button_type' => 'primary',
      ];
    }
    else {
      $form['actions']['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Save blueprint'),
        '#button_type' => 'primary',
      ];
    }

    return $form;
  }

  /**
   * Step 1: site type and tenant identity.
   */
  protected function buildStepSite(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->get('wizard') ?? [];

    $form['site'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Site identity'),
    ];
    $form['site']['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Portal name'),
      '#required' => TRUE,
      '#default_value' => $values['label'] ?? '',
      '#description' => $this->t('Display name for the delivered portal.'),
    ];
    $form['site']['machine_name'] = [
      '#type' => 'machine_name',
      '#title' => $this->t('Machine name'),
      '#required' => TRUE,
      '#default_value' => $values['machine_name'] ?? '',
      '#machine_name' => [
        'exists' => [Blueprint::class, 'loadByMachineName'],
        'source' => ['site', 'label'],
      ],
      '#description' => $this->t('Used for tenant database and subdomain.'),
    ];
    $form['site']['owner_mail'] = [
      '#type' => 'email',
      '#title' => $this->t('Owner email'),
      '#required' => TRUE,
      '#default_value' => $values['owner_mail'] ?? '',
    ];
    $form['site']['site_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Site type'),
      '#required' => TRUE,
      '#options' => [
        'government' => $this->t('Government portal'),
        'enterprise' => $this->t('Enterprise portal'),
        'industry' => $this->t('Industry site'),
      ],
      '#default_value' => $values['site_type'] ?? 'government',
    ];
  }

  /**
   * Step 2: theme skin selection.
   */
  protected function buildStepTheme(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->get('wizard') ?? [];
    $siteType = $values['site_type'] ?? 'government';
    $defaultSkin = self::DEFAULT_SKINS[$siteType] ?? 'portal';

    $familyMap = [
      'government' => 'government',
      'enterprise' => 'enterprise',
      'industry' => 'universal',
    ];
    $family = $familyMap[$siteType] ?? 'universal';
    $grouped = $this->themeCatalog->byFamily(FALSE);
    $skins = $grouped[$family] ?? $this->themeCatalog->all();

    $options = [];
    foreach ($skins as $skin) {
      $id = (string) ($skin['id'] ?? '');
      if ($id === '') {
        continue;
      }
      $persona = (string) ($skin['persona'] ?? $skin['label'] ?? $id);
      $options[$id] = $persona . ' — ' . ($skin['summary'] ?? '');
    }

    $form['theme'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Facade temperament'),
    ];
    $form['theme']['theme_skin'] = [
      '#type' => 'radios',
      '#title' => $this->t('Theme pack'),
      '#required' => TRUE,
      '#options' => $options,
      '#default_value' => $values['theme_skin'] ?? ($this->themeCatalog->has($defaultSkin) ? $defaultSkin : $this->themeCatalog->defaultSkinId()),
    ];
  }

  /**
   * Step 3: content source.
   */
  protected function buildStepContent(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->get('wizard') ?? [];

    $form['content'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Content source'),
    ];
    $form['content']['content_source'] = [
      '#type' => 'radios',
      '#title' => $this->t('Starting content'),
      '#required' => TRUE,
      '#options' => [
        'blank' => $this->t('Blank start'),
        'demo' => $this->t('Industry demo pack'),
        'migrate' => $this->t('Legacy site (manual — Phase DZ)'),
      ],
      '#default_value' => $values['content_source'] ?? 'demo',
    ];
    $form['content']['industry'] = [
      '#type' => 'select',
      '#title' => $this->t('Industry demo'),
      '#options' => [
        'manufacturing' => $this->t('Manufacturing'),
        'retail' => $this->t('Retail'),
        'services' => $this->t('Services'),
      ],
      '#default_value' => $values['industry'] ?? 'manufacturing',
      '#states' => [
        'visible' => [
          ':input[name="content[content_source]"]' => ['value' => 'demo'],
        ],
      ],
    ];
  }

  /**
   * Step 4: optional App Store capabilities.
   */
  protected function buildStepApps(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->get('wizard') ?? [];
    $siteType = $values['site_type'] ?? 'government';
    $selected = $values['app_packages'] ?? [];

    $storage = $this->entityTypeManager->getStorage('dx_app_package');
    $ids = $storage->getQuery()->accessCheck(FALSE)->sort('label')->execute();
    $packages = $storage->loadMultiple($ids);

    $options = [];
    foreach ($packages as $package) {
      $trust = (string) $package->get('trust_level')->value;
      if ($siteType === 'government' && $trust === 'community') {
        continue;
      }
      $machine = (string) $package->get('machine_name')->value;
      $price = (float) ($package->get('price')->value ?: 0);
      $priceLabel = $price > 0 ? ' ¥' . number_format($price, 2) : $this->t('(free)');
      $options[$machine] = $package->label() . ' — ' . ($package->get('description')->value ?: '') . ' ' . $priceLabel;
    }

    $form['apps'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Capabilities (App Store)'),
    ];
    $form['apps']['app_packages'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Optional modules'),
      '#options' => $options,
      '#default_value' => $selected,
      '#description' => $siteType === 'government'
        ? $this->t('Government deliveries exclude community-trust packages by default.')
        : $this->t('Select curated App Store packages to enable on delivery.'),
    ];
  }

  /**
   * Step 5: channels and confirmation summary.
   */
  protected function buildStepConfirm(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->get('wizard') ?? [];
    $channels = $values['channels'] ?? ['web' => 1, 'pwa' => 0, 'wechat_miniprogram' => 0, 'android_shell' => 0];

    $form['channels'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Delivery channels'),
    ];
    $form['channels']['web'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Web portal (required)'),
      '#default_value' => 1,
      '#disabled' => TRUE,
    ];
    $form['channels']['pwa'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('PWA installable shell'),
      '#default_value' => !empty($channels['pwa']),
    ];
    $form['channels']['wechat_miniprogram'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('WeChat mini-program (Phase EA)'),
      '#default_value' => !empty($channels['wechat_miniprogram']),
      '#description' => $this->t('Will be flagged for Phase EA packaging.'),
    ];
    $form['channels']['android_shell'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Android controlled shell (Phase EA)'),
      '#default_value' => !empty($channels['android_shell']),
    ];

    $appList = array_filter($values['app_packages'] ?? []);
    $summary = [
      $this->t('Portal: @label (@machine)', [
        '@label' => $values['label'] ?? '',
        '@machine' => $values['machine_name'] ?? '',
      ]),
      $this->t('Type: @type', ['@type' => $values['site_type'] ?? '']),
      $this->t('Theme: @skin', ['@skin' => $values['theme_skin'] ?? '']),
      $this->t('Content: @src', ['@src' => $values['content_source'] ?? '']),
      $this->t('Apps: @apps', ['@apps' => $appList ? implode(', ', array_values($appList)) : $this->t('none')]),
    ];

    $form['summary'] = [
      '#type' => 'item',
      '#title' => $this->t('Blueprint summary'),
      '#markup' => '<ul><li>' . implode('</li><li>', $summary) . '</li></ul>',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $step = (int) $form_state->get('step');
    if ($step === 1) {
      $machine = $form_state->getValue(['site', 'machine_name']);
      if ($machine && Blueprint::loadByMachineName($machine)) {
        $form_state->setErrorByName('site][machine_name', $this->t('Machine name already in use.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $step = (int) $form_state->get('step');
    $wizard = $form_state->get('wizard') ?? [];

    if ($step === 1) {
      $wizard = array_merge($wizard, $form_state->getValue('site'));
    }
    elseif ($step === 2) {
      $wizard = array_merge($wizard, $form_state->getValue('theme'));
    }
    elseif ($step === 3) {
      $wizard = array_merge($wizard, $form_state->getValue('content'));
    }
    elseif ($step === 4) {
      $apps = array_values(array_filter($form_state->getValue(['apps', 'app_packages']) ?? []));
      $wizard['app_packages'] = $apps;
    }
    elseif ($step === 5) {
      $channelValues = $form_state->getValue('channels') ?? [];
      $wizard['channels'] = [
        'web' => TRUE,
        'pwa' => !empty($channelValues['pwa']),
        'wechat_miniprogram' => !empty($channelValues['wechat_miniprogram']),
        'android_shell' => !empty($channelValues['android_shell']),
      ];
      $this->saveBlueprint($wizard);
      $form_state->setRedirect('entity.dx_blueprint.collection');
      return;
    }

    $form_state->set('wizard', $wizard);
    $form_state->set('step', $step + 1);
    $form_state->setRebuild(TRUE);
  }

  /**
   * Moves the wizard back one step.
   */
  public function backStep(array &$form, FormStateInterface $form_state): void {
    $step = (int) $form_state->get('step');
    $form_state->set('step', max(1, $step - 1));
    $form_state->setRebuild(TRUE);
  }

  /**
   * Persists the blueprint entity from wizard values.
   *
   * @param array<string, mixed> $wizard
   */
  protected function saveBlueprint(array $wizard): void {
    $storage = $this->entityTypeManager->getStorage('dx_blueprint');
    $blueprintJson = json_encode($wizard, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    /** @var \Drupal\dx_delivery\Entity\Blueprint $blueprint */
    $blueprint = $storage->create([
      'label' => $wizard['label'],
      'machine_name' => $wizard['machine_name'],
      'status' => 'confirmed',
      'site_type' => $wizard['site_type'],
      'theme_skin' => $wizard['theme_skin'],
      'content_source' => $wizard['content_source'] ?? 'demo',
      'industry' => $wizard['industry'] ?? 'manufacturing',
      'app_packages' => json_encode($wizard['app_packages'] ?? []),
      'channels' => json_encode($wizard['channels'] ?? ['web' => TRUE]),
      'owner_mail' => $wizard['owner_mail'],
      'blueprint_json' => $blueprintJson,
      'tenant_machine' => $wizard['machine_name'],
    ]);
    $blueprint->save();

    $this->messenger()->addStatus($this->t('Blueprint %label saved. Execute delivery from the list when ready.', [
      '%label' => $blueprint->label(),
    ]));
  }

}
