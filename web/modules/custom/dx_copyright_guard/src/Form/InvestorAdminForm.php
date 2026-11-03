<?php

declare(strict_types=1);

namespace Drupal\dx_copyright_guard\Form;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\dx_copyright_guard\Service\InvestorAccess;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Investor access review console: requests + invite codes.
 */
final class InvestorAdminForm extends FormBase {

  private InvestorAccess $investorAccess;

  private DateFormatterInterface $dateFormatter;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->investorAccess = $container->get('dx_copyright_guard.investor_access');
    $instance->dateFormatter = $container->get('date.formatter');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'dx_copyright_guard_investor_admin';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $account = $this->currentUser();
    $adminUid = (int) $account->id();
    $config = $this->config('dx_copyright_guard.settings');
    $minimum = (int) ($config->get('invite_auto_count') ?? 3);
    $this->investorAccess->ensureInvites($minimum, $adminUid);

    $form['#attached']['library'][] = 'dx_copyright_guard/investor';

    $form['summary'] = [
      '#markup' => '<div class="dxcg-admin-summary">'
        . '<div><span class="dxcg-admin-num">' . count($this->investorAccess->pending()) . '</span><span>待审核</span></div>'
        . '<div><span class="dxcg-admin-num">' . count($this->investorAccess->invites()) . '</span><span>邀请码</span></div>'
        . '<div><span class="dxcg-admin-num">' . count($this->investorAccess->recent(5)) . '</span><span>近期处理</span></div>'
        . '</div>',
    ];

    // ── Pending requests ───────────────────────────────────────────────────
    $options = [];
    foreach ($this->investorAccess->pending() as $row) {
      $options[$row->id] = [
        $row->name . ' (UID ' . $row->uid . ')',
        $row->mail ?: '—',
        $this->dateFormatter->format((int) $row->requested_at, 'short'),
        $row->message ?: '—',
        $row->source ?: 'request',
      ];
    }
    $form['requests'] = [
      '#type' => 'tableselect',
      '#header' => [
        $this->t('用户'),
        $this->t('邮箱'),
        $this->t('申请时间'),
        $this->t('说明'),
        $this->t('来源'),
      ],
      '#options' => $options,
      '#empty' => $this->t('暂无待审核申请。'),
    ];
    $form['request_action'] = [
      '#type' => 'select',
      '#title' => $this->t('处理方式'),
      '#options' => [
        'approve' => $this->t('批准并开通投资者权限'),
        'deny' => $this->t('拒绝申请'),
      ],
    ];
    $form['request_apply'] = [
      '#type' => 'submit',
      '#value' => $this->t('处理选中申请'),
      '#name' => 'request_apply',
      '#button_type' => 'primary',
    ];

    // ── Invite codes ───────────────────────────────────────────────────────
    $inviteOptions = [];
    foreach ($this->investorAccess->invites() as $row) {
      $owner = (int) ($row->owner_uid ?? 0) > 0
        ? ($row->owner_name ?: 'UID ' . $row->owner_uid)
        : $this->t('管理员');
      $inviteOptions[$row->id] = [
        $row->code,
        $this->investorAccess->inviteUrl((string) $row->code),
        $row->label ?: '—',
        $owner,
        (int) $row->max_uses > 0 ? $row->uses . ' / ' . $row->max_uses : $row->uses . ' / ∞',
        (int) $row->active ? $this->t('启用') : $this->t('停用'),
        $this->dateFormatter->format((int) $row->created_at, 'short'),
      ];
    }
    $form['invites'] = [
      '#type' => 'tableselect',
      '#header' => [
        $this->t('邀请码'),
        $this->t('邀请链接'),
        $this->t('备注'),
        $this->t('分享者'),
        $this->t('使用'),
        $this->t('状态'),
        $this->t('创建时间'),
      ],
      '#options' => $inviteOptions,
      '#empty' => $this->t('暂无邀请码。'),
    ];
    $form['invite_action'] = [
      '#type' => 'select',
      '#title' => $this->t('批量操作'),
      '#options' => [
        'enable' => $this->t('启用选中邀请码'),
        'disable' => $this->t('停用选中邀请码'),
      ],
    ];
    $form['invite_apply'] = [
      '#type' => 'submit',
      '#value' => $this->t('应用到选中邀请码'),
      '#name' => 'invite_apply',
    ];

    $form['generate'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('生成新的访问邀请码'),
    ];
    $form['generate']['count'] = [
      '#type' => 'number',
      '#title' => $this->t('数量'),
      '#default_value' => 3,
      '#min' => 1,
      '#max' => 20,
    ];
    $form['generate']['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('备注'),
      '#default_value' => $this->t('手动生成'),
      '#maxlength' => 128,
    ];
    $form['generate']['generate_submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('生成邀请码'),
      '#name' => 'invite_generate',
      '#button_type' => 'primary',
    ];

    $form['hint'] = [
      '#markup' => '<p class="dxcg-admin-hint">'
        . $this->t('邀请链接格式：<code>@url</code>；对方登录并同意协议后自动开通，无需审核。', [
          '@url' => $this->investorAccess->inviteUrl('TPST-XXXX-XXXX'),
        ])
        . '</p>',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $trigger = (string) ($form_state->getTriggeringElement()['#name'] ?? '');

    if ($trigger === 'request_apply') {
      $ids = array_filter((array) $form_state->getValue('requests'), static fn($v) => (int) $v > 0);
      if ($ids === []) {
        $this->messenger()->addWarning($this->t('请先勾选要处理的申请。'));
        return;
      }
      $action = (string) $form_state->getValue('request_action');
      $done = 0;
      foreach ($ids as $id) {
        if ($this->investorAccess->review((int) $id, $action, (int) $this->currentUser()->id())) {
          $done++;
        }
      }
      $this->messenger()->addStatus($this->t('已处理 @count 条申请。', ['@count' => $done]));
      return;
    }

    if ($trigger === 'invite_apply') {
      $ids = array_filter((array) $form_state->getValue('invites'), static fn($v) => (int) $v > 0);
      if ($ids === []) {
        $this->messenger()->addWarning($this->t('请先勾选邀请码。'));
        return;
      }
      $active = (string) $form_state->getValue('invite_action') === 'enable';
      foreach ($ids as $id) {
        $this->investorAccess->setInviteActive((int) $id, $active);
      }
      $this->messenger()->addStatus($this->t('已更新 @count 个邀请码。', ['@count' => count($ids)]));
      return;
    }

    if ($trigger === 'invite_generate') {
      $created = $this->investorAccess->createInvites(
        (int) $form_state->getValue('count'),
        (string) $form_state->getValue('label'),
        (int) $this->currentUser()->id(),
      );
      $this->messenger()->addStatus($this->t('已生成 @count 个邀请码。', ['@count' => count($created)]));
    }
  }

}
