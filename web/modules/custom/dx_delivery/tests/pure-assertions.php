<?php

/**
 * Pure assertions for the dx_delivery turnkey desk (roadmap Phase F, lane L1).
 *
 * Everything proven here needs no database and no bootstrapped site: the
 * blueprint status partition (F1), the L3 todo board markup and filters (F2),
 * batch sign-off with SLA fields (F3) and the acceptance report v3 shape (F4).
 *
 * Usage:
 *   php web/modules/custom/dx_delivery/tests/pure-assertions.php
 *
 * Exit code 0 means every assertion passed. The delivery smoke scripts call this
 * first, so a logic regression fails before any live data is touched.
 */
declare(strict_types=1);

// The site runs east of Greenwich; SLA maths must not depend on that.
date_default_timezone_set('Asia/Shanghai');

$module = dirname(__DIR__);
$root = dirname($module, 4);

require_once $module . '/src/BlueprintStatusFilter.php';
require_once $module . '/src/Service/HandoffTodoService.php';
require_once $module . '/src/Service/AcceptanceReportBuilder.php';

use Drupal\dx_delivery\BlueprintStatusFilter;
use Drupal\dx_delivery\Service\AcceptanceReportBuilder;
use Drupal\dx_delivery\Service\HandoffTodoService;

$failures = 0;
$checks = 0;

function ok(string $label, bool $pass, string $detail = ''): void {
  global $failures, $checks;
  $checks++;
  if (!$pass) {
    $failures++;
    printf("FAIL  %s%s\n", $label, $detail !== '' ? ' — ' . $detail : '');
    return;
  }
  printf("ok    %s\n", $label);
}

function same(string $label, mixed $expected, mixed $actual): void {
  ok($label, $expected === $actual, 'expected ' . var_export($expected, TRUE) . ' got ' . var_export($actual, TRUE));
}

function has(string $label, string $needle, string $haystack): void {
  ok($label, str_contains($haystack, $needle), 'missing ' . $needle);
}

function hasnt(string $label, string $needle, string $haystack): void {
  ok($label, !str_contains($haystack, $needle), 'unexpected ' . $needle);
}

echo "== F1 blueprint status partition ==\n";
same('four segments in order', ['draft', 'confirmed', 'executed', 'failed'], BlueprintStatusFilter::segments());
same('draft segment', ['draft'], BlueprintStatusFilter::statuses('draft'));
same('executed segment covers running+completed', ['running', 'completed'], BlueprintStatusFilter::statuses('executed'));
same('failed segment', ['failed'], BlueprintStatusFilter::statuses('failed'));
same('all segment adds no condition', [], BlueprintStatusFilter::statuses('all'));
same('unknown value falls back to all', 'all', BlueprintStatusFilter::normalize('nonsense'));
same('empty value falls back to all', 'all', BlueprintStatusFilter::normalize(''));
same('array value falls back to all', 'all', BlueprintStatusFilter::normalize(['draft']));
same('legacy completed collapses to executed', 'executed', BlueprintStatusFilter::normalize('completed'));
same('case/space tolerant', 'draft', BlueprintStatusFilter::normalize('  DRAFT '));
ok('matches running under executed', BlueprintStatusFilter::matches('executed', 'running'));
ok('running not under confirmed', !BlueprintStatusFilter::matches('confirmed', 'running'));
ok('all matches every status', BlueprintStatusFilter::matches('all', 'whatever'));
same('segmentOf completed', 'executed', BlueprintStatusFilter::segmentOf('completed'));
same('segmentOf unknown', 'all', BlueprintStatusFilter::segmentOf('archived'));
same('rollup sums statuses',
  ['all' => 9, 'draft' => 2, 'confirmed' => 1, 'executed' => 5, 'failed' => 1],
  BlueprintStatusFilter::rollup(['draft' => 2, 'confirmed' => 1, 'running' => 2, 'completed' => 3, 'failed' => 1]),
);

