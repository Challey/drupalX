<?php

declare(strict_types=1);

namespace Drupal\dx_delivery\Service;

use Drupal\dx_delivery\Entity\DeliveryBlueprint;

/**
 * Manual L3 integration todos attached to a delivery blueprint.
 */
final class HandoffTodoService {

  /**
   * @return list<array{id: string, title: string, status: string, kind: string, notes: string}>
   */
  public function openL3(DeliveryBlueprint $blueprint): array {
    $url = trim((string) $blueprint->get('source_url')->value);
    $todos = [
      [
        'id' => 'l3-integration',
        'title' => 'L3 原业务系统人工/集成（不假装一键）',
        'status' => 'open',
        'kind' => 'l3',
        'notes' => $url !== '' ? $url : '无旧站 URL；需现场对接审批流/库表',
      ],
      [
        'id' => 'l3-acceptance',
        'title' => 'L3 对接完成后回写入验收报告',
        'status' => 'open',
        'kind' => 'l3',
        'notes' => '完成后 drush dx:delivery-todo-done ' . (int) $blueprint->id() . ' l3-acceptance',
      ],
    ];
    $blueprint->appendLog('Opened L3 handoff todos');
    return $todos;
  }

  /**
   * @param list<array<string, mixed>> $todos
   *
   * @return list<array<string, mixed>>
   */
  public function complete(array $todos, string $todoId): array {
    $found = FALSE;
    foreach ($todos as &$todo) {
      if (($todo['id'] ?? '') === $todoId) {
        $todo['status'] = 'done';
        $todo['done_at'] = gmdate('c');
        $found = TRUE;
      }
    }
    unset($todo);
    if (!$found) {
      throw new \InvalidArgumentException('Unknown handoff todo: ' . $todoId);
    }
    return $todos;
  }

  /**
   * Persist todos onto the blueprint acceptance JSON.
   *
   * @param list<array<string, mixed>> $todos
   */
  public function saveOnBlueprint(DeliveryBlueprint $blueprint, array $todos): void {
    $acceptance = json_decode((string) $blueprint->get('acceptance')->value, TRUE);
    if (!is_array($acceptance)) {
      $acceptance = ['spec' => 'DX-ACCEPTANCE', 'steps' => []];
    }
    $acceptance['handoff_todos'] = $todos;
    $blueprint->set('acceptance', json_encode($acceptance, JSON_UNESCAPED_UNICODE));
    $blueprint->save();
  }

  /**
   * @return list<array<string, mixed>>
   */
  public function listFromBlueprint(DeliveryBlueprint $blueprint): array {
    $acceptance = json_decode((string) $blueprint->get('acceptance')->value, TRUE);
    $todos = $acceptance['handoff_todos'] ?? [];
    return is_array($todos) ? array_values($todos) : [];
  }

}
