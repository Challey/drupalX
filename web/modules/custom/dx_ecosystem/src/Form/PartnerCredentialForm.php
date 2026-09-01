<?php

declare(strict_types=1);

namespace Drupal\dx_ecosystem\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\dx_ecosystem\Service\Composer\SatisMetadataBuilder;
use Drupal\dx_ecosystem\Service\CredentialLifecycle;
use Drupal\dx_ecosystem\Service\L2ComposerRepository;
use Drupal\dx_ecosystem\Service\PartnerCredentialStore;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Certified developer: issue / rotate L2 Composer+Git credential (shown once).
 */
final class PartnerCredentialForm extends FormBase {

  public function __construct(
    protected PartnerCredentialStore $credentials,
    protected ?L2ComposerRepository $repository = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('dx_ecosystem.credentials'),
      $container->get('dx_ecosystem.l2_repository'),
    );
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
      // Phase I / I3: the partner sees the same counters the admin report shows.
      $life = $this->credentials->lifecycle($uid);
      $formatter = \Drupal::service('date.formatter');
      $form['lifecycle'] = [
        '#markup' => '<p><small>' . $this->t('状态 @state · 轮换 @r 次 · 调用 @u 次 · 最后使用 @last · 累计闲置 @days 天', [
          '@state' => $life['state'],
          '@r' => $life['rotations'],
          '@u' => $life['uses'],
          '@last' => $life['last_used'] > 0
            ? $formatter->format((int) $life['last_used'], 'custom', 'Y-m-d H:i')
            : (string) $this->t('从未'),
          '@days' => $life['last_used'] > 0
            ? (string) (int) floor((\Drupal::time()->getRequestTime() - $life['last_used']) / 86400)
            : '—',
        ]) . '</small></p>',
      ];
      if ($this->repository !== NULL) {
        $plan = $this->repository->plan();
        $form['host'] = [
          '#markup' => '<p><small>' . $this->t('仓库：@driver · @url', [
            '@driver' => (string) $plan['driver'],
            '@url' => rtrim((string) $plan['served_root_url'], '/') . '/' . SatisMetadataBuilder::ROOT_FILE,
          ]) . '</small></p>',
        ];
      }
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
    if (is_array($status) && empty($status['revoked'])) {
      $form['actions']['revoke'] = [
        '#type' => 'submit',
        '#value' => $this->t('Revoke this credential'),
        '#submit' => ['::submitRevoke'],
        '#limit_validation_errors' => [],
      ];
    }
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

  /**
   * Self-service revocation (I2): the token dies immediately, re-issue is fine.
   */
  public function submitRevoke(array &$form, FormStateInterface $form_state): void {
    $uid = (int) $this->currentUser()->id();
    $this->credentials->revoke($uid, 'self_revoked', $uid);
    $this->messenger()->addStatus($this->t('凭证已吊销：旧 token 在任何入口都不再生效，@code。需要时重新签发即可。', [
      '@code' => CredentialLifecycle::STATE_REVOKED,
    ]));
    $form_state->setRebuild(TRUE);
  }

}