echo "\n== F2 board filters ==\n";
$todos = [
  ['id' => 'l3-integration', 'title' => 'A', 'status' => 'open', 'kind' => 'l3', 'notes' => 'u'],
  ['id' => 'l3-acceptance', 'title' => 'B', 'status' => 'done', 'done_at' => '2026-08-01T00:00:00+00:00'],
  ['id' => 'l3-data', 'title' => 'C', 'status' => 'open', 'due' => '2000-01-01'],
  ['id' => 'l3-tomorrow', 'title' => 'D', 'status' => 'open', 'due' => '2999-01-01'],
];
same('filter normaliser pending->open', 'open', HandoffTodoService::normalizeFilter('pending'));
same('filter normaliser junk->all', 'all', HandoffTodoService::normalizeFilter('zzz'));
same('open filter keeps open only', ['l3-integration', 'l3-data', 'l3-tomorrow'],
  array_column(HandoffTodoService::filter($todos, 'open'), 'id'));
same('done filter keeps done only', ['l3-acceptance'],
  array_column(HandoffTodoService::filter($todos, 'done'), 'id'));
same('overdue filter keeps past due only', ['l3-data'],
  array_column(HandoffTodoService::filter($todos, 'overdue'), 'id'));
same('all filter keeps everything', 4, count(HandoffTodoService::filter($todos, 'all')));
same('stats', ['total' => 4, 'open' => 3, 'done' => 1, 'overdue' => 1, 'missing_sla' => 1],
  HandoffTodoService::stats($todos));
ok('due date counts until the end of its day', HandoffTodoService::isOverdue(['status' => 'open', 'due' => '2000-01-01'], 946771200));
ok('last second of the due day is not overdue', !HandoffTodoService::isOverdue(['status' => 'open', 'due' => '2000-01-01'], 946771199));
ok('mid-day of a future due date is not overdue', !HandoffTodoService::isOverdue(['status' => 'open', 'due' => '2999-01-01']));
ok('due counts with explicit time', HandoffTodoService::isOverdue(['status' => 'open', 'due' => '2000-01-01 00:00:00 UTC']));
ok('signed todo never overdue', !HandoffTodoService::isOverdue(['status' => 'done', 'due' => '2000-01-01']));
ok('junk due does not blow up', !HandoffTodoService::isOverdue(['status' => 'open', 'due' => 'soon-ish']));
same('find missing id', NULL, HandoffTodoService::find($todos, 'nope'));
same('find returns todo', 'A', HandoffTodoService::find($todos, 'l3-integration')['title']);

echo "\n== F3 batch + SLA ==\n";
$svc = new HandoffTodoService();
same('parseIdList trims/dedupes/keeps order', ['a', 'b', 'c'], HandoffTodoService::parseIdList(' a , b ,,b ,c'));
same('parseIdList on empty string', [], HandoffTodoService::parseIdList(''));
same('parseIdList on array input', ['a', 'b'], HandoffTodoService::parseIdList(['a', ' b ', 'a']));
same('parseIdList ignores nested arrays', ['a'], HandoffTodoService::parseIdList(['a', ['b']]));

$run = $svc->completeBatch($todos, ['l3-integration', 'l3-acceptance', 'ghost']);
same('batch completes open todo', ['l3-integration'], $run['completed']);
same('batch skips signed todo', ['l3-acceptance'], $run['skipped']);
same('batch reports unknown', ['ghost'], $run['unknown']);
ok('completed todo carries done_at', !empty($run['todos'][0]['done_at']));
$firstDoneAt = $run['todos'][1]['done_at'];

$again = $svc->completeBatch($run['todos'], ['l3-integration', 'l3-acceptance', 'ghost']);
same('re-run completes nothing', [], $again['completed']);
same('re-run skips both', ['l3-integration', 'l3-acceptance'], $again['skipped']);
same('re-run is stable on todos', $run['todos'], $again['todos']);
ok('re-run keeps original done_at', $firstDoneAt === $again['todos'][1]['done_at']);

