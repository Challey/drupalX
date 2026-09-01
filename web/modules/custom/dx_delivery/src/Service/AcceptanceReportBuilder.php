<?php

declare(strict_types=1);

namespace Drupal\dx_delivery\Service;

use Drupal\dx_delivery\BlueprintStatusFilter;

/**
 * Acceptance report v3 (Phase F / F4).
 *
 * Turns the acceptance JSON stored on a blueprint into a single-file, parseable
 * export where the four operator deliverables - ops handbook, API docs,
 * certificate vault, L3 source bundle - are emitted as one block. Every
 * deliverable key is always present; an unresolvable one is reported as
 * "missing" instead of being dropped, so downstream tooling never has to guess
 * whether a key is absent because nothing was delivered or because the exporter
 * was too lazy to say so.
 *
 * Pure array in / array out: assertable without Drupal or a database.
 */
final class AcceptanceReportBuilder {

  /**
   * Report spec, unchanged since v1 for existing consumers.
   */
  public const SPEC = 'DX-ACCEPTANCE';

  /**
   * Report shape version. v3 adds the deliverables block and SLA on todos.
   */
  public const SPEC_VERSION = '3.0';

  /**
   * Spec of the deliverables block.
   */
  public const DELIVERABLE_SPEC = 'DX-DELIVERABLES';

  public const STATUS_OK = 'ok';

  public const STATUS_MISSING = 'missing';

  /**
   * Deliverable key => where it is read from and what it points at.
   *
   * @return array<string, array{label: string, source: string, type: string}>
   */
  public static function definitions(): array {
    return [
      'ops_handbook' => ['label' => '运维手册', 'source' => 'handbook', 'type' => 'doc'],
      'api_docs' => ['label' => 'API 文档', 'source' => 'api_docs', 'type' => 'url'],
      'certs' => ['label' => '证书托管', 'source' => 'certs', 'type' => 'url'],
      'l3_source' => ['label' => 'L3 源码包', 'source' => 'l3_source', 'type' => 'url'],
    ];
  }

  /**
   * Site-relative deliverable paths that have to resolve to a route.
   *
   * Absolute http(s) links are left out: nobody can verify them offline, and a
   * probe would turn a report into an outbound request.
   *
   * @param array<string, mixed> $acceptance
   *
   * @return list<string>
   */
  public static function sitePaths(array $acceptance): array {
    $ops = is_array($acceptance['ops'] ?? NULL) ? $acceptance['ops'] : [];
    $paths = [];
    foreach (['api_docs', 'certs', 'l3_source'] as $key) {
      $value = trim((string) ($ops[$key] ?? ''));
      if ($value !== '' && !str_starts_with($value, 'http')) {
        $paths[] = '/' . ltrim($value, '/');
      }
    }
    return array_values(array_unique($paths));
  }

  /**
   * Ready-made deliverables() context for a blueprint's acceptance JSON.
   *
   * @param array<string, mixed> $acceptance
   * @param array<string, bool> $resolved
   *   Which of self::sitePaths() actually resolve, see deliverables().
   *
   * @return array<string, mixed>
   */
  public static function context(array $acceptance, array $resolved = []): array {
    return [
      'repo_root' => defined('DRUPAL_ROOT') ? dirname(DRUPAL_ROOT) : NULL,
      'portal_url' => (string) ($acceptance['portal_url'] ?? ''),
      'resolved_paths' => $resolved,
    ];
  }

  /**
   * The four deliverable links as one block.
   *
   * @param array<string, mixed> $acceptance
   *   Acceptance JSON from the blueprint.
   * @param array{
   *   portal_url?: string|null,
   *   repo_root?: string|null,
   *   resolved_paths?: array<string, bool>|null,
   * } $context
   *   portal_url prefixes site-relative links, repo_root enables the handbook
   *   file check, resolved_paths marks which site paths actually have a route.
   *
   * @return array{
   *   spec: string,
   *   total: int,
   *   ok: int,
   *   missing: list<string>,
   *   items: array<string, array<string, mixed>>,
   * }
   */
  public static function deliverables(array $acceptance, array $context = []): array {
    $ops = is_array($acceptance['ops'] ?? NULL) ? $acceptance['ops'] : [];
    $portal = trim((string) ($context['portal_url'] ?? ($acceptance['portal_url'] ?? '')));
    $portal = $portal === '/' ? '' : rtrim($portal, '/');
    $repoRoot = isset($context['repo_root']) ? rtrim((string) $context['repo_root'], '/') : '';
    $resolved = is_array($context['resolved_paths'] ?? NULL) ? $context['resolved_paths'] : [];

    $items = [];
    $missing = [];
    foreach (self::definitions() as $key => $definition) {
      $value = trim((string) ($ops[$definition['source']] ?? ''));
      $url = '';
      $path = '';
      $note = '';
      $status = self::STATUS_OK;

      if ($value === '') {
        $status = self::STATUS_MISSING;
        $note = '验收报告未记录该链接（蓝图未执行或缺 ops 步骤）';
      }
      elseif ($definition['type'] === 'doc') {
        $path = $repoRoot !== '' ? $repoRoot . '/' . ltrim($value, '/') : $value;
        if ($repoRoot !== '' && !is_file($path)) {
          $status = self::STATUS_MISSING;
          $note = '手册文件不存在：' . $path;
        }
        else {
          $note = $repoRoot === '' ? '未提供仓库根目录，未校验文件' : '文件就绪';
        }
        $url = str_starts_with($value, 'http') ? $value : '';
      }
      else {
        if (str_starts_with($value, 'http')) {
          $url = $value;
          $path = '';
        }
        else {
          $path = '/' . ltrim($value, '/');
          $url = $portal !== '' ? $portal . $path : $path;
          if ($resolved !== [] && !($resolved[$path] ?? FALSE)) {
            $status = self::STATUS_MISSING;
            $note = '站点未注册该路径的路由（对应模块未启用？）';
          }
        }
        if ($status === self::STATUS_OK) {
          $note = '链接就绪';
        }
      }

      $items[$key] = [
        'key' => $key,
        'label' => $definition['label'],
        'type' => $definition['type'],
        'value' => $value,
        'url' => $url,
        'path' => $path,
        'status' => $status,
        'note' => $note,
      ];
      if ($status === self::STATUS_MISSING) {
        $missing[] = $key;
      }
    }

    return [
      'spec' => self::DELIVERABLE_SPEC,
      'total' => count($items),
      'ok' => count($items) - count($missing),
      'missing' => $missing,
      'items' => $items,
    ];
  }

