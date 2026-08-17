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

    if ($dx_app_package) {
      $family = (string) ($dx_app_package->get('license_family')->value ?? 'gpl');
      $policy = (string) ($dx_app_package->get('source_policy')->value ?? 'tenant_visible');
      $form['license_meta'] = [
        '#type' => 'item',
        '#title' => $this->t('Source / license'),
        '#markup' => $this->t('Family: @f · Source policy: @p', [
          '@f' => $family,
          '@p' => $policy,
        ]),
      ];
    }

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

    $ral_version = '1.0';
    $ral_link = '/dx/ecosystem/agreements/dx_ral';
    if (\Drupal::moduleHandler()->moduleExists('dx_ecosystem') && \Drupal::hasService('dx_ecosystem.agreements')) {
      /** @var \Drupal\dx_ecosystem\Service\AgreementRepository $repo */
      $repo = \Drupal::service('dx_ecosystem.agreements');
      $ral = $repo->currentRal();
      if ($ral) {
        $ral_version = $ral['version'];
      }
    }

    $form['ral_accepted'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I accept the DrupalX Reciprocal App License (DX-RAL) v@version and will not disclose app source to fourth parties.', [
        '@version' => $ral_version,
      ]),
      '#description' => $this->t('Read the agreement: <a href=":url">DX-RAL</a>.', [':url' => $ral_link]),
      '#required' => TRUE,
    ];
    $form['ral_version'] = [
      '#type' => 'value',
      '#value' => $ral_version,
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
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (!(bool) $form_state->getValue('ral_accepted')) {
      $form_state->setErrorByName('ral_accepted', $this->t('DX-RAL acceptance is required.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $ral_version = (string) $form_state->getValue('ral_version');
    $uid = (int) $this->currentUser()->id();
    $request = InstallRequest::create([
      'app_id' => $form_state->getValue('app_id'),
      'tenant_machine' => $form_state->getValue('tenant_machine'),
      'notes' => $form_state->getValue('notes'),
      'requester_uid' => $uid,
      'status' => 'pending',
      'ral_accepted' => TRUE,
      'ral_version' => $ral_version,
      'ral_accepted_at' => \Drupal::time()->getRequestTime(),
      'ral_accepter_uid' => $uid,
    ]);
    $request->save();

    if (\Drupal::moduleHandler()->moduleExists('dx_ecosystem') && \Drupal::hasService('dx_ecosystem.acks')) {
      /** @var \Drupal\dx_ecosystem\Service\AgreementAckStore $acks */
      $acks = \Drupal::service('dx_ecosystem.acks');
      $acks->record('dx_ral', $ral_version, [
        'source' => 'install_request_form',
        'tenant_machine' => (string) $form_state->getValue('tenant_machine'),
        'app_id' => (string) $form_state->getValue('app_id'),
        'request_id' => (string) $request->id(),
      ], $uid);
    }

    $this->messenger()->addStatus($this->t('Your install request has been submitted.'));
    $form_state->setRedirect('dx_appstore.catalog');
  }

}