$sla = $svc->applySla($todos, ['l3-integration'], ['owner' => '   ops  ', 'due' => '2026-09-15', 'note' => NULL, 'remark' => '现场联调']);
same('sla normalises due', '2026-09-15', $sla['todos'][0]['due']);
same('canonical due survives a +08:00 site timezone', '2026-09-15', HandoffTodoService::normalizeDue('2026-09-15'));
same('relative due is read as UTC', '1999-12-31', HandoffTodoService::normalizeDue('1999-12-31 23:00'));
same('junk due kept verbatim', 'soon-ish', HandoffTodoService::normalizeDue('soon-ish'));
same('empty due stays empty', '', HandoffTodoService::normalizeDue('   '));
same('unparseable due has no deadline', NULL, HandoffTodoService::dueDeadline('soon-ish'));
same('bare due deadline is end of day', 946771199, HandoffTodoService::dueDeadline('2000-01-01'));
same('sla trims owner', 'ops', $sla['todos'][0]['owner']);
same('sla writes remark', '现场联调', $sla['todos'][0]['remark']);
same('sla reports applied fields', ['owner' => 'ops', 'due' => '2026-09-15', 'remark' => '现场联调'], $sla['applied']);
same('sla leaves other todos alone', '', $sla['todos'][2]['owner'] ?? '');
ok('sla stamps update time', !empty($sla['todos'][0]['sla_updated_at']));
$all = $svc->applySla($todos, [], ['owner' => 'team']);
same('empty id list targets every todo', ['l3-integration', 'l3-acceptance', 'l3-data', 'l3-tomorrow'], $all['updated']);
$ghost = $svc->applySla($todos, ['ghost'], ['owner' => 'team']);
same('unknown ids reported', ['ghost'], $ghost['unknown']);
same('unknown ids change nothing', $todos, $ghost['todos']);
ok('applySla does not touch status', $ghost['todos'][0]['status'] === 'open');

$legacy = $svc->complete($todos, 'l3-integration');
same('legacy complete() still flips status', 'done', $legacy[0]['status']);
try {
  $svc->complete($todos, 'ghost');
  ok('legacy complete() still throws on unknown', FALSE);
}
catch (InvalidArgumentException $e) {
  same('legacy complete() still throws on unknown', 'Unknown handoff todo: ghost', $e->getMessage());
}

echo "\n== F4 acceptance report v3 ==\n";
$acceptance = [
  'spec' => 'DX-ACCEPTANCE',
  'blueprint_id' => 7,
  'tenant' => 'govdemo',
  'passed' => TRUE,
  'portal_url' => 'https://govdemo.drupalx.local',
  'steps' => [
    ['id' => 'provision', 'ok' => TRUE, 'message' => 'ok'],
    ['id' => 'pack', 'ok' => FALSE, 'message' => 'pack failed'],
  ],
  'ops' => [
    'handbook' => 'docs/delivery.md',
    'api_docs' => '/dx/api/docs',
    'certs' => '/admin/dx/certs',
    'l3_source' => '/appstore/licenses',
  ],
  'handoff_todos' => $sla['todos'],
];
$block = AcceptanceReportBuilder::deliverables($acceptance, ['repo_root' => $root]);
same('deliverables block spec', 'DX-DELIVERABLES', $block['spec']);
same('deliverables always four', 4, $block['total']);
same('deliverables keys in order', ['ops_handbook', 'api_docs', 'certs', 'l3_source'], array_keys($block['items']));
same('handbook resolves against repo root', $root . '/docs/delivery.md', $block['items']['ops_handbook']['path']);
same('handbook ok when file exists', 'ok', $block['items']['ops_handbook']['status']);
same('api docs prefixed with portal', 'https://govdemo.drupalx.local/dx/api/docs', $block['items']['api_docs']['url']);
same('nothing missing', [], $block['missing']);
same('ok counter', 4, $block['ok']);

same('site paths collected for route probes', ['/dx/api/docs', '/admin/dx/certs', '/appstore/licenses'],
  AcceptanceReportBuilder::sitePaths($acceptance));
same('absolute links are never probed', [],
  AcceptanceReportBuilder::sitePaths(['ops' => ['api_docs' => 'https://docs.example/api', 'certs' => 'http://example/certs']]));
same('site paths deduped', ['/dx/api/docs'],
  AcceptanceReportBuilder::sitePaths(['ops' => ['api_docs' => 'dx/api/docs', 'certs' => '/dx/api/docs']]));
