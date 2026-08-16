<?php

declare(strict_types=1);

namespace Drupal\dx_delivery\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\dx_delivery\Entity\DeliveryBlueprint;
use Drupal\dx_delivery\Service\BlueprintFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Page-selection wizard for turnkey orders (D2-B).
 */
final class DeliveryWizardForm extends FormBase {

  public function __construct(
    protected BlueprintFactory $factory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('dx_delivery.blueprint_factory'));
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
    $form['#attached']['library'][] = 'dx_delivery/dx_delivery';

    $form['site_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('站点类型'),
      '#options' => [
        'government' => $this->t('政府 / 政务门户'),
        'enterprise' => $this->t('企业 / 机构门户'),
      ],
      '#default_value' => 'government',
      '#required' => TRUE,
    ];

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('站点名称'),
      '#required' => TRUE,
      '#default_value' => '示例门户',
    ];

    $form['machine_name'] = [
      '#type' => 'machine_name',
      '#title' => $this->t('租户 ID'),
      '#required' => TRUE,
      '#default_value' => 'demo_portal',
      '#machine_name' => [
        'exists' => [$this, 'machineExists'],
        'source' => ['label'],
      ],
      '#maxlength' => 32,
    ];

    $form['theme_pack'] = [
      '#type' => 'select',
      '#title' => $this->t('门面气质'),
      '#options' => [
        'gov_steady' => $this->t('政务 · 沉稳'),
        'gov_open' => $this->t('政务 · 亲民'),
        'gov_resolve' => $this->t('政务 · 魄力'),
        'gov_solemn' => $this->t('政务 · 庄重'),
        'gov_passion' => $this->t('政务 · 激情'),
        'ent_trust' => $this->t('企业 · 稳健'),
        'ent_drive' => $this->t('企业 · 进取'),
        'ent_apple' => $this->t('企业 · 苹果风'),
        'ent_innovate' => $this->t('企业 · 创新'),
      ],
      '#default_value' => 'gov_steady',
    ];

    $form['layout_profile'] = [
      '#type' => 'select',
      '#title' => $this->t('App / 小程序版式'),
      '#options' => [
        'gov_default' => $this->t('政务默认'),
        'ent_default' => $this->t('企业默认'),
      ],
      '#default_value' => 'gov_default',
    ];

    $form['channels'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('交付端'),
      '#options' => [
        'web' => $this->t('Web 门户（必选）'),
        'miniprogram' => $this->t('微信小程序'),
        'app' => $this->t('Flutter App（安卓/iOS）'),
      ],
      '#default_value' => ['web'],
    ];

    $form['capabilities'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('能力清单'),
      '#options' => [
        'commerce' => $this->t('商城'),
        'opinion' => $this->t('舆情监控（第一波演示）'),
        'ai_chat' => $this->t('AI 客服'),
        'oss' => $this->t('对象存储'),
      ],
    ];

    $form['migrate_level'] = [
      '#type' => 'select',
      '#title' => $this->t('旧站移植'),
      '#options' => [
        'none' => $this->t('空白起步'),
        'l1' => $this->t('L1 栏目+正文'),
        'l2' => $this->t('L2 含结构化块'),
        'l3' => $this->t('L3 人工集成'),
      ],
      '#default_value' => 'none',
    ];

    $form['source_url'] = [
      '#type' => 'url',
      '#title' => $this->t('原网站 URL'),
      '#states' => [
        'visible' => [
          ':input[name="migrate_level"]' => ['!value' => 'none'],
        ],
      ],
    ];

    $form['owner_mail'] = [
      '#type' => 'email',
      '#title' => $this->t('联系邮箱'),
      '#default_value' => 'admin@drupalx.local',
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('生成蓝图'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * Machine name uniqueness against blueprints.
   */
  public function machineExists(string $value): bool {
    $storage = \Drupal::entityTypeManager()->getStorage('dx_blueprint');
    return (bool) $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('machine_name', $value)
      ->range(0, 1)
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $channels = array_values(array_filter($form_state->getValue('channels') ?: []));
    $capabilities = array_values(array_filter($form_state->getValue('capabilities') ?: []));
    $built = $this->factory->fromWizard([
      'site_type' => $form_state->getValue('site_type'),
      'label' => $form_state->getValue('label'),
      'machine_name' => $form_state->getValue('machine_name'),
      'theme_pack' => $form_state->getValue('theme_pack'),
      'layout_profile' => $form_state->getValue('layout_profile'),
      'channels' => $channels,
      'capabilities' => $capabilities,
      'migrate_level' => $form_state->getValue('migrate_level'),
      'source_url' => $form_state->getValue('source_url'),
      'owner_mail' => $form_state->getValue('owner_mail'),
    ]);

    $entity = DeliveryBlueprint::create($built['fields']);
    $entity->setPayload($built['payload']);
    $entity->save();

    $this->messenger()->addStatus($this->t('蓝图已生成，请确认后一键交付。'));
    $form_state->setRedirect('dx_delivery.blueprint', ['dx_blueprint' => $entity->id()]);
  }

}
