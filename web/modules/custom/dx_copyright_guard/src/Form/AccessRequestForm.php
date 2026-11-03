<?php

declare(strict_types=1);

namespace Drupal\dx_copyright_guard\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\RedirectCommand;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\dx_copyright_guard\Service\AcceptanceStorage;
use Drupal\dx_copyright_guard\Service\InvestorAccess;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Investor access request and invite-code entry point.
 */
final class AccessRequestForm extends FormBase {

  private InvestorAccess $investorAccess;

  private AcceptanceStorage $acceptance;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->investorAccess = $container->get('dx_copyright_guard.investor_access');
    $instance->acceptance = $container->get('dx_copyright_guard.acceptance');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'dx_copyright_guard_access_request';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $account = $this->currentUser();
    $uid = (int) $account->id();
    $destination = $this->sanitizeDestination((string) $this->getRequest()->query->get('destination', ''));
    $inviteCode = trim((string) $this->getRequest()->query->get('invite', ''));
    $status = $this->investorAccess->status($uid);
    $hasAccess = $this->investorAccess->hasAccess($account);
    $isAdmin = $this->investorAccess->isAdmin($account);

    $form['#attached']['library'][] = 'dx_copyright_guard/agreement';
    $form['#attached']['library'][] = 'dx_copyright_guard/investor';
    $form['#prefix'] = '<div class="dxcg-access" id="dxcg-access">';
    $form['#suffix'] = '</div>';

    $form['heading'] = [
      '#markup' => '<div class="dxcg-heading">'
        . '<div class="dxcg-kicker">INVESTOR ACCESS</div>'
        . '<h1 class="dxcg-title">投资者访问</h1>'
        . '</div>',
    ];

