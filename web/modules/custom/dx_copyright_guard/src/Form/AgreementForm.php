<?php

declare(strict_types=1);

namespace Drupal\dx_copyright_guard\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\RedirectCommand;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\dx_copyright_guard\Service\AcceptanceStorage;
use Drupal\dx_copyright_guard\Service\InvestorAccess;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Copyright + confidentiality agreement acceptance form.
 */
final class AgreementForm extends FormBase {

  private AcceptanceStorage $acceptance;

  private InvestorAccess $investorAccess;

  /**
   * Optional shared signature store (dx_signature module).
   *
   * NULL on sites where the module is not installed yet, which keeps this
   * module backward compatible during rollout.
   */
  private ?object $signatures = NULL;

  /**
   * Used to read race-entry signatures saved before dx_signature existed.
   */
  private ?EntityTypeManagerInterface $entityTypeManager = NULL;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->acceptance = $container->get('dx_copyright_guard.acceptance');
    $instance->investorAccess = $container->get('dx_copyright_guard.investor_access');
    $instance->signatures = $container->has('dx_signature.storage')
      ? $container->get('dx_signature.storage')
      : NULL;
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * Page title callback.
   */
  public static function getTitle(): string {
    $title = (string) (\Drupal::config('dx_copyright_guard.settings')->get('agreement_title') ?? '');
    return $title !== '' ? $title : '版权与保密协议';
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'dx_copyright_guard_agreement';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('dx_copyright_guard.settings');
    $destination = $this->sanitizeDestination((string) $this->getRequest()->query->get('destination', ''));

    $form['#attached']['library'][] = 'dx_copyright_guard/agreement';
    // AJAX wrapper: keeps the whole form replaceable without a full page POST.
    $form['#prefix'] = '<div id="dxcg-agreement">';
    $form['#suffix'] = '</div>';

    $intro = (string) ($config->get('agreement_intro') ?? '');
    $terms = (string) ($config->get('agreement_terms') ?? '');
    $version = (string) ($config->get('agreement_version') ?? '1.0');
    $title = (string) ($config->get('agreement_title') ?? '');
    $account = $this->currentUser();
    $uid = (int) $account->id();
    $inviteCode = trim((string) $this->getRequest()->query->get('invite', ''));
    $invite = $inviteCode !== '' ? $this->investorAccess->findValidInvite($inviteCode) : NULL;
    $accepted = $this->acceptance->hasAccepted($uid);
    // Latest handwritten signature for this agreement version (if the shared
    // dx_signature module is enabled).
    $signature = $this->signatures !== NULL
      ? $this->signatures->latest($uid, 'investor_agreement', $version, TRUE)
      : [];
    $signed = trim((string) ($signature['data_url'] ?? '')) !== '';

    // Reuse the handwritten signature the member already left in the Xtrail
    // race entry form. Signing the race waiver is the same person making a
    // handwritten mark, so asking for a second drawing is unnecessary; the
    // reused image is still archived under `investor_agreement` on submit.
    $reused = ($signed || $this->signatures === NULL) ? [] : $this->latestRaceSignature($uid);
    $prefill = $signed
      ? (string) ($signature['data_url'] ?? '')
      : (string) ($reused['data_url'] ?? '');

    $form['heading'] = [
      '#markup' => '<div class="dxcg-heading">'
        . '<div class="dxcg-kicker">CONFIDENTIAL</div>'
        . '<h1 class="dxcg-title">' . htmlspecialchars($title !== '' ? $title : '版权与保密协议', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1>'
        . '</div>',
    ];
    $form['intro'] = [
      '#markup' => '<div class="dxcg-intro">' . $this->plainToHtml($intro) . '</div>',
    ];
    $form['meta'] = [
      '#markup' => '<div class="dxcg-meta">'
        . $this->t('访问者：@name（UID @uid） · 协议版本：@version · 时间：@time', [
          '@name' => $account->getDisplayName(),
          '@uid' => (string) $account->id(),
          '@version' => $version,
          '@time' => date('Y-m-d H:i'),
        ])
        . '</div>',
    ];
    $form['terms'] = [
      '#markup' => '<div class="dxcg-terms" tabindex="0">' . $this->plainToHtml($terms) . '</div>',
    ];

    if ($accepted) {
      $form['already'] = [
        '#markup' => '<div class="dxcg-already">'
          . ($signed
            ? $this->t('您已签署当前版本协议并留存手写签名，可直接继续阅读。')
            : ($reused !== []
              ? $this->t('您已签署当前版本协议，已自动复用赛事报名签名；可直接继续阅读，也可重新签名后提交以更新存证。')
              : $this->t('您已签署当前版本协议，但尚未留存手写签名；请补签后继续阅读。')))
          . ' <a class="dxcg-continue" href="' . htmlspecialchars($destination, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
          . $this->t('直接继续阅读') . ' →</a></div>',
      ];
    }

    if ($invite !== NULL) {
      $owner = $this->investorAccess->shareOwnerName((int) ($invite->owner_uid ?? 0));
      $form['invite_notice'] = [
        '#markup' => '<div class="dxcg-invite is-valid">'
          . $this->t('邀请码 <b>@code</b> 有效：签署本协议后，将自动为您开通投资者访问权限。', [
            '@code' => htmlspecialchars((string) $invite->code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
          ])
          . ($owner !== '' ? '<br>' . $this->t('该链接由 @name 分享。', ['@name' => htmlspecialchars($owner, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')]) : '')
          . '</div>',
      ];
    }
    elseif ($inviteCode !== '') {
      $form['invite_notice'] = [
        '#markup' => '<div class="dxcg-invite is-invalid">'
          . $this->t('邀请码无效、已停用或已过期；您仍可签署协议后提交访问申请。')
          . '</div>',
      ];
    }

    $form['destination'] = [
      '#type' => 'hidden',
      '#value' => $destination,
    ];
    $form['invite'] = [
      '#type' => 'hidden',
      '#value' => $inviteCode,
    ];

    if ($this->signatures !== NULL) {
      $saved = '';
      if ($signed) {
        $signedAt = (int) ($signature['signed_at'] ?? 0);
        $saved = '<div class="dxcg-signature-saved">'
          . '<span>' . $this->t('已留存签名 · 签名时间：@time', [
            '@time' => $signedAt > 0 ? date('Y-m-d H:i', $signedAt) : '-',
          ]) . '</span>'
          . '</div>';
      }
      elseif ($reused !== []) {
        // Show when and where the reused signature came from so the signer
        // knows this is their own race-entry signature, not a blank form.
        $reusedAt = (int) ($reused['signed_at'] ?? 0);
        if ($reusedAt <= 0) {
          $reusedAt = (int) ($reused['created'] ?? 0);
        }
        $saved = '<div class="dxcg-signature-reuse">'
          . $this->t('已自动复用您在赛事报名中留存的手写签名（@time）。无需重复签名，勾选协议后直接提交即可；如需重签，可在画布上直接重写。', [
            '@time' => $reusedAt > 0 ? date('Y-m-d H:i', $reusedAt) : '-',
          ])
          . '</div>';
      }
      $hasSignature = $signed || $reused !== [];
      if ($signed) {
        $signatureTip = $this->t('已留存当前版本签名；如需更新，可在下方画布重新书写。');
      }
      elseif ($reused !== []) {
        $signatureTip = $this->t('已自动带入赛事报名签名；如需重签，可在下方画布重新书写。签名将与账号、时间一起存证。');
      }
      else {
        $signatureTip = $this->t('机密文档除勾选协议外还需亲笔签名。请用鼠标或手指在下方画布书写，签名将与账号、时间一起存证。');
      }
      $form['signature_block'] = [
        '#markup' => '<div class="dxcg-signature" data-dxcg-sign-reused="' . ($reused !== [] ? '1' : '0') . '">'
          . '<div class="dxcg-signature-head">'
          . '<strong>' . $this->t('手写签名') . '</strong>'
          . '<span class="dxcg-signature-state' . ($hasSignature ? ' is-on' : '') . '" data-dxcg-sign-state>'
          . ($signed ? $this->t('已签名') : ($reused !== [] ? $this->t('已复用报名签名') : $this->t('尚未签名')))
          . '</span>'
          . '</div>'
          . '<p class="dxcg-signature-tip">' . $signatureTip . '</p>'
          . $saved
          . '<canvas class="dxcg-sign-canvas" data-dxcg-sign-canvas width="900" height="260" aria-label="' . $this->t('手写签名') . '"></canvas>'
          . '<div class="dxcg-signature-actions">'
          . '<button type="button" class="dxcg-sign-clear" data-dxcg-sign-clear>' . $this->t('重写') . '</button>'
          . '<span class="dxcg-signature-live" data-dxcg-sign-live>' . ($reused !== [] ? $this->t('已复用赛事报名签名，可直接提交') : $this->t('请在上方画布签名')) . '</span>'
          . '</div>'
          . '</div>',
      ];
      $form['signature'] = [
        '#type' => 'hidden',
        '#value' => $prefill,
        '#attributes' => ['data-dxcg-sign-value' => '1'],
      ];
    }

    $form['accept'] = [
      '#type' => 'checkbox',
      '#title' => (string) ($config->get('checkbox_label') ?? '我已阅读并同意上述协议。'),
      '#required' => TRUE,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('同意并继续阅读'),
      '#button_type' => 'primary',
      '#ajax' => [
        'callback' => '::ajaxSubmit',
        'wrapper' => 'dxcg-agreement',
        'effect' => 'fade',
      ],
      '#attributes' => [
        'class' => ['dxcg-accept-button'],
        'data-dxcg-submit' => '1',
      ],
    ];
    $form['accept_hint'] = [
      '#markup' => '<p class="dxcg-hint" role="status" aria-live="polite"></p>',
    ];
    $form['notice'] = [
      '#markup' => '<p class="dxcg-notice">' . $this->t('签署记录（账号、时间、IP、浏览器标识、协议版本）将被保存为审计证据。') . '</p>',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $destination = $this->sanitizeDestination((string) $form_state->getValue('destination'));
    $uid = (int) $this->currentUser()->id();
    $invite = trim((string) $form_state->getValue('invite'));

    // A signature is mandatory once the shared dx_signature module is
    // available. It may be the race-entry signature prefilled above or a fresh
    // drawing; either way the service validates and stores it under
    // `investor_agreement` with uid, context, IP and user agent.
    if ($this->signatures !== NULL) {
      $signature = trim((string) $form_state->getValue('signature'));
      if ($signature === '') {
        $form_state->setErrorByName('signature', $this->t('请先在画布上完成手写签名。'));
        return;
      }
      $version = (string) $this->acceptance->getVersion();
      $existing = $this->signatures->latest($uid, 'investor_agreement', $version, TRUE);
      // Re-submitting an untouched signature must not create duplicate audit
      // rows; a redrawn signature is different and is stored as a new revision.
      if (($existing['data_url'] ?? '') !== $signature) {
        $saved = $this->signatures->save(
          $uid,
          'investor_agreement',
          $signature,
          $version,
          $this->currentUser()->getDisplayName(),
        );
        if (empty($saved['ok'])) {
          $form_state->setErrorByName('signature', $this->t('签名图片无效或过大，请重写后再提交。'));
          return;
        }
      }
    }

    $this->acceptance->record($uid, $destination);
    $target = $destination;

    if ($this->investorAccess->hasAccess($this->currentUser())) {
      $this->messenger()->addStatus($this->t('已记录您的签署与手写签名（协议版本 @version）。', [
        '@version' => $this->acceptance->getVersion(),
      ]));
    }
    elseif ($invite !== '') {
      // Invite link flow: signing is the only review step.
      $result = $this->investorAccess->redeemInvite($uid, $invite);
      $this->messenger()->addStatus($this->t('签署与手写签名已记录（协议版本 @version）。@result', [
        '@version' => $this->acceptance->getVersion(),
        '@result' => $result['message'],
      ]));
      if (!$result['ok']) {
        $target = $this->accessUrl($destination, $invite);
      }
    }
    else {
      $this->messenger()->addStatus($this->t('签署与手写签名已记录（协议版本 @version）。请提交访问申请或使用邀请码。', [
        '@version' => $this->acceptance->getVersion(),
      ]));
      $target = $this->accessUrl($destination);
    }

    $form_state->set('dxcg_redirect', $target);
    // AJAX submissions are redirected by ajaxSubmit(); a full-page POST uses
    // the traditional 302 response. This keeps both paths working and avoids
    // the full-page POST that some in-app WebViews abort.
    if ($this->getRequest()->isXmlHttpRequest()) {
      return;
    }
    $form_state->setResponse(new RedirectResponse($target !== '' ? $target : '/'));
  }

  /**
   * AJAX submit callback: record first, then redirect the whole page.
   */
  public function ajaxSubmit(array &$form, FormStateInterface $form_state): AjaxResponse {
    $target = (string) ($form_state->get('dxcg_redirect') ?? '');
    if ($target === '') {
      $target = $this->sanitizeDestination((string) $form_state->getValue('destination'));
    }
    $response = new AjaxResponse();
    $response->addCommand(new RedirectCommand($target !== '' ? $target : '/'));
    return $response;
  }

  /**
   * Latest handwritten signature from the member's Xtrail race entries.
   *
   * The shared dx_signature archive is preferred. Older race entries predate
   * that archive, so the canonical `participant_data.signature` copy on the
   * registration entity is used as a fallback. Any failure is non-fatal: the
   * signer can still draw on the canvas.
   *
   * @return array<string, mixed>
   *   Row containing at least `data_url`, plus an optional epoch `signed_at`.
   */
  private function latestRaceSignature(int $uid): array {
    if ($uid <= 0) {
      return [];
    }
    if ($this->signatures !== NULL) {
      $row = $this->signatures->latest($uid, 'race_registration', '', TRUE);
      if (trim((string) ($row['data_url'] ?? '')) !== '') {
        return $row;
      }
    }
    if ($this->entityTypeManager === NULL || !$this->entityTypeManager->hasDefinition('tpst_registration')) {
      return [];
    }
    try {
      $storage = $this->entityTypeManager->getStorage('tpst_registration');
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('uid', $uid)
        ->sort('created', 'DESC')
        ->range(0, 50)
        ->execute();
      foreach ($storage->loadMultiple($ids) as $registration) {
        $field = $registration->get('participant_data');
        $data = $field->isEmpty() ? [] : ($field->first()?->getValue() ?? []);
        $dataUrl = trim((string) ($data['signature'] ?? ''));
        if ($dataUrl === '') {
          continue;
        }
        // Normalise/validate the archived copy; a malformed old entry should
        // fall through to "draw your signature" instead of rendering broken.
        $parsed = $this->signatures?->parse($dataUrl);
        if ($parsed === NULL) {
          continue;
        }
        $row = ['data_url' => $parsed['data_url']];
        $signedAt = $data['signature_time'] ?? '';
        if (is_numeric($signedAt)) {
          $ts = (int) $signedAt;
          if ($ts > 9999999999) {
            $ts = (int) floor($ts / 1000);
          }
          $row['signed_at'] = $ts;
        }
        elseif (is_string($signedAt) && $signedAt !== '') {
          $ts = strtotime($signedAt);
          if ($ts !== FALSE) {
            $row['signed_at'] = $ts;
          }
        }
        return $row;
      }
    }
    catch (\Throwable $e) {
      $this->getLogger('dx_copyright_guard')->notice('Unable to load race signature for uid @uid: @m', [
        '@uid' => $uid,
        '@m' => $e->getMessage(),
      ]);
    }
    return [];
  }

  /**
   * Builds the access-request page URL while preserving the destination.
   */
  private function accessUrl(string $destination, string $invite = ''): string {
    $query = ['destination' => $destination];
    if ($invite !== '') {
      $query['invite'] = $invite;
    }
    return Url::fromRoute('dx_copyright_guard.access', [], ['query' => $query])->toString();
  }

  /**
   * Only allow local absolute paths (prevents open redirects).
   */
  private function sanitizeDestination(string $destination): string {
    $destination = trim($destination);
    if ($destination === '' || !str_starts_with($destination, '/') || str_starts_with($destination, '//')) {
      return '/';
    }
    return $destination;
  }

  /**
   * Escapes plain text and preserves line breaks.
   */
  private function plainToHtml(string $text): string {
    return nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
  }

}
