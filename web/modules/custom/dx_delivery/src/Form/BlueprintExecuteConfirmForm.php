<?php

declare(strict_types=1);

namespace Drupal\dx_delivery\Form;

use Drupal\Core\Entity\ContentEntityConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\dx_delivery\Service\DeliveryOrchestrator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirmation form to execute a turnkey delivery blueprint.
 */
class BlueprintExecuteConfirmForm extends ContentEntityConfirmFormBase {

  public function __construct(
    protected DeliveryOrchestrator $orchestrator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('dx_delivery.orchestrator'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): string {
    return $this->t('Execute turnkey delivery for %label?', ['%label' => $this->getEntity()->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('entity.dx_blueprint.collection');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): string {
    return $this->t('Execute delivery');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    return $this->t('This will provision the tenant, apply the theme, seed content, and enable selected App Store packages. The process may take several minutes.');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\dx_delivery\Entity\Blueprint $blueprint */
    $blueprint = $this->getEntity();
    $blueprint->set('status', 'confirmed');
    $blueprint->save();

    try {
      $this->orchestrator->run($blueprint);
      $this->messenger()->addStatus($this->t('Delivery completed for %label.', ['%label' => $blueprint->label()]));
      $form_state->setRedirect('dx_delivery.acceptance', ['dx_blueprint' => $blueprint->id()]);
    }
    catch (\Throwable $exception) {
      $this->messenger()->addError($this->t('Delivery failed: @message', ['@message' => $exception->getMessage()]));
      $form_state->setRedirect('entity.dx_blueprint.collection');
    }
  }

}
