<?php

declare(strict_types=1);

namespace Drupal\dcn_appstore\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Generic entity form for App Store content entities.
 */
class EntityForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $entity = $this->getEntity();
    $this->messenger()->addStatus($this->t('Saved @label.', ['@label' => $entity->label()]));
    $form_state->setRedirectUrl($entity->toUrl('collection'));
    return $result;
  }

}
