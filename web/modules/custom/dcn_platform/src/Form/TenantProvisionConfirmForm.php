<?php

declare(strict_types=1);

namespace Drupal\dcn_platform\Form;

use Drupal\Core\Entity\ContentEntityConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\dcn_platform\Entity\Tenant;
use Drupal\dcn_platform\Service\TenantProvisioner;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirmation form to provision a tenant site.
 */
class TenantProvisionConfirmForm extends ContentEntityConfirmFormBase {

  /**
   * Constructs a TenantProvisionConfirmForm.
   */
  public function __construct(
    protected TenantProvisioner $tenantProvisioner,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('dcn_platform.tenant_provisioner'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): string {
    return $this->t('Provision tenant %label?', ['%label' => $this->getEntity()->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('entity.dcn_tenant.collection');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): string {
    return $this->t('Provision');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    return $this->t('This will create a database, site directory, and run site:install for this tenant.');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\dcn_platform\Entity\Tenant $tenant */
    $tenant = $this->getEntity();
    $tenant->set('status', 'provisioning');
    $tenant->save();

    try {
      $this->tenantProvisioner->provision($tenant);
      $this->messenger()->addStatus($this->t('Tenant %label has been provisioned.', ['%label' => $tenant->label()]));
    }
    catch (\Throwable $exception) {
      $this->messenger()->addError($this->t('Provisioning failed: @message', ['@message' => $exception->getMessage()]));
    }

    $form_state->setRedirect('entity.dcn_tenant.collection');
  }

}
