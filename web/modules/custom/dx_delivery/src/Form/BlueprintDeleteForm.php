<?php

declare(strict_types=1);

namespace Drupal\dx_delivery\Form;

use Drupal\Core\Entity\ContentEntityDeleteForm;

/**
 * Provides a deletion form for delivery blueprints.
 */
class BlueprintDeleteForm extends ContentEntityDeleteForm {

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return $this->getEntity()->toUrl('collection');
  }

}
