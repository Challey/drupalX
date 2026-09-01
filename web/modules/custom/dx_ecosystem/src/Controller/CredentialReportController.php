<?php

declare(strict_types=1);

namespace Drupal\dx_ecosystem\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\dx_ecosystem\Service\CredentialAuditLog;
use Drupal\dx_ecosystem\Service\CredentialLifecycle;
use Drupal\dx_ecosystem\Service\CredentialReport;
use Drupal\dx_ecosystem\Service\DeveloperCertificationStore;
use Drupal\dx_ecosystem\Service\L2ComposerRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Backend report: who got an L2 credential, who issued it, how it is used (I3).
 *
 * Read-only by design — issuing, rotating and revoking stay on the existing
 * screens so there is exactly one place that mutates a credential.
 */
final class CredentialReportController extends ControllerBase {

  public function __construct(
    protected CredentialReport $report,
    protected L2ComposerRepository $repository,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('dx_ecosystem.credential_report'),
      $container->get('dx_ecosystem.l2_repository'),
      $container->get('date.formatter'),
    );
  }

  /**
   * GET /admin/dx/ecosystem/credentials
   */
  public function report(Request $request): array {
    $window = (int) $request->query->get('window', CredentialReport::DEFAULT_WINDOW);
    if ($window === 0 && $request->query->get('window') !== NULL) {
      $window = CredentialReport::WINDOW_ALL;
    }
    $uidFilter = $request->query->get('uid');
    $uid = $uidFilter !== NULL && ctype_digit((string) $uidFilter) ? (int) $uidFilter : NULL;
    $data = $this->report->build($window, $uid);

    $build = [];
    $plan = $this->repository->plan();
    $build['plan'] = [
      '#markup' => '<p><em>' . $this->t('L2 仓库：@driver · @base · 元数据 @served', [
        '@driver' => (string) $plan['driver'],
        '@base' => (string) $plan['base_url'],
        '@served' => (string) $plan['served_root_url'],
      ]) . '</em></p>',
    ];
    if (!$data['audit_ready']) {
      $build['warning'] = [
        '#markup' => '<div class="messages messages--warning">' . $this->t('审计表 @table 尚未建立：当前只显示签发状态与计数，来源 IP 与调用明细会在执行 drush updatedb 后出现。', [
          '@table' => CredentialAuditLog::TABLE,
        ]) . '</div>',
      ];
    }
    foreach (array_keys((array) ($plan['warnings'] ?? [])) as $code) {
      $build['plan_warning_' . preg_replace('/\W/', '_', (string) $code)] = [
        '#markup' => '<p><small>' . $this->t('仓库提示 @code：@message', [
          '@code' => $code,
          '@message' => (string) ($plan['warnings'][$code] ?? ''),
        ]) . '</small></p>',
      ];
    }

    $windowLabel = $window > 0
      ? (string) $this->t('@seconds 秒', ['@seconds' => $window])
      : (string) $this->t('全部');
    $build['summary'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('汇总（窗口 @window）', ['@window' => $windowLabel]),
      '#items' => [
        $this->t('凭证记录：@n', ['@n' => $data['totals']['credentials']]),
        $this->t('有效：@n', ['@n' => $data['totals'][CredentialLifecycle::STATE_ACTIVE] ?? 0]),
        $this->t('暂停（认证/DPA 不满足）：@n', ['@n' => $data['totals'][CredentialLifecycle::STATE_SUSPENDED] ?? 0]),
        $this->t('已吊销：@n', ['@n' => $data['totals'][CredentialLifecycle::STATE_REVOKED] ?? 0]),
        $this->t('累计调用：@n', ['@n' => $data['totals']['uses']]),
        $this->t('轮换次数：@n', ['@n' => $data['totals']['rotations']]),
        $this->t('拒绝次数：@n', ['@n' => $data['totals']['denials']]),
        $this->t('由他人代发：@n', ['@n' => $data['totals']['issued_by_others']]),
      ],
    ];

    $rows = [];
    foreach ($data['rows'] as $row) {
      $rows[] = [
        'data' => [
          (string) $row['uid'],
          (string) $row['state'],
          (string) $row['prefix'],
          (string) $row['issued_by_name'],
          (string) ($row['issued_ip'] !== '' ? $row['issued_ip'] : '—'),
          $row['created'] > 0 ? $this->dateFormatter->format((int) $row['created'], 'short') : '—',
          (string) $row['rotations'],
          (string) $row['uses'],
          $row['last_used'] > 0 ? $this->dateFormatter->format((int) $row['last_used'], 'short') : $this->t('从未使用'),
          $row['idle_days'] === NULL ? '—' : (string) $row['idle_days'],
          (string) $row['denials'],
          (string) $row['distinct_ips'],
          $row['revoked_at'] > 0 ? $this->dateFormatter->format((int) $row['revoked_at'], 'short') : '—',
          (string) ($row['revoke_reason'] !== '' ? $row['revoke_reason'] : ($row['cert_status'] === DeveloperCertificationStore::STATUS_REVOKED ? 'certification_revoked' : '')),
        ],
      ];
    }
    $headers = [];
    foreach (CredentialReport::columns() as $column) {
      $headers[] = (string) $this->t(CredentialReport::headerLabel($column));
    }
    $build['table'] = [
      '#type' => 'table',
      '#header' => $headers,
      '#rows' => $rows,
      '#empty' => $this->t('还没有 L2 凭证。认证开发者后在 /dx/ecosystem/credentials 签发。'),
    ];

    $events = [];
    foreach ($data['events'] as $event) {
      $events[] = sprintf(
        '%s · uid %d · %s · %s · %s · %s',
        $this->dateFormatter->format((int) $event['created'], 'short'),
        (int) $event['uid'],
        (string) $event['event'],
        (string) ($event['code'] ?: '—'),
        (string) ($event['ip'] ?: '—'),
        (string) ($event['path'] ?: '—'),
      );
    }
    $build['events'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('最近事件'),
      '#items' => $events,
      '#empty' => $this->t('窗口内没有事件。'),
    ];
    $build['#cache'] = [
      'contexts' => ['url.query_args', 'user'],
      'max-age' => 0,
    ];
    return $build;
  }

}
