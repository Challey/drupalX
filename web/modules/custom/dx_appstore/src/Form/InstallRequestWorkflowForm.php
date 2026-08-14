<?php

declare(strict_types=1);

namespace Drupal\dx_appstore\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\dx_appstore\Entity\InstallRequest;
use Drupal\dx_appstore\Service\ModuleInstallGate;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Approve / reject / install confirm form for install requests.
 */
class InstallRequestWorkflowForm extends ConfirmFormBase {

  protected ?InstallRequest $request = NULL;

  protected string $operation = 'approve';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ModuleInstallGate $installGate,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('dx_appstore.install_gate'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'dx_appstore_install_request_workflow';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?InstallRequest $dx_install_request = NULL, string $operation = 'approve'): array {
    $this->request = $dx_install_request;
    $this->operation = $operation;
    $form = parent::buildForm($form, $form_state);
    $form['notes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Notes'),
      '#rows' => 3,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    $id = $this->request?->id() ?? '';
    return match ($this->operation) {
      'reject' => $this->t('Reject install request #@id?', ['@id' => $id]),
      'install' => $this->t('Enable whitelisted module for request #@id?', ['@id' => $id]),
      default => $this->t('Approve install request #@id?', ['@id' => $id]),
    };
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('entity.dx_install_request.collection');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return match ($this->operation) {
      'reject' => $this->t('Reject'),
      'install' => $this->t('Install module'),
      default => $this->t('Approve'),
    };
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if (!$this->request) {
      return;
    }
    $notes = trim((string) $form_state->getValue('notes'));
    try {
      match ($this->operation) {
        'reject' => $this->installGate->reject($this->request, $notes),
        'install' => $this->installGate->install($this->request),
        default => $this->installGate->approve($this->request, $notes),
      };
      $this->messenger()->addStatus($this->t('Request #@id marked as @op.', [
        '@id' => $this->request->id(),
        '@op' => $this->operation === 'install' ? 'installed' : $this->operation . 'd',
      ]));
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($e->getMessage());
    }
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
