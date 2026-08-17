<?php

declare(strict_types=1);

namespace Drupal\dx_delivery\Service;

use Drupal\Core\Database\Connection;
use Drupal\dx_delivery\Entity\DeliveryBlueprint;

/**
 * Manual work items for turnkey delivery (D4-A: L3 + transparent 待办).
 */
final class TodoService {

  public function __construct(
    protected Connection $database,
  ) {}

  /**
   * Schema definition for hook_schema / ensureTable.
   *
   * @return array<string, mixed>
   */
  public static function tableSchema(): array {
    return [
      'description' => 'Turnkey delivery manual work items (L3 / pending).',
      'fields' => [
        'id' => [
          'type' => 'serial',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'uuid' => [
          'type' => 'varchar',
          'length' => 36,
          'not null' => TRUE,
          'default' => '',
        ],
        'blueprint_id' => [
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
          'default' => 0,
        ],
        'tenant' => [
          'type' => 'varchar',
          'length' => 64,
          'not null' => TRUE,
          'default' => '',
        ],
        'category' => [
          'type' => 'varchar',
          'length' => 32,
          'not null' => TRUE,
          'default' => '',
        ],
        'title' => [
          'type' => 'varchar',
          'length' => 255,
          'not null' => TRUE,
          'default' => '',
        ],
        'detail' => [
          'type' => 'text',
          'size' => 'normal',
          'not null' => FALSE,
        ],
        'status' => [
          'type' => 'varchar',
          'length' => 16,
          'not null' => TRUE,
          'default' => 'open',
        ],
        'quote_hint' => [
          'type' => 'varchar',
          'length' => 64,
          'not null' => TRUE,
          'default' => '',
        ],
        'created' => [
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
          'default' => 0,
        ],
        'changed' => [
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
          'default' => 0,
        ],
      ],
      'primary key' => ['id'],
      'indexes' => [
        'blueprint_status' => ['blueprint_id', 'status'],
        'category' => ['category'],
      ],
    ];
  }

  public function ensureTable(): void {
    $schema = $this->database->schema();
    if (!$schema->tableExists('dx_delivery_todo')) {
      $schema->createTable('dx_delivery_todo', self::tableSchema());
    }
  }

  /**
   * @return list<array<string, mixed>>
   */
  public function list(?int $blueprintId = NULL, ?string $status = NULL): array {
    $this->ensureTable();
    $query = $this->database->select('dx_delivery_todo', 't')
      ->fields('t')
      ->orderBy('id', 'ASC');
    if ($blueprintId !== NULL) {
      $query->condition('blueprint_id', $blueprintId);
    }
    if ($status !== NULL && $status !== '') {
      $query->condition('status', $status);
    }
    $rows = [];
    foreach ($query->execute() ?: [] as $row) {
      $rows[] = $this->normalize((array) $row);
    }
    return $rows;
  }

  /**
   * @return array<string, mixed>|null
   */
  public function load(int $id): ?array {
    $this->ensureTable();
    $row = $this->database->select('dx_delivery_todo', 't')
      ->fields('t')
      ->condition('id', $id)
      ->execute()
      ?->fetchAssoc();
    return is_array($row) ? $this->normalize($row) : NULL;
  }

  /**
   * Create a todo unless an open item of the same blueprint+category exists.
   *
   * @param array<string, mixed> $fields
   *
   * @return array<string, mixed>
   */
  public function ensureOpen(DeliveryBlueprint $blueprint, string $category, array $fields): array {
    $this->ensureTable();
    $existing = $this->database->select('dx_delivery_todo', 't')
      ->fields('t')
      ->condition('blueprint_id', (int) $blueprint->id())
      ->condition('category', $category)
      ->condition('status', 'open')
      ->range(0, 1)
      ->execute()
      ?->fetchAssoc();
    if (is_array($existing)) {
      return $this->normalize($existing);
    }
    $now = time();
    $record = [
      'uuid' => $this->uuid(),
      'blueprint_id' => (int) $blueprint->id(),
      'tenant' => $blueprint->getMachineName(),
      'category' => $category,
      'title' => (string) ($fields['title'] ?? $category),
      'detail' => (string) ($fields['detail'] ?? ''),
      'status' => 'open',
      'quote_hint' => (string) ($fields['quote_hint'] ?? ''),
      'created' => $now,
      'changed' => $now,
    ];
    $id = (int) $this->database->insert('dx_delivery_todo')->fields($record)->execute();
    $record['id'] = $id;
    return $this->normalize($record);
  }

  /**
   * Seed L3 / review / signing todos from a finished orchestration.
   *
   * @param array<string, mixed> $acceptance
   *
   * @return list<array<string, mixed>>
   */
  public function seedFromAcceptance(DeliveryBlueprint $blueprint, array $acceptance): array {
    $seeded = [];
    $level = (string) $blueprint->get('migrate_level')->value;
    $source = (string) $blueprint->get('source_url')->value;

    if ($level === 'l3') {
      $seeded[] = $this->ensureOpen($blueprint, 'l3_integration', [
        'title' => '原业务系统人工/集成（L3）',
        'detail' => '审批流、库表耦合等不进入一键流水线。来源：'
          . ($source !== '' ? $source : '（未填 URL）')
          . '。请走集成项目报价，完成后在待办中标记完成。',
        'quote_hint' => 'integration_project',
      ]);
    }

    $imported = 0;
    foreach ($acceptance['steps'] ?? [] as $step) {
      if (!is_array($step) || ($step['id'] ?? '') !== 'migrate') {
        continue;
      }
      $imported = (int) ($step['imported'] ?? 0);
    }
    if (in_array($level, ['l1', 'l2'], TRUE) && $imported > 0) {
      $seeded[] = $this->ensureOpen($blueprint, 'migrate_review', [
        'title' => '导入内容待人工审核发布',
        'detail' => "已导入 {$imported} 条草稿，请在移植审核队列发布或丢弃。",
        'quote_hint' => '',
      ]);
    }

    $channels = $blueprint->getChannels();
    if (in_array('app', $channels, TRUE)) {
      $seeded[] = $this->ensureOpen($blueprint, 'app_signing', [
        'title' => 'Flutter 壳签名 / 出包（客户或托管 CI）',
        'detail' => 'F5-A：一键交付给出可打开工程与文档；签名与上架不在流水线内。',
        'quote_hint' => 'hosted_ci',
      ]);
    }

    return $seeded;
  }

  public function markDone(int $id): bool {
    $this->ensureTable();
    $updated = $this->database->update('dx_delivery_todo')
      ->fields(['status' => 'done', 'changed' => time()])
      ->condition('id', $id)
      ->execute();
    return (int) $updated > 0;
  }

  /**
   * @return array{open: int, done: int, total: int}
   */
  public function counts(?int $blueprintId = NULL): array {
    $rows = $this->list($blueprintId);
    $open = 0;
    $done = 0;
    foreach ($rows as $row) {
      if (($row['status'] ?? '') === 'done') {
        $done++;
      }
      else {
        $open++;
      }
    }
    return [
      'open' => $open,
      'done' => $done,
      'total' => count($rows),
    ];
  }

  /**
   * @param array<string, mixed> $row
   *
   * @return array<string, mixed>
   */
  protected function normalize(array $row): array {
    return [
      'id' => (int) ($row['id'] ?? 0),
      'uuid' => (string) ($row['uuid'] ?? ''),
      'blueprint_id' => (int) ($row['blueprint_id'] ?? 0),
      'tenant' => (string) ($row['tenant'] ?? ''),
      'category' => (string) ($row['category'] ?? ''),
      'title' => (string) ($row['title'] ?? ''),
      'detail' => (string) ($row['detail'] ?? ''),
      'status' => (string) ($row['status'] ?? 'open'),
      'quote_hint' => (string) ($row['quote_hint'] ?? ''),
      'created' => (int) ($row['created'] ?? 0),
      'changed' => (int) ($row['changed'] ?? 0),
    ];
  }

  protected function uuid(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
  }

}
