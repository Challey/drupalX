<?php

declare(strict_types=1);

namespace Drupal\dx_appstore\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\dx_appstore\Entity\InstallRequest;

/**
 * Generic entity form for App Store content entities.
 */
class EntityForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildForm($form, $form_state);
    $entity = $this->getEntity();

    if ($entity instanceof InstallRequest && $entity->id()) {
      $status = $entity->get('status')->value;
      if ($status === 'pending') {
        $form['actions']['approve_install'] = [
          '#type' => 'submit',
          '#value' => $this->t('Approve & Enable on tenant'),
          '#button_type' => 'primary',
          '#submit' => ['::submitApproveInstall'],
          '#weight' => 5,
        ];
      }
    }

    return $form;
  }

  /**
   * Approves and installs the requested module on the tenant site.
   */
  public function submitApproveInstall(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\dx_appstore\Entity\InstallRequest $entity */
    $entity = $this->getEntity();
    try {
      /** @var \Drupal\dx_appstore\Service\AppInstaller $installer */
      $installer = \Drupal::service('dx_appstore.installer');
      $result = $installer->approveAndInstall($entity);
      $this->messenger()->addStatus($this->t('Module @mod has been enabled on tenant @t.', [
        '@mod' => $result['module'],
        '@t' => $result['tenant'],
      ]));
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Installation failed: @message', ['@message' => $e->getMessage()]));
    }

    $form_state->setRedirectUrl($entity->toUrl('collection'));
  }

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