same('no ops means no site paths', [], AcceptanceReportBuilder::sitePaths([]));
$context = AcceptanceReportBuilder::context($acceptance, ['/dx/api/docs' => TRUE]);
same('context carries portal url', 'https://govdemo.drupalx.local', $context['portal_url']);
same('context forwards resolved routes', ['/dx/api/docs' => TRUE], $context['resolved_paths']);
ok('context flags repo root probe as unavailable without DRUPAL_ROOT', !isset($context['repo_root']));
same('context() feeds deliverables the same link', $block['items']['api_docs']['url'],
  AcceptanceReportBuilder::deliverables($acceptance, AcceptanceReportBuilder::context($acceptance))['items']['api_docs']['url']);

$empty = AcceptanceReportBuilder::deliverables([]);
same('empty acceptance still emits four keys', ['ops_handbook', 'api_docs', 'certs', 'l3_source'], array_keys($empty['items']));
same('all four marked missing', ['ops_handbook', 'api_docs', 'certs', 'l3_source'], $empty['missing']);
same('missing counter', 0, $empty['ok']);
ok('every item keeps a status key', count(array_filter($empty['items'], static fn(array $i): bool => ($i['status'] ?? '') === 'missing')) === 4);
ok('every item keeps url/path/note keys', count(array_filter(
  $empty['items'],
  static fn(array $i): bool => array_key_exists('url', $i) && array_key_exists('path', $i) && array_key_exists('note', $i),
)) === 4);

$broken = AcceptanceReportBuilder::deliverables(
  ['ops' => ['handbook' => 'docs/nope.md', 'api_docs' => '/dx/api/docs', 'certs' => '/admin/dx/certs', 'l3_source' => '/appstore/licenses']],
  ['repo_root' => $root, 'resolved_paths' => ['/dx/api/docs' => TRUE, '/admin/dx/certs' => FALSE, '/appstore/licenses' => TRUE]],
);
same('missing handbook file flagged', 'missing', $broken['items']['ops_handbook']['status']);
same('unrouted cert page flagged', 'missing', $broken['items']['certs']['status']);
same('routed api docs still ok', 'ok', $broken['items']['api_docs']['status']);
same('missing list is ordered by key', ['ops_handbook', 'certs'], $broken['missing']);

$payload = AcceptanceReportBuilder::export($acceptance, [
  'blueprint_id' => 7,
  'label' => '政务门户',
  'status' => 'completed',
  'machine_name' => 'govdemo',
  'site_type' => 'government',
  'channels' => ['web', 'app'],
  'capabilities' => ['opinion'],
  'migrate_level' => 'l3',
  'log' => 'line one',
], ['repo_root' => $root, 'exported_at' => '2026-08-30T00:00:00+00:00']);

foreach (['spec', 'spec_version', 'exported_at', 'blueprint_id', 'label', 'status', 'status_segment', 'machine_name',
  'site_type', 'channels', 'capabilities', 'migrate_level', 'portal_url', 'passed', 'steps', 'acceptance',
  'deliverables', 'handoff_todos', 'log'] as $key) {
  ok("export keeps key '$key'", array_key_exists($key, $payload));
}
same('export spec', 'DX-ACCEPTANCE', $payload['spec']);
same('export version', '3.0', $payload['spec_version']);
same('completed maps to executed segment', 'executed', $payload['status_segment']);
same('failed steps listed', ['pack'], $payload['steps']['failed']);
same('steps total', 2, $payload['steps']['total']);
same('todo rows carry sla keys',
  ['id', 'title', 'status', 'kind', 'owner', 'due', 'remark', 'notes', 'done_at', 'sla_updated_at', 'open', 'overdue'],
  array_keys($payload['handoff_todos']['items'][0]));
$roundTrip = json_decode((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), TRUE);
ok('export payload is parseable JSON', is_array($roundTrip));
same('four deliverable links survive the round trip', ['ops_handbook', 'api_docs', 'certs', 'l3_source'], array_keys($roundTrip['deliverables']['items']));

$bare = AcceptanceReportBuilder::export([], ['blueprint_id' => 0]);
ok('export on empty acceptance still parses', is_array(json_decode((string) json_encode($bare), TRUE)));
ok('empty acceptance exported as JSON object', $bare['acceptance'] instanceof stdClass);
same('empty export marks four missing', ['ops_handbook', 'api_docs', 'certs', 'l3_source'], $bare['deliverables']['missing']);
same('empty export has no todo rows', 0, $bare['handoff_todos']['stats']['total']);

