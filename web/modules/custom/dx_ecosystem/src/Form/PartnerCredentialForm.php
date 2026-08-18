<?php

declare(strict_types=1);

namespace Drupal\dx_ecosystem\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\dx_ecosystem\Service\PartnerCredentialStore;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Certified developer: issue / rotate L2 Composer+Git credential (shown once).
 */
final class PartnerCredentialForm extends FormBase {

  public function __construct(
    protected PartnerCredentialStore $credentials,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('dx_ecosystem.credentials'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'dx_ecosystem_partner_credential_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $uid = (int) $this->currentUser()->id();
    $status = $this->credentials->status($uid);
    $issued = $form_state->get('issued');

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('L2 private Composer/Git token. Plaintext is shown once on this page; rotating invalidates the previous token. Revoking certification also revokes the token.') . '</p>',
    ];
    if (is_array($status)) {
      $form['current'] = [
        '#markup' => '<p>' . $this->t('Current prefix: @p · revoked: @r', [
          '@p' => $status['prefix'] ?: '—',
          '@r' => !empty($status['revoked']) ? $this->t('yes') : $this->t('no'),
        ]) . '</p>',
      ];
    }
    if (is_array($issued) && !empty($issued['composer'])) {
      $json = json_encode($issued['composer'], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
      $form['once'] = [
        '#markup' => '<div class="messages messages--warning"><p><strong>' . $this->t('Copy now. This secret will not be shown again.') . '</strong></p><pre>' . htmlspecialchars((string) $json, ENT_QUOTES) . '</pre><p><code>' . htmlspecialchars((string) ($issued['git_clone'] ?? ''), ENT_QUOTES) . '</code></p></div>',
      ];
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['issue'] = [
      '#type' => 'submit',
      '#value' => $this->t('Issue / rotate credential'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $uid = (int) $this->currentUser()->id();
    try {
      $issued = $this->credentials->issue($uid);
    }
    catch (\RuntimeException $e) {
      $this->messenger()->addError($e->getMessage());
      return;
    }
    $form_state->set('issued', $issued);
    $form_state->setRebuild(TRUE);
  }

}
