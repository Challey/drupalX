<?php

declare(strict_types=1);

namespace Drupal\dx_copyright_guard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\dx_copyright_guard\Service\AcceptanceStorage;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists agreement acceptance records for audit.
 */
final class AcceptanceListController extends ControllerBase {

  private AcceptanceStorage $acceptance;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->acceptance = $container->get('dx_copyright_guard.acceptance');
    return $instance;
  }

  /**
   * Builds the acceptance audit table.
   */
  public function build(): array {
    $records = $this->acceptance->getRecent(500);
    $uids = array_values(array_unique(array_map(
      static fn($record): int => (int) $record->uid,
      $records,
    )));
    $accounts = $uids
      ? $this->entityTypeManager()->getStorage('user')->loadMultiple($uids)
      : [];

    $rows = [];
    foreach ($records as $record) {
      $account = $accounts[$record->uid] ?? NULL;
      $name = $account ? $account->getDisplayName() : $this->t('（用户已删除）');
      $rows[] = [
        'uid' => (string) $record->uid,
        'name' => $name,
        'version' => $record->agreement_version,
        'accepted_at' => date('Y-m-d H:i:s', (int) $record->accepted_at),
        'ip' => $record->ip,
        'user_agent' => $record->user_agent,
        'path' => $record->path,
      ];
    }

    $build['help'] = [
      '#markup' => '<p>' . $this->t('签署记录按时间倒序显示，最多 500 条。记录为追加写入，用于版权与保密协议的审计追溯。') . '</p>',
    ];
    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        'uid' => $this->t('UID'),
        'name' => $this->t('用户'),
        'version' => $this->t('协议版本'),
        'accepted_at' => $this->t('签署时间'),
        'ip' => $this->t('IP'),
        'user_agent' => $this->t('浏览器标识'),
        'path' => $this->t('访问路径'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('暂无签署记录。'),
    ];
    $build['#cache']['max-age'] = 0;
    return $build;
  }

}