echo "\n== F1/F2 template markup ==\n";
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
  echo "skip  twig render harness — vendor/autoload.php missing\n";
}
else {
  require_once $autoload;
  $templates = $module . '/templates';
  $loader = new \Twig\Loader\ArrayLoader([
    'tabs' => (string) file_get_contents($templates . '/dx-delivery-status-tabs.html.twig'),
    'board' => (string) file_get_contents($templates . '/dx-delivery-todos.html.twig'),
  ]);
  $twig = new \Twig\Environment($loader, ['autoescape' => 'html']);
  // Drupal's translation filter, stubbed: this harness proves markup hooks, not
  // translations.
  $twig->addFilter(new \Twig\TwigFilter('t', static fn (string $text): string => $text));

  $tabs = [
    ['key' => 'all', 'label' => '全部', 'url' => '/admin/dx/delivery?status=all', 'active' => FALSE, 'count' => 9],
    ['key' => 'draft', 'label' => '草稿', 'url' => '/admin/dx/delivery?status=draft', 'active' => TRUE, 'count' => 2],
    ['key' => 'confirmed', 'label' => '已确认', 'url' => '/admin/dx/delivery?status=confirmed', 'active' => FALSE, 'count' => 1],
    ['key' => 'executed', 'label' => '已执行', 'url' => '/admin/dx/delivery?status=executed', 'active' => FALSE, 'count' => 5],
    ['key' => 'failed', 'label' => '失败重试', 'url' => '/admin/dx/delivery?status=failed', 'active' => FALSE, 'count' => 1],
  ];
  $html = $twig->render('tabs', ['tabs' => $tabs, 'label' => '蓝图状态分区']);
  has('nav landmark rendered', '<nav class="dx-deliver-tabs"', $html);
  has('accessible label', 'aria-label="蓝图状态分区"', $html);
  foreach (['draft', 'confirmed', 'executed', 'failed'] as $key) {
    has("tab href for {$key}", '/admin/dx/delivery?status=' . $key, $html);
  }
  has('active tab marked', 'dx-deliver-tabs__item is-active', $html);
  has('active tab announces current page', 'aria-current="page"', $html);
  same('exactly one current tab', 1, substr_count($html, 'aria-current="page"'));
  has('count badge', '<span class="dx-deliver-tabs__count">5</span>', $html);
  has('chinese label present', '失败重试', $html);
  $zero = $twig->render('tabs', ['tabs' => [['key' => 'failed', 'label' => '失败重试', 'url' => '/x', 'active' => FALSE, 'count' => 0]]]);
  hasnt('zero count badge suppressed', 'dx-deliver-tabs__count', $zero);
  hasnt('empty tab list renders nothing', '<nav', $twig->render('tabs', ['tabs' => [], 'label' => '']));

  $board = [
    'title' => 'L3 工单看板',
    'intro' => '人工对接项按蓝图归集',
    'notice' => '',
    'filter' => 'open',
    'tabs' => [
      ['key' => 'open', 'label' => '待办', 'url' => '/deliver/todos?status=open', 'active' => TRUE],
      ['key' => 'done', 'label' => '已签核', 'url' => '/deliver/todos?status=done', 'active' => FALSE],
    ],
    'blueprint_tabs' => [['id' => 7, 'label' => '政务门户', 'url' => '/deliver/todos?blueprint=7', 'active' => TRUE, 'open' => 2]],
    'blueprint_filter' => 7,
    'clear_url' => '/deliver/todos',
    'rows' => [
      [
        'blueprint_id' => 7, 'blueprint_label' => '政务门户', 'blueprint_status' => 'confirmed',
        'blueprint_url' => '/deliver/blueprint/7', 'id' => 'l3-integration', 'title' => 'L3 原业务系统人工/集成',
        'status' => 'open', 'owner' => 'ops', 'due' => '2000-01-01', 'remark' => '现场联调', 'notes' => 'https://old.example',
        'done_at' => '', 'overdue' => TRUE, 'complete_url' => '/deliver/todos/7/l3-integration/complete', 'drush' => '',
      ],
      [
        'blueprint_id' => 7, 'blueprint_label' => '政务门户', 'blueprint_status' => 'confirmed',
        'blueprint_url' => '/deliver/blueprint/7', 'id' => 'l3-acceptance', 'title' => '回写验收报告',
        'status' => 'done', 'owner' => '', 'due' => '', 'remark' => '', 'notes' => '',
        'done_at' => '2026-08-01T00:00:00+00:00', 'overdue' => FALSE, 'complete_url' => '',
        'drush' => 'drush dx:delivery-todo-done 7 --batch=l3-acceptance',
      ],
    ],
    'stats' => ['total' => 4, 'open' => 3, 'done' => 1, 'overdue' => 1, 'missing_sla' => 1],
    'empty_text' => '',
    'empty_hint' => '',
    'can_complete' => TRUE,
    'drush_hint' => 'drush dx:delivery-todo-done 7 --batch=l3-integration,l3-acceptance',
  ];
  $html = $twig->render('board', $board);
  has('board heading', '<h1>L3 工单看板</h1>', $html);
  has('root hook', 'dx-deliver--todos', $html);
  has('stats block', '<ul class="dx-deliver-board__stats">', $html);
  has('overdue stat flagged', '<li class="is-overdue">', $html);
  has('filter tabs', '/deliver/todos?status=done', $html);
  has('blueprint chip active', 'dx-deliver-board__chip is-active', $html);
  has('clear filter link', 'href="/deliver/todos"', $html);
  has('table head 负责人', '<th>负责人</th>', $html);
  has('table head 到期', '<th>到期</th>', $html);
  has('open row hook', 'dx-deliver-board__row--open', $html);
  has('todo id in code', '<code class="dx-deliver-board__id">l3-integration</code>', $html);
  has('sign-off link', 'href="/deliver/todos/7/l3-integration/complete"', $html);
  has('signed row class', 'dx-deliver-board__row--done', $html);
  has('drush fallback for signed row', 'drush dx:delivery-todo-done 7 --batch=l3-acceptance', $html);
  has('batch hint shown to closers', '批量签核：', $html);
  has('empty owner placeholder', '—', $html);
  hasnt('signed row has no sign-off link', '/7/l3-acceptance/complete"', $html);

  $empty = $twig->render('board', array_replace($board, [
    'rows' => [],
    'empty_text' => '蓝图尚未确认执行，暂无 L3 工单。',
    'empty_hint' => '确认执行后会自动开立。',
  ]));
  has('empty state block', '<div class="dx-deliver-board__empty">', $empty);
  ok('empty state replaces the table', !str_contains($empty, '<table'), 'table leaked into empty state');
  has('empty copy', '蓝图尚未确认执行，暂无 L3 工单。', $empty);
  has('empty hint', '确认执行后会自动开立。', $empty);

  $readOnly = $twig->render('board', array_replace($board, [
    'can_complete' => FALSE,
    'rows' => [array_replace($board['rows'][0], ['complete_url' => '']), $board['rows'][1]],
  ]));
  hasnt('read-only users get no sign-off link', '/complete"', $readOnly);
  hasnt('read-only users get no batch hint', '批量签核：', $readOnly);
  has('read-only users still see the drush command', 'drush dx:delivery-todo-done 7 --batch=l3-acceptance', $readOnly);

  $noTabs = $twig->render('board', array_replace($board, ['tabs' => [], 'blueprint_tabs' => [], 'notice' => '', 'stats' => []]));
  hasnt('absent tabs produce no nav', '<nav', $noTabs);
  hasnt('absent notice produces empty paragraph', 'dx-deliver-board__notice', $noTabs);
  hasnt('absent stats produce no list', 'dx-deliver-board__stats', $noTabs);

  $xss = $twig->render('board', array_replace($board, ['rows' => [array_replace($board['rows'][0], [
    'title' => '<script>alert(1)</script>',
    'owner' => '"><b>',
  ])]]));
  hasnt('row title not injected raw', '<script>alert(1)</script>', $xss);
  has('row title escaped to entities', '&lt;script&gt;', $xss);
  hasnt('owner quote breaks out of markup', '"><b>', $xss);
}

echo "\n";
printf("%s: %d assertion(s), %d failure(s)\n", $failures === 0 ? 'OK' : 'FAIL', $checks, $failures);
exit($failures === 0 ? 0 : 1);
