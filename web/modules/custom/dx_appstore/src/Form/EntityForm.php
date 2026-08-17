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
        $accepted = (bool) ($entity->get('ral_accepted')->value ?? FALSE);
        if (!$accepted) {
          $form['admin_ral_accept'] = [
            '#type' => 'checkbox',
            '#title' => $this->t('Confirm DX-RAL acceptance on behalf of requester (audit logged)'),
            '#required' => TRUE,
            '#weight' => 40,
          ];
        }
        else {
          $form['admin_ral_status'] = [
            '#type' => 'item',
            '#title' => $this->t('DX-RAL'),
            '#markup' => $this->t('Accepted v@version', [
              '@version' => (string) ($entity->get('ral_version')->value ?: '?'),
            ]),
            '#weight' => 40,
          ];
        }
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
      if (!(bool) ($entity->get('ral_accepted')->value ?? FALSE)) {
        if (!(bool) $form_state->getValue('admin_ral_accept')) {
          throw new \RuntimeException('DX-RAL confirmation required.');
        }
        $version = '1.0';
        if (\Drupal::moduleHandler()->moduleExists('dx_ecosystem') && \Drupal::hasService('dx_ecosystem.agreements')) {
          $ral = \Drupal::service('dx_ecosystem.agreements')->currentRal();
          if ($ral) {
            $version = $ral['version'];
          }
        }
        $uid = (int) $this->currentUser()->id();
        $entity->set('ral_accepted', TRUE);
        $entity->set('ral_version', $version);
        $entity->set('ral_accepted_at', \Drupal::time()->getRequestTime());
        $entity->set('ral_accepter_uid', $uid);
        $entity->save();
        if (\Drupal::hasService('dx_ecosystem.acks')) {
          \Drupal::service('dx_ecosystem.acks')->record('dx_ral', $version, [
            'source' => 'admin_approve_form',
            'request_id' => (string) $entity->id(),
            'tenant_machine' => (string) $entity->get('tenant_machine')->value,
          ], $uid);
        }
      }

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
