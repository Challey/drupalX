<?php

declare(strict_types=1);

namespace Drupal\dx_auth\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\dx_auth\Service\EnterpriseAccountLinker;
use Drupal\dx_auth\Service\EnterpriseIdentityService;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin form to bind enterprise credit IDs to Drupal users.
 */
class EnterpriseBindingForm extends FormBase {

  public function __construct(
    protected EnterpriseIdentityService $identity,
    protected EnterpriseAccountLinker $linker,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('dx_auth.enterprise_identity'),
      $container->get('dx_auth.account_linker'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'dx_auth_enterprise_binding_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['credit_code'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Enterprise credit ID'),
      '#description' => $this->t('Unified social credit code (市场监督局统一社会信用代码), 18 characters.'),
      '#required' => TRUE,
      '#maxlength' => 32,
      '#attributes' => [
        'autocomplete' => 'off',
        'spellcheck' => 'false',
        'style' => 'text-transform:uppercase',
      ],
    ];

    $form['uid'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Drupal user'),
      '#target_type' => 'user',
      '#selection_settings' => [
        'include_anonymous' => FALSE,
      ],
      '#required' => TRUE,
    ];

    $form['company_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Company name'),
      '#maxlength' => 255,
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Bind enterprise ID'),
      ],
    ];

    $bindings = $this->linker->listBindings();
    $rows = [];
    foreach ($bindings as $row) {
      $user = User::load($row['uid']);
      $rows[] = [
        $this->identity->mask($row['credit_code']),
        $row['company_name'],
        $user ? $user->getDisplayName() . ' (' . $row['uid'] . ')' : (string) $row['uid'],
        $row['changed'] ? date('Y-m-d H:i', $row['changed']) : '—',
      ];
    }

    $form['existing'] = [
      '#type' => 'table',
      '#caption' => $this->t('Existing bindings'),
      '#header' => [
        $this->t('Credit ID'),
        $this->t('Company'),
        $this->t('User'),
        $this->t('Updated'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No enterprise bindings yet.'),
      '#weight' => 50,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $code = $this->identity->normalize((string) $form_state->getValue('credit_code'));
    if (!$this->identity->validate($code)) {
      $form_state->setErrorByName('credit_code', $this->t('Invalid unified social credit code.'));
    }
    $form_state->setValue('credit_code', $code);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $ok = $this->linker->bind(
      (string) $form_state->getValue('credit_code'),
      (int) $form_state->getValue('uid'),
      (string) $form_state->getValue('company_name'),
    );
    if ($ok) {
      $this->messenger()->addStatus($this->t('Enterprise ID bound successfully.'));
    }
    else {
      $this->messenger()->addError($this->t('Could not bind enterprise ID.'));
    }
  }

}
