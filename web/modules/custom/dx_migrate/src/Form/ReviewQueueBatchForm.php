<?php

declare(strict_types=1);

namespace Drupal\dx_migrate\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\dx_migrate\Service\ReviewBatch;
use Drupal\dx_migrate\Service\ReviewBatchRunner;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Batch publish / discard / replay for the migrate review queue (roadmap G2).
 *
 * The form runs on FormBuilder's built-in `form_id` + `form_token` guard, caps
 * one run at {@see \Drupal\dx_migrate\Service\ReviewBatch::MAX_ITEMS} items and
 * always renders the per-item outcome table afterwards — a partially failed run
 * keeps the items it already succeeded on.
 */
final class ReviewQueueBatchForm extends FormBase {

  public function __construct(
    private readonly ReviewBatchRunner $batch,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('dx_migrate.review_batch'),
    );
  }

  public function getFormId(): string {
    return 'dx_migrate_review_batch_form';
  }

  /**
   * @param array<array-key, mixed> $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $report = $form_state->get('report');
    $form['report'] = $this->reportElement(is_array($report) ? $report : NULL);

    $pending = $this->batch->pending(ReviewBatch::MAX_ITEMS);
    $options = [];
    foreach ($pending as $row) {
      $external = $row['external_ids'] === [] ? '' : ' [' . implode(',', $row['external_ids']) . ']';
      $options[(string) $row['nid']] = $this->t('@title（@bundle · nid @nid）@external', [
        '@title' => $row['title'],
        '@bundle' => $row['bundle'],
        '@nid' => $row['nid'],
        '@external' => $external,
      ]);
    }

    $form['overview'] = [
      '#type' => 'item',
      '#title' => $this->t('单次上限'),
      '#markup' => '<p>' . $this->t('每次批量操作最多 @n 项，超出部分会列为「未处理」，可再次提交。已成功的项不会因后续失败而回滚。', ['@n' => ReviewBatch::MAX_ITEMS]) . '</p>'
        . '<p>' . $this->t('当前待审草稿（最多显示 @n 条）：@c', ['@n' => ReviewBatch::MAX_ITEMS, '@c' => count($options)]) . '</p>',
    ];

    $form['action'] = [
      '#type' => 'radios',
      '#title' => $this->t('批量动作'),
      '#options' => [
        ReviewBatch::ACTION_PUBLISH => $this->t('批量发布所选草稿'),
        ReviewBatch::ACTION_DISCARD => $this->t('批量丢弃所选草稿（删除节点并清除外部 ID 映射）'),
        ReviewBatch::ACTION_REPLAY => $this->t('按外部 ID 重放（从 payload 快照重新 Ingest）'),
      ],
      '#default_value' => ReviewBatch::ACTION_PUBLISH,
      '#required' => TRUE,
    ];

    $form['nids'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('选择草稿'),
      '#options' => $options,
      '#access' => $options !== [],
      '#states' => [
        'visible' => [
          [':input[name="action"]' => ['value' => ReviewBatch::ACTION_PUBLISH]],
          [':input[name="action"]' => ['value' => ReviewBatch::ACTION_DISCARD]],
        ],
      ],
    ];
    if ($options === []) {
      $form['nids_empty'] = [
        '#markup' => '<p>' . $this->t('暂无待审草稿。') . '</p>',
      ];
    }

    $form['external_ids'] = [
      '#type' => 'textarea',
      '#title' => $this->t('外部 ID（每行一个，或逗号/空格分隔）'),
      '#description' => $this->t('仅「按外部 ID 重放」使用，例如 l1_3f2a8c9d0e1b2a34。'),
      '#rows' => 5,
      '#states' => [
        'visible' => [
          ':input[name="action"]' => ['value' => ReviewBatch::ACTION_REPLAY],
        ],
      ],
    ];

    $form['confirm'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('我已确认该操作会直接写入内容（发布/删除）'),
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('执行批量操作'),
        '#button_type' => 'danger',
      ],
      'cancel' => [
        '#type' => 'link',
        '#title' => $this->t('返回审核队列'),
        '#url' => Url::fromRoute('dx_migrate.review'),
        '#attributes' => ['class' => ['button']],
      ],
    ];
    return $form;
  }

  /**
   * @param array<array-key, mixed> $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $action = (string) $form_state->getValue('action');
    if (!ReviewBatch::isAction($action)) {
      $form_state->setErrorByName('action', $this->t('未知的批量动作。'));
      return;
    }
    if (!$form_state->hasValue('confirm') || empty($form_state->getValue('confirm'))) {
      $form_state->setErrorByName('confirm', $this->t('请先勾选确认项。'));
    }
    $ids = $this->collectIds($form_state);
    if ($ids === []) {
      $form_state->setErrorByName(
        $action === ReviewBatch::ACTION_REPLAY ? 'external_ids' : 'nids',
        $this->t('请至少选择一项。'),
      );
      return;
    }
    $plan = ReviewBatch::normalize($ids, ReviewBatch::MAX_ITEMS, $action === ReviewBatch::ACTION_REPLAY ? 'external_id' : 'nid');
    foreach ($plan['rejected'] as $rejected) {
      $form_state->setErrorByName(
        $action === ReviewBatch::ACTION_REPLAY ? 'external_ids' : 'nids',
        $this->t('无效标识 @id：@reason', ['@id' => $rejected['input'], '@reason' => $rejected['reason']]),
      );
    }
  }

  /**
   * @param array<array-key, mixed> $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $action = (string) $form_state->getValue('action');
    $report = $this->batch->run($action, $this->collectIds($form_state), ReviewBatch::MAX_ITEMS);
    $form_state->set('report', $report);
    $form_state->setRebuild(TRUE);
    if (!empty($report['ok'])) {
      $this->messenger()->addStatus($this->t('批量@action 完成：@n 项成功。', [
        '@action' => $this->actionLabel($action),
        '@n' => $report['succeeded'],
      ]));
    }
    else {
      $this->messenger()->addWarning($this->t('批量@action 结束：成功 @ok，失败 @bad，未处理 @rest。已成功项不会回滚。', [
        '@action' => $this->actionLabel($action),
        '@ok' => $report['succeeded'],
        '@bad' => $report['failed'],
        '@rest' => count($report['overflow']),
      ]));
    }
    // Clear the selection so a second submit cannot repeat the run by accident.
    $form_state->setValue('nids', []);
    $form_state->setValue('external_ids', '');
    $form_state->setValue('confirm', FALSE);
  }

  /**
   * @return list<string>
   */
  private function collectIds(FormStateInterface $form_state): array {
    $action = (string) ($form_state->getValue('action') ?: '');
    if ($action === ReviewBatch::ACTION_REPLAY) {
      $raw = (string) ($form_state->getValue('external_ids') ?? '');
      $parts = preg_split('/[\s,;]+/', $raw) ?: [];
      return array_values(array_filter(array_map('trim', $parts), static fn(string $v): bool => $v !== ''));
    }
    $nids = $form_state->getValue('nids');
    $out = [];
    foreach (is_array($nids) ? $nids : [] as $nid => $selected) {
      if (!empty($selected)) {
        $out[] = (string) $nid;
      }
    }
    return $out;
  }

  private function actionLabel(string $action): string {
    return match ($action) {
      ReviewBatch::ACTION_DISCARD => $this->t('丢弃'),
      ReviewBatch::ACTION_REPLAY => $this->t('重放'),
      default => $this->t('发布'),
    };
  }

  /**
   * Per-item success / failure table for the previous run.
   *
   * @param array<string, mixed>|NULL $report
   */
  private function reportElement(?array $report): array {
    if (!is_array($report) || empty($report['total'])) {
      return [];
    }
    $rows = [];
    foreach ($report['results'] ?? [] as $row) {
      $rows[] = [
        (string) ($row['key'] ?? ''),
        !empty($row['ok']) ? $this->t('成功') : $this->t('失败'),
        (string) ($row['message'] ?? ''),
      ];
    }
    $elements = [
      '#type' => 'container',
      '#attributes' => ['class' => ['dx-migrate-batch-report']],
      'headline' => [
        '#markup' => '<p><strong>' . $this->t('上次批量@action：共 @t，成功 @ok，失败 @bad，未处理 @rest。', [
          '@action' => $this->actionLabel((string) ($report['action'] ?? '')),
          '@t' => $report['total'],
          '@ok' => $report['succeeded'],
          '@bad' => $report['failed'],
          '@rest' => count($report['overflow'] ?? []),
        ]) . '</strong></p>',
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [$this->t('标识'), $this->t('结果'), $this->t('说明')],
        '#rows' => $rows,
      ],
    ];
    if (!empty($report['overflow'])) {
      $elements['overflow'] = [
        '#markup' => '<p>' . $this->t('超出单次上限未处理：@ids', ['@ids' => implode(', ', array_map('strval', (array) $report['overflow']))]) . '</p>',
      ];
    }
    if (!empty($report['rejected'])) {
      $bits = [];
      foreach ((array) $report['rejected'] as $rejected) {
        $bits[] = ($rejected['input'] ?? '?') . '（' . ($rejected['reason'] ?? '') . '）';
      }
      $elements['rejected'] = [
        '#markup' => '<p>' . $this->t('已拒绝的输入：@bits', ['@bits' => implode(', ', $bits)]) . '</p>',
      ];
    }
    return $elements;
  }

}
