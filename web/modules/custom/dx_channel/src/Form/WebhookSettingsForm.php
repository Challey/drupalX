<?php

declare(strict_types=1);

namespace Drupal\dx_channel\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\dx_channel\Service\WebhookHealth;
use Drupal\dx_channel\Service\WebhookService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Site-level outbound webhook endpoint, secret and on/off switch (roadmap G4).
 *
 * Before this form existed the destination could only be created through
 * `POST /api/dx/v1/webhooks` or `drush dx:webhook-register`, and the smoke
 * fixtures pointed at the `fail.example.com` sink. Saving this form mirrors the
 * configured endpoint into state under the fixed id `wh_site`; leaving the URL
 * empty removes it again, so an unconfigured site keeps delivering exactly as it
 * does today (registered endpoints only, failures dead-lettered, no exception).
 */
final class WebhookSettingsForm extends ConfigFormBase {

  public function __construct(
    protected WebhookService $webhooks,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('dx_channel.webhook'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'dx_channel_webhook_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['dx_channel.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('dx_channel.settings');
    $webhook = is_array($config->get('webhook')) ? $config->get('webhook') : [];
    $site = $this->webhooks->siteEndpoint();

    $form['webhook_intro'] = [
      '#type' => 'item',
      '#markup' => '<p>' . $this->t('配置资源发布后向外推送的 DXEP Webhook 接收端。留空则维持现状：仅使用已登记的 endpoint，失败写入死信队列，不抛出异常。') . '</p>',
    ];

    $form['webhook_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('启用站点级 endpoint'),
      '#default_value' => !empty($webhook['enabled']),
      '#description' => $this->t('关闭时保留已填 URL，但不再向该地址投递。'),
    ];

    $form['webhook_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Endpoint URL'),
      '#default_value' => (string) ($webhook['url'] ?? ''),
      '#description' => $this->t('必须为 https（生产要求）；http 仅用于本机冒烟。清空并保存可移除镜像的 endpoint。'),
    ];

    $form['webhook_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('签名密钥'),
      '#default_value' => '',
      '#description' => $this->t('用于 <code>X-DX-Signature: sha256=…</code> 的 HMAC-SHA256 密钥。留空表示沿用现有密钥；留空且无现有密钥时自动生成。'),
      '#attributes' => ['autocomplete' => 'off', 'placeholder' => $this->t('留空即沿用现有密钥')],
    ];

    $form['webhook_secret_info'] = [
      '#type' => 'item',
      '#title' => $this->t('当前密钥'),
      '#markup' => $site !== NULL && !empty($site['secret'])
        ? '<code>' . $this->t('已设置（末 4 位：@tail）', ['@tail' => substr((string) $site['secret'], -4)]) . '</code>'
        : '<em>' . $this->t('尚未设置') . '</em>',
    ];

    $form['webhook_events'] = [
      '#type' => 'textfield',
      '#title' => $this->t('订阅事件（逗号分隔）'),
      '#default_value' => implode(',', array_map('strval', is_array($webhook['events'] ?? NULL) ? $webhook['events'] : ['resource.published'])) ?: 'resource.published',
      '#description' => $this->t('例如 <code>resource.published</code>；<code>*</code> 表示全部事件。'),
    ];

    $form['test_dispatch'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('保存后立即发送一条测试事件'),
      '#default_value' => FALSE,
      '#description' => $this->t('测试结果会计入健康报表；失败只会进入死信队列，不影响内容保存。'),
    ];

    $report = $this->webhooks->healthReport();
    $form['health'] = [
      '#type' => 'details',
      '#title' => $this->t('近 @days 天投递健康', ['@days' => $report['window_days']]),
      '#open' => TRUE,
    ];
    $form['health']['summary'] = [
      '#type' => 'item',
      '#title' => $this->t('状态'),
      '#markup' => '<strong>' . (string) static::statusLabel((string) $report['status']) . '</strong> · '
        . $this->t('尝试 @attempts，成功 @sent，失败 @failed，死信 @dl', [
          '@attempts' => $report['attempts'],
          '@sent' => $report['sent'],
          '@failed' => $report['failed'],
          '@dl' => $report['dead_letters'],
        ])
        . ($report['success_rate'] === NULL ? '' : ' · ' . $this->t('成功率 @rate%', ['@rate' => $report['success_rate_percent']])),
    ];
    $form['health']['more'] = [
      '#type' => 'item',
      '#markup' => '<p>' . Link::fromTextAndUrl(
        $this->t('查看完整健康报表'),
        Url::fromRoute('dx_channel.webhook_health'),
      )->toString() . '</p>',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $url = trim((string) $form_state->getValue('webhook_url'));
    if ($url !== '' && !preg_match('#^https?://#i', $url)) {
      $form_state->setErrorByName('webhook_url', $this->t('Endpoint 必须是 http(s) 绝对地址。'));
    }
    if ($url === '' && $form_state->getValue('webhook_enabled')) {
      $form_state->setErrorByName('webhook_url', $this->t('启用投递前必须填写 Endpoint URL。'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('dx_channel.settings');
    $current = is_array($config->get('webhook')) ? $config->get('webhook') : [];
    $url = trim((string) $form_state->getValue('webhook_url'));
    $events = array_values(array_filter(array_map('trim', explode(',', (string) $form_state->getValue('webhook_events')))));
    $given = trim((string) $form_state->getValue('webhook_secret'));
    $site = $this->webhooks->siteEndpoint();
    $secret = $given !== '' ? $given : (string) ($current['secret'] ?? $site['secret'] ?? '');

    $config->set('webhook', [
      'enabled' => (bool) $form_state->getValue('webhook_enabled'),
      'url' => $url,
      'secret' => $secret,
      'events' => $events ?: ['resource.published'],
    ])->save();

    // Mirror into state so dispatch() sees one authoritative endpoint row.
    $this->webhooks->syncSiteEndpoint(
      $url,
      $secret,
      $events ?: ['resource.published'],
      (bool) $form_state->getValue('webhook_enabled'),
    );

    if ($url === '') {
      $this->messenger()->addStatus($this->t('已清空站点级 Webhook endpoint，投递回到仅使用已登记 endpoint 的行为。'));
      parent::submitForm($form, $form_state);
      return;
    }

    if ($given !== '') {
      $this->messenger()->addWarning($this->t('签名密钥已更新，请同步通知对端轮换。'));
    }

    if ($form_state->getValue('test_dispatch')) {
      $result = $this->webhooks->dispatch('resource.published', [
        'type' => 'article',
        'external_id' => 'wh_ui_test',
        'title' => 'Webhook UI test',
      ]);
      $this->messenger()->addStatus($this->t('测试投递完成：成功 @sent，失败 @failed（失败项已入死信队列，可在报表中重试）。', [
        '@sent' => $result['sent'],
        '@failed' => $result['failed'],
      ]));
    }
    else {
      $this->messenger()->addStatus($this->t('站点级 Webhook endpoint 已保存。'));
    }

    parent::submitForm($form, $form_state);
  }

  /**
   * Human-readable grade label (shared with the report controller).
   */
  public static function statusLabel(string $status): TranslatableMarkup {
    return match ($status) {
      WebhookHealth::STATUS_HEALTHY => new TranslatableMarkup('健康'),
      WebhookHealth::STATUS_DEGRADED => new TranslatableMarkup('降级'),
      WebhookHealth::STATUS_FAILING => new TranslatableMarkup('持续失败'),
      WebhookHealth::STATUS_UNCONFIGURED => new TranslatableMarkup('未配置'),
      default => new TranslatableMarkup('暂无投递数据'),
    };
  }

}
