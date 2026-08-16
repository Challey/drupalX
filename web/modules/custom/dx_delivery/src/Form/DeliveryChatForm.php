<?php

declare(strict_types=1);

namespace Drupal\dx_delivery\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\dx_delivery\Entity\DeliveryBlueprint;
use Drupal\dx_delivery\Service\BlueprintFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Natural-language intake form (D2-B).
 */
final class DeliveryChatForm extends FormBase {

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
    return 'dx_delivery_chat';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attached']['library'][] = 'dx_delivery/dx_delivery';

    $form['message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('描述你的需求'),
      '#required' => TRUE,
      '#rows' => 6,
      '#placeholder' => $this->t('例如：做政府门户，气质沉稳；把 https://old.example.gov.cn 资讯迁过来；要商城和舆情；打小程序和安卓 App。'),
    ];

    $form['machine_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('租户 ID（可选覆盖）'),
      '#description' => $this->t('留空则从描述推断或自动生成。'),
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
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $overrides = [];
    $machine = trim((string) $form_state->getValue('machine_name'));
    if ($machine !== '') {
      $overrides['machine_name'] = $this->factory->sanitizeMachine($machine);
    }
    $built = $this->factory->fromChat((string) $form_state->getValue('message'), $overrides);
    $entity = DeliveryBlueprint::create($built['fields']);
    $entity->setPayload($built['payload']);
    $entity->save();

    if (!empty($built['notes'])) {
      $this->messenger()->addStatus($built['notes']);
    }
    $this->messenger()->addStatus($this->t('已根据对话生成蓝图，请核对后确认交付。'));
    $form_state->setRedirect('dx_delivery.blueprint', ['dx_blueprint' => $entity->id()]);
  }

}
