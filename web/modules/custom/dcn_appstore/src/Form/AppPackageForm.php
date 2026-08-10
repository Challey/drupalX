<?php

declare(strict_types=1);

namespace Drupal\dcn_appstore\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form handler for app package entities.
 */
class AppPackageForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $entity = $this->getEntity();
    $this->messenger()->addStatus($this->t('App package %label saved.', ['%label' => $entity->label()]));
    $form_state->setRedirect('entity.dcn_app_package.collection');
    return $result;
  }

}
