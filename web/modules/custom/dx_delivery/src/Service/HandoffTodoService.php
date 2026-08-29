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

  /**
   * Split a raw "--batch=a,b" style value into clean, de-duplicated ids.
   *
   * @param mixed $raw
   *   Comma separated string or list of ids.
   *
   * @return list<string>
   */
  public static function parseIdList(mixed $raw): array {
    $values = is_array($raw) ? $raw : explode(',', is_string($raw) ? $raw : '');
    $ids = [];
    foreach ($values as $value) {
      if (is_array($value)) {
        continue;
      }
      $value = trim((string) $value);
      if ($value !== '' && !in_array($value, $ids, TRUE)) {
        $ids[] = $value;
      }
    }
    return $ids;
  }

  /**
   * First todo carrying this id, or NULL.
   *
   * @param list<array<string, mixed>> $todos
   *
   * @return array<string, mixed>|null
   */
  public static function find(array $todos, string $todoId): ?array {
    foreach ($todos as $todo) {
      if (is_array($todo) && (string) ($todo['id'] ?? '') === $todoId) {
        return $todo;
      }
    }
    return NULL;
  }

  /**
   * A todo counts as open until it is explicitly signed off.
   *
   * @param array<string, mixed> $todo
   */
  public static function isOpen(array $todo): bool {
    return strtolower((string) ($todo['status'] ?? 'open')) !== 'done';
  }

  /**
   * Complete many todos in one run without failing on finished ones.
   *
   * Idempotency is the point: re-running the same batch reports the already
   * signed items as "skipped" and keeps their original done_at untouched.
   *
   * @param list<array<string, mixed>> $todos
   * @param list<string> $todoIds
   *
   * @return array{
   *   todos: list<array<string, mixed>>,
   *   completed: list<string>,
   *   skipped: list<string>,
   *   unknown: list<string>,
   * }
   */
  public function completeBatch(array $todos, array $todoIds): array {
    $completed = [];
    $skipped = [];
    $unknown = [];
    foreach (array_values($todoIds) as $todoId) {
      $todo = self::find($todos, (string) $todoId);
      if ($todo === NULL) {
        $unknown[] = (string) $todoId;
        continue;
      }
      if (!self::isOpen($todo)) {
        $skipped[] = (string) $todoId;
        continue;
      }
      $todos = $this->complete($todos, (string) $todoId);
      $completed[] = (string) $todoId;
    }
    return [
      'todos' => array_values($todos),
      'completed' => $completed,
      'skipped' => $skipped,
      'unknown' => $unknown,
    ];
  }

  /**
   * Attach SLA fields (owner / due / remark) to selected todos.
   *
   * Status is never touched here, so SLA can be corrected after sign-off, and
   * the fields live inside the todo array only - no entity schema change.
   *
   * @param list<array<string, mixed>> $todos
   * @param list<string> $todoIds
   *   Target ids; an empty list applies the SLA to every todo.
   * @param array{owner?: string|null, due?: string|null, remark?: string|null} $sla
   *
   * @return array{
   *   todos: list<array<string, mixed>>,
   *   updated: list<string>,
   *   unknown: list<string>,
   *   applied: array<string, string>,
   * }
   */
  public function applySla(array $todos, array $todoIds, array $sla): array {
    $applied = [];
    foreach (['owner', 'due', 'remark'] as $field) {
      $value = $sla[$field] ?? NULL;
      if (!is_string($value)) {
        continue;
      }
      $value = trim($value);
      if ($value === '') {
        continue;
      }
      $applied[$field] = $field === 'due' ? self::normalizeDue($value) : $value;
    }

    $targets = $todoIds === []
      ? array_values(array_filter(array_map(
        static fn (array $todo): string => (string) ($todo['id'] ?? ''),
        $todos,
      )))
      : $todoIds;

    $updated = [];
    $unknown = [];
    foreach ($targets as $todoId) {
      $todoId = (string) $todoId;
      if ($todoId === '') {
        continue;
      }
      if (self::find($todos, $todoId) === NULL) {
        $unknown[] = $todoId;
        continue;
      }
      if ($applied !== []) {
        foreach ($todos as &$todo) {
          if ((string) ($todo['id'] ?? '') === $todoId) {
            foreach ($applied as $field => $value) {
              $todo[$field] = $value;
            }
            $todo['sla_updated_at'] = gmdate('c');
          }
        }
        unset($todo);
      }
      $updated[] = $todoId;
    }

    return [
      'todos' => array_values($todos),
      'updated' => $updated,
      'unknown' => array_values(array_unique($unknown)),
      'applied' => $applied,
    ];
  }

  /**
   * Normalise a due date to Y-m-d, keeping unparseable input verbatim.
   *
   * Parsing is pinned to UTC so a site timezone east of Greenwich cannot
   * silently move a deadline to the previous day.
   */
  public static function normalizeDue(string $raw): string {
    $raw = trim($raw);
    if ($raw === '') {
      return '';
    }
    // Already canonical: return it untouched, no conversion can shift it.
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
      return $raw;
    }
    try {
      $due = new \DateTimeImmutable($raw, new \DateTimeZone('UTC'));
    }
    catch (\Exception) {
      // Never eat operator input silently - keep the raw text and let the
      // overdue calculation ignore it.
      return $raw;
    }
    return $due->format('Y-m-d');
  }

  /**
   * Timestamp a due value stops counting as open, or NULL when unparseable.
   */
  public static function dueDeadline(string $due): ?int {
    $due = trim($due);
    if ($due === '') {
      return NULL;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
      // A bare date means "until the end of that day".
      $ts = strtotime($due . ' 00:00:00 UTC');
      return $ts === FALSE ? NULL : $ts + 86399;
    }
    $ts = strtotime($due);
    return $ts === FALSE ? NULL : $ts;
  }

  /**
   * Open todo whose due deadline has passed.
   *
   * @param array<string, mixed> $todo
   */
  public static function isOverdue(array $todo, ?int $now = NULL): bool {
    if (!self::isOpen($todo)) {
      return FALSE;
    }
    $deadline = self::dueDeadline((string) ($todo['due'] ?? ''));
    return $deadline !== NULL && $deadline < ($now ?? time());
  }

  /**
   * Board counters for a todo list.
   *
   * @param list<array<string, mixed>> $todos
   *
   * @return array{total: int, open: int, done: int, overdue: int, missing_sla: int}
   */
  public static function stats(array $todos, ?int $now = NULL): array {
    $stats = [
      'total' => 0,
      'open' => 0,
      'done' => 0,
      'overdue' => 0,
      'missing_sla' => 0,
    ];
    foreach ($todos as $todo) {
      if (!is_array($todo)) {
        continue;
      }
      $stats['total']++;
      if (self::isOpen($todo)) {
        $stats['open']++;
        if (trim((string) ($todo['owner'] ?? '')) === '' && trim((string) ($todo['due'] ?? '')) === '') {
          $stats['missing_sla']++;
        }
        if (self::isOverdue($todo, $now)) {
          $stats['overdue']++;
        }
      }
      else {
        $stats['done']++;
      }
    }
    return $stats;
  }

  /**
   * Board filter names, normalised.
   *
   * @param mixed $raw
   *   Raw ?status= value.
   */
  public static function normalizeFilter(mixed $raw): string {
    $key = strtolower(trim(is_string($raw) ? $raw : ''));
    return match ($key) {
      'open', 'pending' => 'open',
      'done' => 'done',
      'overdue' => 'overdue',
      default => 'all',
    };
  }

  /**
   * Keep the todos matching a board filter.
   *
   * @param list<array<string, mixed>> $todos
   *   Filter: open | done | overdue | all.
   *
   * @return list<array<string, mixed>>
   */
  public static function filter(array $todos, string $filter, ?int $now = NULL): array {
    $filter = self::normalizeFilter($filter);
    if ($filter === 'all') {
      return array_values($todos);
    }
    $out = [];
    foreach ($todos as $todo) {
      if (!is_array($todo)) {
        continue;
      }
      $open = self::isOpen($todo);
      $keep = match ($filter) {
        'open' => $open,
        'done' => !$open,
        'overdue' => self::isOverdue($todo, $now),
        default => TRUE,
      };
      if ($keep) {
        $out[] = $todo;
      }
    }
    return $out;
  }

}

