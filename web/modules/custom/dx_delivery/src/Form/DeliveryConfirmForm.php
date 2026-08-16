<?php

declare(strict_types=1);

namespace Drupal\dx_delivery\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\dx_delivery\Entity\DeliveryBlueprint;
use Drupal\dx_delivery\Service\DeliveryOrchestrator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirm and run one-click delivery.
 */
final class DeliveryConfirmForm extends ConfirmFormBase {

  protected ?DeliveryBlueprint $blueprint = NULL;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected DeliveryOrchestrator $orchestrator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('dx_delivery.orchestrator'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'dx_delivery_confirm';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?DeliveryBlueprint $dx_blueprint = NULL): array {
    $this->blueprint = $dx_blueprint;
    $form['skip_provision'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('跳过开通（仅主题/版式/打包，用于已有租户）'),
    ];
    $form['skip_pack'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('跳过 App/小程序打包'),
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    $label = $this->blueprint ? $this->blueprint->label() : '';
    return $this->t('确认一键交付「@label」？', ['@label' => $label]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return Url::fromRoute('dx_delivery.blueprint', [
      'dx_blueprint' => $this->blueprint?->id(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('开始交付');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if (!$this->blueprint) {
      return;
    }
    $this->blueprint->set('status', 'confirmed');
    $this->blueprint->save();
    $report = $this->orchestrator->run(
      $this->blueprint,
      (bool) $form_state->getValue('skip_provision'),
      (bool) $form_state->getValue('skip_pack'),
    );
    if (!empty($report['passed'])) {
      $this->messenger()->addStatus($this->t('交付完成。'));
    }
    else {
      $this->messenger()->addWarning($this->t('交付结束但有失败步骤，请查看验收报告。'));
    }
    $form_state->setRedirect('dx_delivery.blueprint', [
      'dx_blueprint' => $this->blueprint->id(),
    ]);
  }

}