  /**
   * Todo rows with a fixed key set, SLA fields included.
   *
   * @param list<array<string, mixed>> $todos
   *
   * @return list<array<string, mixed>>
   */
  public static function todoRows(array $todos, ?int $now = NULL): array {
    $rows = [];
    foreach ($todos as $todo) {
      if (!is_array($todo)) {
        continue;
      }
      $rows[] = [
        'id' => (string) ($todo['id'] ?? ''),
        'title' => (string) ($todo['title'] ?? ''),
        'status' => (string) ($todo['status'] ?? 'open'),
        'kind' => (string) ($todo['kind'] ?? ''),
        'owner' => (string) ($todo['owner'] ?? ''),
        'due' => (string) ($todo['due'] ?? ''),
        'remark' => (string) ($todo['remark'] ?? ''),
        'notes' => (string) ($todo['notes'] ?? ''),
        'done_at' => (string) ($todo['done_at'] ?? ''),
        'sla_updated_at' => (string) ($todo['sla_updated_at'] ?? ''),
        'open' => HandoffTodoService::isOpen($todo),
        'overdue' => HandoffTodoService::isOverdue($todo, $now),
      ];
    }
    return $rows;
  }

  /**
   * Full single-file export payload.
   *
   * @param array<string, mixed> $acceptance
   * @param array<string, mixed> $meta
   *   Blueprint facts: blueprint_id, label, status, machine_name, site_type,
   *   channels, capabilities, migrate_level, log.
   * @param array<string, mixed> $context
   *   Passed through to self::deliverables().
   *
   * @return array<string, mixed>
   */
  public static function export(array $acceptance, array $meta, array $context = []): array {
    $todos = is_array($acceptance['handoff_todos'] ?? NULL) ? array_values($acceptance['handoff_todos']) : [];
    $steps = is_array($acceptance['steps'] ?? NULL) ? array_values($acceptance['steps']) : [];
    $failedSteps = [];
    foreach ($steps as $step) {
      if (is_array($step) && empty($step['ok'])) {
        $failedSteps[] = (string) ($step['id'] ?? '');
      }
    }
    $status = (string) ($meta['status'] ?? '');

    return [
      'spec' => self::SPEC,
      'spec_version' => self::SPEC_VERSION,
      'exported_at' => (string) ($context['exported_at'] ?? gmdate('c')),
      'blueprint_id' => (int) ($meta['blueprint_id'] ?? 0),
      'label' => (string) ($meta['label'] ?? ''),
      'status' => $status,
      'status_segment' => BlueprintStatusFilter::segmentOf($status),
      'machine_name' => (string) ($meta['machine_name'] ?? ''),
      'site_type' => (string) ($meta['site_type'] ?? ''),
      'channels' => is_array($meta['channels'] ?? NULL) ? array_values($meta['channels']) : [],
      'capabilities' => is_array($meta['capabilities'] ?? NULL) ? array_values($meta['capabilities']) : [],
      'migrate_level' => (string) ($meta['migrate_level'] ?? ''),
      'portal_url' => (string) ($acceptance['portal_url'] ?? ''),
      'passed' => !empty($acceptance['passed']),
      'steps' => [
        'total' => count($steps),
        'failed' => $failedSteps,
      ],
      'acceptance' => $acceptance === [] ? new \stdClass() : $acceptance,
      'deliverables' => self::deliverables($acceptance, $context),
      'handoff_todos' => [
        'stats' => HandoffTodoService::stats($todos),
        'items' => self::todoRows($todos),
      ],
      'log' => (string) ($meta['log'] ?? ''),
    ];
  }

}
