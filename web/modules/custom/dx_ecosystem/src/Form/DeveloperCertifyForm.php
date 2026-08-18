<?php

declare(strict_types=1);

namespace Drupal\dx_ecosystem\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\dx_ecosystem\Service\AgreementRepository;
use Drupal\dx_ecosystem\Service\DeveloperCertificationStore;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin UI: review pending developers and certify / revoke (OE2).
 */
final class DeveloperCertifyForm extends FormBase {

  public function __construct(
    protected DeveloperCertificationStore $certs,
    protected AgreementRepository $agreements,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('dx_ecosystem.certs'),
      $container->get('dx_ecosystem.agreements'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'dx_ecosystem_developer_certify_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $dpa = $this->agreements->currentDpa();
    $form['intro'] = [
      '#markup' => '<p>' . $this->t('O5-A: DPA sign → pending → platform certify. Current DPA v@version.', [
        '@version' => $dpa['version'] ?? '?',
      ]) . '</p>',
    ];

    $rows = [];
    foreach ($this->certs->listByStatus() as $row) {
      $account = User::load($row['uid']);
      $name = $account ? $account->getDisplayName() : ('uid ' . $row['uid']);
      $rows[] = [
        $row['uid'],
        $name,
        $row['status'],
        $row['dpa_version'],
        $row['updated'] ? date('c', $row['updated']) : '—',
        $row['note'],
      ];
    }
    $form['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('UID'),
        $this->t('User'),
        $this->t('Status'),
        $this->t('DPA'),
        $this->t('Updated'),
        $this->t('Note'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No certification records yet. Developers appear after signing DPA.'),
    ];

    $form['uid'] = [
      '#type' => 'number',
      '#title' => $this->t('User ID'),
      '#required' => TRUE,
      '#min' => 1,
    ];
    $form['note'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Review note'),
      '#maxlength' => 255,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['certify'] = [
      '#type' => 'submit',
      '#value' => $this->t('Certify'),
      '#submit' => ['::submitCertify'],
    ];
    $form['actions']['revoke'] = [
      '#type' => 'submit',
      '#value' => $this->t('Revoke'),
      '#submit' => ['::submitRevoke'],
      '#button_type' => 'danger',
    ];
    $form['partner_link'] = [
      '#type' => 'link',
      '#title' => $this->t('Open partner vault'),
      '#url' => Url::fromRoute('dx_ecosystem.partner_docs'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Unused; actions use dedicated submit handlers.
  }

  public function submitCertify(array &$form, FormStateInterface $form_state): void {
    $uid = (int) $form_state->getValue('uid');
    $dpa = $this->agreements->currentDpa();
    if ($dpa === NULL) {
      $this->messenger()->addError($this->t('DPA missing.'));
      return;
    }
    $this->certs->certify($uid, $dpa['version'], (string) $form_state->getValue('note'));
    $this->messenger()->addStatus($this->t('Certified uid @uid for DPA v@version.', [
      '@uid' => $uid,
      '@version' => $dpa['version'],
    ]));
  }

  public function submitRevoke(array &$form, FormStateInterface $form_state): void {
    $uid = (int) $form_state->getValue('uid');
    $this->certs->revoke($uid, (string) $form_state->getValue('note'));
    $this->messenger()->addWarning($this->t('Revoked certification for uid @uid.', ['@uid' => $uid]));
  }

}
