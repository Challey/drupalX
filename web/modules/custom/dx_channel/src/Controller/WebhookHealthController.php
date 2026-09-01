<?php

declare(strict_types=1);

namespace Drupal\dx_channel\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\dx_channel\Form\WebhookSettingsForm;
use Drupal\dx_channel\Service\WebhookHealth;
use Drupal\dx_channel\Service\WebhookService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Delivery health report for outbound webhooks (roadmap G4).
 *
 * Read-only admin screen: success rate over a window, per-endpoint counters,
 * retry / dead-letter depth and the newest dead letters, so an operator can tell
 * a partner endpoint that is merely slow apart from one that stopped accepting
 * signatures.
 */
final class WebhookHealthController extends ControllerBase {

  public function __construct(
    private readonly WebhookService $webhooks,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('dx_channel.webhook'));
  }

  /**
   * @return array<string, mixed>
   */
  public function report(Request $request): array {
    $window = min(90, max(1, (int) $request->query->get('days', 7)));
    $report = $this->webhooks->healthReport($window);

    $build['summary'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('窗口 @days 天：状态 <strong>@status</strong>，投递尝试 @attempts 次，成功 @sent，失败 @failed，成功率 @rate；重试 @retried 次（成功 @retried_sent），死信队列 @dl 条，限流拦截 @rl 次。', [
        '@days' => $report['window_days'],
        '@status' => (string) WebhookSettingsForm::statusLabel((string) $report['status']),
        '@attempts' => $report['attempts'],
        '@sent' => $report['sent'],
        '@failed' => $report['failed'],
        '@rate' => $report['success_rate'] === NULL ? $this->t('n/a') : $report['success_rate_percent'] . '%',
        '@retried' => $report['retried'],
        '@retried_sent' => $report['retried_sent'],
        '@dl' => $report['dead_letters'],
        '@rl' => $report['rate_limited'],
      ]),
    ];

    $build['lifetime'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#attributes' => ['class' => ['description']],
      '#value' => $this->t('自 @since 起累计：尝试 @attempts，成功 @sent，失败 @failed。站点级 endpoint：@site。', [
        '@since' => $report['last_at'] === '' ? $this->t('（无记录）') : $report['last_at'],
        '@attempts' => $report['lifetime']['attempts'],
        '@sent' => $report['lifetime']['sent'],
        '@failed' => $report['lifetime']['failed'],
        '@site' => empty($report['site_endpoint']) ? $this->t('未配置') : $this->t('已配置'),
      ]),
    ];

    $rows = [];
    foreach ($report['by_endpoint'] as $row) {
      $rows[] = [
        'id' => $row['id'],
        'url' => $row['url'] === '' ? $this->t('（endpoint 已删除）') : $row['url'],
        'enabled' => empty($row['enabled']) ? $this->t('停用') : $this->t('启用'),
        'events' => implode(', ', $row['events']),
        'attempts' => $row['attempts'],
        'sent' => $row['sent'],
        'failed' => $row['failed'],
        'rate' => $row['success_rate'] === NULL ? '—' : round($row['success_rate'] * 100, 1) . '%',
        'last' => $row['last_at'] === '' ? '—' : $row['last_at'],
        'error' => $row['last_error_at'] === '' ? '—' : $row['last_error_at'],
      ];
    }
    $build['endpoints'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Endpoint'),
        $this->t('URL'),
        $this->t('启停'),
        $this->t('事件'),
        $this->t('尝试'),
        $this->t('成功'),
        $this->t('失败'),
        $this->t('成功率'),
        $this->t('最近投递'),
        $this->t('最近失败'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('尚未有投递记录。登记 endpoint 并触发一次 resource.published 后回来查看。'),
    ];

    $daily = [];
    foreach ($report['daily'] as $row) {
      $daily[] = [
        $row['day'],
        $row['attempts'],
        $row['sent'],
        $row['failed'],
        $row['attempts'] === 0 ? '—' : round($row['sent'] / $row['attempts'] * 100, 1) . '%',
      ];
    }
    $build['daily'] = [
      '#type' => 'table',
      '#title' => $this->t('按日明细'),
      '#header' => [
        $this->t('日期'),
        $this->t('尝试'),
        $this->t('成功'),
        $this->t('失败'),
        $this->t('成功率'),
      ],
      '#rows' => $daily,
      '#empty' => $this->t('窗口内没有投递。'),
    ];

    $deadRows = [];
    foreach ($this->webhooks->listDeadLetters(20) as $item) {
      $payload = is_array($item['payload'] ?? NULL) ? $item['payload'] : [];
      $resource = is_array($payload['resource'] ?? NULL) ? $payload['resource'] : [];
      $retries = (int) ($item['retries'] ?? 0);
      $deadRows[] = [
        (string) ($item['endpoint_id'] ?? ''),
        (string) ($payload['event'] ?? ''),
        trim((string) ($resource['type'] ?? '') . ':' . (string) ($resource['external_id'] ?? ''), ':'),
        (string) ($item['failed_at'] ?? ''),
        $retries . ' / ' . WebhookHealth::MAX_ATTEMPTS,
        isset($item['next_retry_at']) ? (string) $item['next_retry_at'] : $this->t('可立即重试'),
      ];
    }
    $build['dead'] = [
      '#type' => 'table',
      '#title' => $this->t('死信队列（最近 20 条）'),
      '#header' => [
        $this->t('Endpoint'),
        $this->t('事件'),
        $this->t('资源'),
        $this->t('失败时间'),
        $this->t('重试'),
        $this->t('下次重试'),
      ],
      '#rows' => $deadRows,
      '#empty' => $this->t('死信队列为空。'),
    ];

    $build['actions'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => Link::fromTextAndUrl(
        $this->t('编辑站点级 endpoint'),
        Url::fromRoute('dx_channel.webhook_settings'),
      )->toString() . ' · ' . Link::fromTextAndUrl(
        $this->t('刷新'),
        Url::fromRoute('dx_channel.webhook_health'),
      )->toString() . ' <span class="description">' . $this->t('命令行等价：<code>drush dx:webhook-health</code> / <code>drush dx:webhook-retry --limit=50</code>；URL 加 <code>?days=30</code> 可拉长窗口。') . '</span>',
    ];

    return $build;
  }

}