    if ($hasAccess) {
      $referrer = $this->investorAccess->shareOwnerName((int) ($status['invited_by'] ?? 0));
      $form['access'] = [
        '#markup' => '<div class="dxcg-access-card is-ok">'
          . '<p>' . $this->t('当前账号已获得投资者访问权限。') . '</p>'
          . ($referrer !== '' ? '<p class="dxcg-access-referrer">' . $this->t('由 @name 分享开通。', ['@name' => htmlspecialchars($referrer, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')]) . '</p>' : '')
          . '<p class="dxcg-access-actions">'
          . '<a class="dxcg-access-primary" href="' . htmlspecialchars($destination, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
          . $this->t('打开投资人报告') . ' →</a>'
          . '<a class="dxcg-access-secondary" href="' . htmlspecialchars(Url::fromRoute('dx_copyright_guard.documents')->toString(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
          . $this->t('我的文档') . '</a>'
          . '</p>'
          . '</div>',
      ];

      if ($this->investorAccess->canShare($account)) {
        $share = $this->investorAccess->shareInvite($uid);
        if ($share !== NULL) {
          $link = $this->investorAccess->inviteUrl((string) $share->code);
          // Form controls must be render elements: #markup is XSS filtered and
          // silently drops <input>/<button>, which left only the button label.
          $form['share'] = [
            '#type' => 'container',
            '#attributes' => [
              'class' => ['dxcg-share'],
              'data-dxcg-share' => '',
            ],
            'kicker' => [
              '#markup' => '<div class="dxcg-kicker">SHARE ACCESS</div>',
            ],
            'title' => [
              '#markup' => '<h2 class="dxcg-share-title">' . $this->t('分享阅读权限') . '</h2>',
            ],
            'lead' => [
              '#markup' => '<p class="dxcg-share-lead">' . $this->t('将下方个人链接发给确有需要的合作方或投资人。对方登录并签署保密协议后即可阅读，无需管理员审核。') . '</p>',
            ],
            'row' => [
              '#type' => 'container',
              '#attributes' => ['class' => ['dxcg-share-row']],
              'input' => [
                '#type' => 'html_tag',
                '#tag' => 'input',
                '#attributes' => [
                  'class' => ['dxcg-share-input'],
                  'type' => 'text',
                  'readonly' => 'readonly',
                  'value' => $link,
                  'aria-label' => (string) $this->t('分享链接'),
                ],
              ],
              'copy' => [
                '#type' => 'html_tag',
                '#tag' => 'button',
                '#attributes' => [
                  'type' => 'button',
                  'class' => ['dxcg-share-copy'],
                  'data-dxcg-copy' => $link,
                ],
                '#value' => $this->t('复制链接'),
              ],
              'native' => [
                '#type' => 'html_tag',
                '#tag' => 'button',
                '#attributes' => [
                  'type' => 'button',
                  'class' => ['dxcg-share-copy', 'is-secondary'],
                  'data-dxcg-share-native' => '',
                ],
                '#value' => $this->t('系统分享'),
              ],
            ],
            'tip' => [
              '#markup' => '<p class="dxcg-share-tip">' . $this->t('复制链接后通过微信、邮件或 IM 私信发送；手机浏览器可点“系统分享”直接调起应用，微信内请点右上角“…”发送给朋友。') . '</p>',
            ],
            'meta' => [
              '#markup' => '<p class="dxcg-share-meta">' . $this->t('邀请码 @code · 已邀请 @uses 人 · 归属：@owner', [
                '@code' => (string) $share->code,
                '@uses' => (int) $share->uses,
                '@owner' => htmlspecialchars((string) $account->getDisplayName(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
              ]) . '</p>',
            ],
            'norms' => [
              '#markup' => '<details class="dxcg-share-norms" open>'
                . '<summary>' . $this->t('分享规范') . '</summary>'
                . '<ul>'
                . '<li>' . $this->t('仅分享给确有阅读需求的合作方或投资人，请勿公开发布或转贴到公开渠道。') . '</li>'
                . '<li>' . $this->t('对方需登录并签署保密协议后才会获得阅读权限，邀请码本身不代表授权。') . '</li>'
                . '<li>' . $this->t('请勿截图、录屏或二次转发受保护内容；发现泄露请立即通知管理员。') . '</li>'
                . '<li>' . $this->t('管理员可随时停用分享码并撤销访问权限。') . '</li>'
                . '</ul>'
                . '</details>',
            ],
            'hint' => [
              '#markup' => '<p class="dxcg-hint" data-dxcg-share-hint></p>',
            ],
          ];
        }
      }
    }
    else {
      $form['intro'] = [
        '#markup' => '<div class="dxcg-access-card dxcg-access-intro">'
          . '<p>' . $this->t('本报告仅向受邀投资者与合作伙伴开放。您可以使用邀请码直接开通，或提交申请等待管理员审核。') . '</p>'
          . '</div>',
      ];

      if ($status['status'] === 'pending') {
        $form['status'] = [
          '#markup' => '<div class="dxcg-access-card is-pending">'
            . '<div class="dxcg-access-state">' . $this->t('申请审核中') . '</div>'
            . '<p>' . $this->t('您的访问申请已提交，管理员审核通过后即可阅读。') . '</p>'
            . '</div>',
        ];
      }
      elseif ($status['status'] === 'denied' || $status['status'] === 'revoked') {
        $note = trim($status['note']);
        $form['status'] = [
          '#markup' => '<div class="dxcg-access-card is-denied">'
            . '<div class="dxcg-access-state">' . $this->t('上次申请未通过') . '</div>'
            . ($note !== '' ? '<p>' . $this->t('审核备注：@note', ['@note' => htmlspecialchars($note, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')]) . '</p>' : '')
            . '<p>' . $this->t('您可以补充说明后重新申请，或使用有效的邀请码直接开通。') . '</p>'
            . '</div>',
        ];
      }
    }

    if (!$hasAccess) {
      $form['invite_wrap'] = [
        '#type' => 'details',
        '#title' => $this->t('使用邀请码'),
        '#open' => TRUE,
        '#attributes' => [
          'class' => ['dxcg-panel', 'dxcg-panel-invite'],
        ],
      ];
      $valid = $inviteCode !== '' ? $this->investorAccess->findValidInvite($inviteCode) : NULL;
      if ($inviteCode !== '' && $valid === NULL) {
        $form['invite_wrap']['invalid'] = [
          '#markup' => '<div class="dxcg-invite is-invalid">'
            . $this->t('邀请码无效、已停用或已过期。') . '</div>',
        ];
      }
      elseif ($valid !== NULL) {
        $owner = $this->investorAccess->shareOwnerName((int) ($valid->owner_uid ?? 0));
        $form['invite_wrap']['valid'] = [
          '#markup' => '<div class="dxcg-invite is-valid">'
            . ($owner !== ''
              ? $this->t('@name 邀请您阅读本报告。点击下方按钮，签署协议后即可开通。', [
                '@name' => htmlspecialchars($owner, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
              ])
              : $this->t('邀请码有效。点击下方按钮，签署协议后即可开通。'))
            . '</div>',
        ];
      }
      $form['invite_wrap']['invite'] = [
        '#type' => 'textfield',
        '#title' => $this->t('邀请码'),
        '#default_value' => $inviteCode,
        '#placeholder' => 'TPST-XXXX-XXXX',
        '#description' => $this->t('邀请码由管理员生成；使用邀请码无需等待人工审核。'),
        '#maxlength' => 32,
      ];
      $form['invite_wrap']['use_invite'] = [
        '#type' => 'submit',
        '#value' => $this->t('使用邀请码'),
        '#name' => 'use_invite',
        '#button_type' => 'primary',
        '#attributes' => [
          'class' => ['dxcg-btn', 'dxcg-btn-primary'],
        ],
        '#ajax' => [
          'callback' => '::ajaxGo',
          'wrapper' => 'dxcg-access',
          'effect' => 'fade',
        ],
      ];

      $form['request_wrap'] = [
        '#type' => 'details',
        '#title' => $this->t('申请访问'),
        '#open' => $status['status'] !== 'pending',
        '#attributes' => [
          'class' => ['dxcg-panel', 'dxcg-panel-request'],
        ],
      ];
      $form['request_wrap']['message'] = [
        '#type' => 'textarea',
        '#title' => $this->t('申请说明'),
        '#default_value' => (string) ($status['message'] ?? ''),
        '#rows' => 3,
        '#description' => $this->t('可填写机构、投资方向或引荐人，便于管理员快速审核。'),
      ];
      $form['request_wrap']['request'] = [
        '#type' => 'submit',
        '#value' => $status['status'] === 'pending' ? $this->t('更新申请') : $this->t('提交申请'),
        '#name' => 'request_access',
        '#attributes' => [
          'class' => ['dxcg-btn', 'dxcg-btn-secondary'],
        ],
        '#ajax' => [
          'callback' => '::ajaxGo',
          'wrapper' => 'dxcg-access',
          'effect' => 'fade',
        ],
      ];
    }

    $form['destination'] = [
      '#type' => 'hidden',
      '#value' => $destination,
    ];

    if ($isAdmin) {
      $form['admin'] = [
        '#markup' => '<p class="dxcg-admin-link"><a href="' . Url::fromRoute('dx_copyright_guard.investors')->toString() . '">'
          . $this->t('管理员：打开投资者审核台') . ' →</a></p>',
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $trigger = (string) ($form_state->getTriggeringElement()['#name'] ?? '');
    if ($trigger !== 'use_invite') {
      return;
    }
    $code = trim((string) $form_state->getValue('invite'));
    if ($code === '') {
      $form_state->setErrorByName('invite', $this->t('请输入邀请码。'));
      return;
    }
    if ($this->investorAccess->findValidInvite($code) === NULL) {
      $form_state->setErrorByName('invite', $this->t('邀请码无效、已停用或已过期。'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $trigger = (string) ($form_state->getTriggeringElement()['#name'] ?? '');
    $destination = $this->sanitizeDestination((string) $form_state->getValue('destination'));
    $uid = (int) $this->currentUser()->id();
    $target = $this->accessUrl($destination);

    if ($trigger === 'use_invite') {
      $code = trim((string) $form_state->getValue('invite'));
      if ($this->acceptance->hasAccepted($uid)) {
        // Already signed: the invite is the only remaining step.
        $result = $this->investorAccess->redeemInvite($uid, $code);
        $this->messenger()->addStatus($result['message']);
        if ($result['ok']) {
          $target = $destination;
        }
      }
      else {
        $target = $this->agreementUrl($destination, $code);
        $this->messenger()->addStatus($this->t('邀请码有效；请阅读并同意协议后即可访问。'));
      }
    }
    elseif ($trigger === 'request_access') {
      $this->investorAccess->request($uid, (string) $form_state->getValue('message'));
      $this->messenger()->addStatus($this->t('访问申请已提交，管理员审核通过后即可阅读。'));
    }

    $form_state->set('dxcg_redirect', $target);
    if ($this->getRequest()->isXmlHttpRequest()) {
      return;
    }
    $form_state->setResponse(new RedirectResponse($target));
  }

  /**
   * AJAX callback: redirect the whole page (keeps WebView POSTs short).
   */
  public function ajaxGo(array &$form, FormStateInterface $form_state): AjaxResponse {
    $target = (string) ($form_state->get('dxcg_redirect') ?? '');
    if ($target === '') {
      $target = $this->accessUrl($this->sanitizeDestination((string) $form_state->getValue('destination')));
    }
    $response = new AjaxResponse();
    $response->addCommand(new RedirectCommand($target));
    return $response;
  }

  /**
   * URL of the access page for a destination.
   */
  private function accessUrl(string $destination): string {
    return Url::fromRoute('dx_copyright_guard.access', [], [
      'query' => ['destination' => $destination],
    ])->toString();
  }

  /**
   * URL of the agreement page carrying an invite code.
   */
  private function agreementUrl(string $destination, string $code): string {
    return Url::fromRoute('dx_copyright_guard.agreement', [], [
      'query' => [
        'destination' => $destination,
        'invite' => InvestorAccess::normalizeCode($code),
      ],
    ])->toString();
  }

  /**
   * Only allow local absolute paths (prevents open redirects).
   */
  private function sanitizeDestination(string $destination): string {
    $destination = trim($destination);
    if ($destination === '' || !str_starts_with($destination, '/') || str_starts_with($destination, '//')) {
      return '/investor/';
    }
    return $destination;
  }

}
