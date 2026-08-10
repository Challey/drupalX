<?php

declare(strict_types=1);

namespace Drupal\dx_appstore\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\dx_appstore\Entity\AppPackage;
use Drupal\dx_appstore\Entity\InstallRequest;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Public form to request app installation.
 */
class InstallRequestForm extends FormBase {

  /**
   * Constructs InstallRequestForm.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'dx_appstore_install_request_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?AppPackage $dx_app_package = NULL): array {
    $form['app'] = [
      '#type' => 'item',
      '#title' => $this->t('App'),
      '#markup' => $dx_app_package ? $dx_app_package->label() : '',
    ];

    $form['tenant_machine'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Tenant machine name'),
      '#required' => TRUE,
    ];

    $form['notes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Notes'),
      '#rows' => 4,
    ];

    $form['app_id'] = [
      '#type' => 'value',
      '#value' => $dx_app_package?->id(),
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit request'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    InstallRequest::create([
      'app_id' => $form_state->getValue('app_id'),
      'tenant_machine' => $form_state->getValue('tenant_machine'),
      'notes' => $form_state->getValue('notes'),
      'requester_uid' => $this->currentUser()->id(),
      'status' => 'pending',
    ])->save();

    $this->messenger()->addStatus($this->t('Your install request has been submitted.'));
    $form_state->setRedirect('dx_appstore.catalog');
  }

}
