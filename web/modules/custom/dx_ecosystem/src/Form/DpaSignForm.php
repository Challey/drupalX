<?php

declare(strict_types=1);

namespace Drupal\dx_ecosystem\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\dx_ecosystem\Service\AgreementAckStore;
use Drupal\dx_ecosystem\Service\AgreementRepository;
use Drupal\dx_ecosystem\Service\DeveloperCertificationStore;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Developer DPA signing form (OE1 / OE2: sign → pending certification).
 */
final class DpaSignForm extends FormBase {

  public function __construct(
    protected AgreementRepository $agreements,
    protected AgreementAckStore $acks,
    protected DeveloperCertificationStore $certs,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('dx_ecosystem.agreements'),
      $container->get('dx_ecosystem.acks'),
      $container->get('dx_ecosystem.certs'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'dx_ecosystem_dpa_sign_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $dpa = $this->agreements->currentDpa();
    if ($dpa === NULL) {
      $form['missing'] = ['#markup' => $this->t('DPA text not found.')];
      return $form;
    }

    $cert = $this->certs->get();
    $form['cert_status'] = [
      '#markup' => '<p>' . $this->t('Certification status: <strong>@status</strong> (DPA @dpa). Partner vault opens after platform certify.', [
        '@status' => $cert['status'],
        '@dpa' => $cert['dpa_version'] !== '' ? 'v' . $cert['dpa_version'] : '—',
      ]) . '</p>',
    ];

    $existing = $this->acks->latestDpaForUser();
    if ($existing && ($existing['version'] ?? '') === $dpa['version']) {
      $form['status'] = [
        '#markup' => $this->t('You already signed DPA v@version on @time.', [
          '@version' => $existing['version'],
          '@time' => date('c', (int) $existing['created']),
        ]),
      ];
    }

    $form['body'] = [
      '#type' => 'textarea',
      '#title' => $dpa['title'] . ' v' . $dpa['version'],
      '#default_value' => $dpa['body'],
      '#rows' => 18,
      '#attributes' => ['readonly' => 'readonly'],
    ];

    $form['accept'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I am authorized to sign and accept this Developer Participation Agreement.'),
      '#required' => TRUE,
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Sign DPA'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $dpa = $this->agreements->currentDpa();
    if ($dpa === NULL) {
      return;
    }
    $uid = (int) $this->currentUser()->id();
    $this->acks->record('dpa', $dpa['version'], ['source' => 'dpa_sign_form'], $uid);
    $cert = $this->certs->markPending($uid, $dpa['version'], 'Signed via DPA form');
    $this->messenger()->addStatus($this->t('DPA v@version recorded. Certification status: @status. Await platform review for L2 vault.', [
      '@version' => $dpa['version'],
      '@status' => $cert['status'],
    ]));
    $form_state->setRedirectUrl(Url::fromRoute('dx_ecosystem.dpa_sign'));
  }

}
