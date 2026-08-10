<?php

declare(strict_types=1);

namespace Drupal\dx_platform\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form handler for tenant add and edit forms.
 */
class TenantForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $entity = $this->getEntity();

    $message_arguments = ['%label' => $entity->label()];
    if ($result === SAVED_NEW) {
      $this->messenger()->addStatus($this->t('Tenant %label has been created.', $message_arguments));
      $form_state->setRedirect('entity.dx_tenant.provision_form', ['dx_tenant' => $entity->id()]);
    }
    else {
      $this->messenger()->addStatus($this->t('Tenant %label has been updated.', $message_arguments));
      $form_state->setRedirect('entity.dx_tenant.collection');
    }

    return $result;
  }

}
