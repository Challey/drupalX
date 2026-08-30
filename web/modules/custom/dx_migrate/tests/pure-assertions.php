<?php

/**
 * Pure PHP assertions for the dx_migrate lane L2 deliverables (roadmap G1/G2).
 *
 * Run directly, no Drupal bootstrap, no database, no network:
 *
 *   php web/modules/custom/dx_migrate/tests/pure-assertions.php
 *
 * Covered:
 *  - G1 declarative template library (loading, `extends` deep merge, validation
 *    messages, "ship a new industry as data only" proof, backward compatibility
 *    of the default `auto` mapping and of the fixture-derived parse results).
 *  - G2 batch bookkeeping for the review queue (id normalisation, per-run
 *    ceiling, success/failure aggregation, payload snapshot store).
 *
 * @see \Drupal\dx_migrate\Service\L2TemplateRegistry
 * @see \Drupal\dx_migrate\Service\ReviewBatch
 * @see \Drupal\dx_migrate\Service\ReviewPayloadStore
 */

declare(strict_types=1);

use Drupal\dx_migrate\Service\L1HtmlAdapter;
use Drupal\dx_migrate\Service\L2TemplateException;
use Drupal\dx_migrate\Service\L2TemplateRegistry;
use Drupal\dx_migrate\Service\ReviewBatch;
use Drupal\dx_migrate\Service\ReviewPayloadStore;

$moduleDir = dirname(__DIR__);
// The module sits in <root>/web/modules/custom/dx_migrate, so <root>/vendor is
// four levels above the module directory. Worktrees keep the same layout.
$vendorDir = '';
foreach ([dirname($moduleDir, 3), dirname($moduleDir, 4)] as $candidate) {
  if (is_dir($candidate . '/vendor/symfony/yaml')) {
    $vendorDir = $candidate . '/vendor';
    break;
  }
}
if ($vendorDir === '') {
  fwrite(STDERR, "FAIL  cannot locate vendor/symfony/yaml; run this script from a full checkout\n");
  exit(1);
}

// Minimal PSR-4 mapping: the custom module is not part of the composer class
// map, and Symfony Yaml is read straight out of the (read-only) vendor tree.
spl_autoload_register(static function (string $class) use ($moduleDir, $vendorDir): void {
  $map = [
    'Drupal\\dx_migrate\\' => $moduleDir . '/src/',
    'Symfony\\Component\\Yaml\\' => $vendorDir . '/symfony/yaml/',
  ];
  foreach ($map as $prefix => $dir) {
    if (!str_starts_with($class, $prefix)) {
      continue;
    }
    $path = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
      require_once $path;
    }
    return;
  }
});

$assertions = 0;
$failures = 0;
$scratch = [];

/**
 * Report one assertion result.
 */
$report = static function (bool $ok, string $label, string $detail = '') use (&$assertions, &$failures): void {
  $assertions++;
  if ($ok) {
    return;
  }
  $failures++;
  fwrite(STDERR, "FAIL  " . $label . ($detail === '' ? '' : "  ->  " . $detail) . "\n");
};
$ok = static function (bool $cond, string $label) use ($report): void {
  $report($cond, $label);
};
$same = static function (mixed $expected, mixed $actual, string $label) use ($report): void {
  $report($expected === $actual, $label, $expected === $actual ? '' : 'expected ' . var_export($expected, TRUE) . ', got ' . var_export($actual, TRUE));
};
$has = static function (string $needle, string $haystack, string $label) use ($report): void {
  $report(str_contains($haystack, $needle), $label, 'missing "' . $needle . '" in: ' . $haystack);
};
$hasnt = static function (string $needle, string $haystack, string $label) use ($report): void {
  $report(!str_contains($haystack, $needle), $label, 'unexpected "' . $needle . '" in: ' . $haystack);
};
$section = static function (string $title): void {
  echo "\n-- " . $title . "\n";
};
/**
 * Run a callable and return the message of the L2TemplateException it raised.
 */
$throws = static function (callable $fn, string $label) use ($report): string {
  try {
    $fn();
  }
  catch (L2TemplateException $e) {
    $report(TRUE, $label);
    return $e->getMessage() . ' | ' . $e->issuesString();
  }
  catch (\Throwable $e) {
    $report(FALSE, $label, 'wrong exception class ' . get_class($e) . ': ' . $e->getMessage());
    return '';
  }
  $report(FALSE, $label, 'no exception raised');
  return '';
};
$fieldsOf = static fn (array $issues): string => implode(',', array_map(static fn(array $r): string => $r['field'], $issues));

$registry = new L2TemplateRegistry();
$adapter = new L1HtmlAdapter($registry);

// ---------------------------------------------------------------------------
$section('G1 template library: discovery');
$ok(count($registry->names()) >= 5, 'library exposes at least the 5 bundled templates');
$same(['auto', 'gov_news', 'ent_article', 'legacy', 'hospital_notice'], $registry->names(), 'public template list is weight-ordered');
$has('_base', implode(',', $registry->allNames()), 'internal parent is listed by allNames()');
$hasnt('_base', implode(',', $registry->names()), 'internal parent is hidden from names()');
$same([], $registry->invalid(), 'no bundled template is broken');
$same(FALSE, $registry->has('_base'), 'internal parent is not usable directly');
$same(TRUE, $registry->has('gov_news'), 'gov_news is usable');
$same(FALSE, $registry->has('no_such_template'), 'unknown name does not "have" a template');
$same(FALSE, $registry->exists('no_such_template'), 'unknown name does not exist on disk');
foreach ($registry->names() as $name) {
  $ok(is_readable($registry->files()[$name] ?? ''), 'template file readable: ' . $name);
}
$thrown = $throws(static fn () => $registry->definition('no_such_template'), 'unknown template raises');
$has('Available: auto', $thrown, 'unknown template error lists the alternatives');
$thrown = $throws(static fn () => $registry->definition('_base'), 'internal parent cannot be used directly');
$has('internal parent', $thrown, 'internal parent error explains the mechanism');

// ---------------------------------------------------------------------------
$section('G1 backward compatibility: the default mapping is unchanged');
$auto = $registry->definition('auto');
$has('news-list', (string) $auto['list']['xpath'], 'auto keeps the historical news-list selector');
$has('article-list', (string) $auto['list']['xpath'], 'auto keeps the historical article-list selector');
$has('gov-news', (string) $auto['list']['xpath'], 'auto keeps the historical gov-news selector');
$same('//a[@href]', $auto['list']['fallback_xpath'], 'auto keeps the catch-all fallback xpath');
$same('legacy-list.html', $auto['list']['fixture'], 'auto keeps the legacy list fixture');
$same('href_title', $auto['item']['external_id']['source'], 'auto keeps href_title id derivation');
$same('l1_', $auto['item']['external_id']['prefix'], 'auto keeps the l1_ external id prefix');
$same(16, $auto['item']['external_id']['length'], 'auto keeps the 16 char id digest');
$same(40, $auto['item']['max_items'], 'auto keeps the 40 item ceiling');
$same(4, $auto['item']['min_title_length'], 'auto keeps the 4 char title filter');
$same('首页|登录|注册|关于我们|联系我们', $auto['item']['reject_title_pattern'], 'auto keeps the navigation noise pattern');
$same('article', $auto['resource']['type'], 'auto ingests as article');
$same('draft', $auto['resource']['status'], 'auto ingests as draft');
$same(TRUE, $auto['resource']['review'], 'auto keeps items in review');
$same(10, $auto['resource']['detail_limit'], 'auto enriches 10 details by default');
$same(15, $auto['fetch']['timeout'], 'auto keeps the 15s fetch timeout');
$same(8, $auto['detail']['body_max_paragraphs'], 'auto keeps the 8 paragraph cap');
$same('<p></p>', $auto['detail']['body_empty_html'], 'auto keeps the empty body placeholder');
$same('auto', $auto['machine_name'], 'machine_name is derived from the file name');
// Unknown names keep falling back (old callers), broken names do not (new guard).
$same($registry->definition('auto'), $adapter->definition('no_such_template'), 'unknown template still falls back to auto');
$same($registry->definition('auto'), $adapter->definition(''), 'empty template argument falls back to auto');

// ---------------------------------------------------------------------------
$section('G1 validation: missing keys produce an explicit report');
$issues = $registry->validate([]);
$fields = $fieldsOf($issues);
foreach (['label', 'list.fixture', 'list.xpath', 'item.max_items', 'item.title.from', 'item.href.from', 'item.external_id.source', 'item.external_id.length', 'item.body.parts', 'detail.title_xpath', 'detail.body_xpath', 'detail.fixture_slug', 'detail.body_empty_html', 'fetch.timeout', 'fetch.user_agent', 'resource.type', 'resource.status', 'resource.detail_limit'] as $required) {
  $has($required, $fields, 'empty definition reports ' . $required);
}
$has('required', L2TemplateException::formatIssues($issues), 'issues carry human readable reasons');
$bad = L2TemplateRegistry::mergeArrays($auto, ['spec_version' => '2.0', 'typo_key' => 1]);
$fields = $fieldsOf($registry->validate($bad, 'auto'));
$has('spec_version', $fields, 'unsupported spec version is reported');
$has('2.0', L2TemplateException::formatIssues($registry->validate($bad, 'auto')), 'unsupported spec version names the value');
$has('typo_key', $fields, 'unknown top level key is reported');
$bad = L2TemplateRegistry::mergeArrays($auto, ['item' => ['body' => ['parts' => ['<p>{bogus}</p>']]]]);
$msg = L2TemplateException::formatIssues($registry->validate($bad, 'auto'));
$has('unknown placeholder {bogus}', $msg, 'unknown body placeholder is named');
$has('known names: title', $msg, 'unknown body placeholder lists the known ones');
$bad = L2TemplateRegistry::mergeArrays($auto, ['item' => ['external_id' => ['length' => 4]]]);
$has('item.external_id.length', $fieldsOf($registry->validate($bad, 'auto')), 'too short external id length is reported');
$bad = L2TemplateRegistry::mergeArrays($auto, ['detail' => ['fixture_slug' => '{1}-{7}']]);
$msg = L2TemplateException::formatIssues($registry->validate($bad, 'auto'));
$has('detail.fixture_slug', $msg, 'fixture slug referencing a missing capture is reported');
$bad = L2TemplateRegistry::mergeArrays($auto, ['list' => ['fixture' => '../secrets.html']]);
$has('must be a plain file name', L2TemplateException::formatIssues($registry->validate($bad, 'auto')), 'fixture path traversal is rejected');
$bad = L2TemplateRegistry::mergeArrays($auto, ['item' => ['reject_title_pattern' => '([unclosed']]);
$has('item.reject_title_pattern', $fieldsOf($registry->validate($bad, 'auto')), 'unusable PCRE is reported');
$bad = L2TemplateRegistry::mergeArrays($auto, ['resource' => ['type' => 'Gov_News']]);
$has('lower-case with underscores', L2TemplateException::formatIssues($registry->validate($bad, 'auto')), 'uppercase resource type is rejected');
$same([], $registry->validate($auto, 'auto'), 'the bundled auto definition validates cleanly');

// ---------------------------------------------------------------------------
$section('G1 extends: deep merge with the parent, whole-list override');
$merged = L2TemplateRegistry::mergeArrays(['a' => ['b' => 1, 'c' => 2]], ['a' => ['c' => 3]]);
$same(['a' => ['b' => 1, 'c' => 3]], $merged, 'associative branches merge key by key');
$merged = L2TemplateRegistry::mergeArrays(['x' => [1, 2, 3]], ['x' => [9]]);
$same(['x' => [9]], $merged, 'lists are replaced whole, never appended');
$notice = $registry->definition('hospital_notice');
$same('hospital-notice-list.html', $notice['list']['fixture'], 'child overrides the list fixture');
$has('notice-board', $notice['list']['xpath'], 'child overrides the list xpath');
$same('l1_', $notice['item']['external_id']['prefix'], 'child inherits the external id prefix');
$same('href_title', $notice['item']['external_id']['source'], 'child inherits the id source');
$same(8, $notice['detail']['body_max_paragraphs'], 'child inherits the paragraph cap');
$same('//p', $notice['detail']['body_fallback_xpath'], 'child inherits the paragraph fallback');
$has('notice-content', $notice['detail']['body_xpath'][0], 'child replaces the body selectors');
$same('//a[@href]', $notice['list']['fallback_xpath'], 'child inherits the fallback xpath');
$same(FALSE, isset($notice['extends']), 'loader-only keys are stripped from the definition');
$dir = (string) sys_get_temp_dir() . '/dxm_bad_tpl_' . getmypid() . '_' . bin2hex(random_bytes(4));
mkdir($dir);
$scratch[] = $dir;
file_put_contents($dir . '/broken_one.yml', "label: ''\nlist:\n  fixture: ''\n");
$registry->addDirectory($dir);
$ok($registry->exists('broken_one'), 'a broken template is still "present"');
$ok(!$registry->has('broken_one'), 'a broken template is never usable');
$has('broken_one', implode(',', array_keys($registry->invalid())), 'invalid() lists the broken template');
$thrown = $throws(static fn () => $registry->definition('broken_one'), 'a broken template raises instead of falling back');
$has('label: required', $thrown, 'the error names the missing label key');
$has('list.fixture', $fieldsOf($registry->invalid()['broken_one']), 'the error names the missing fixture key');
file_put_contents($dir . '/orphan_one.yml', "extends: no_such_parent\nlabel: 'Orphan'\n");
$registry->refresh();
$thrown = $throws(static fn () => $registry->definition('orphan_one'), 'a missing parent raises');
$has('unknown parent "no_such_parent"', $thrown, 'the error names the missing parent');

// ---------------------------------------------------------------------------
$section('G1 shipping a new industry as data only (no PHP change)');
$dir2 = (string) sys_get_temp_dir() . '/dxm_new_tpl_' . getmypid() . '_' . bin2hex(random_bytes(4));
mkdir($dir2);
$scratch[] = $dir2;
$file = $dir2 . '/clinic_bulletin.yml';
file_put_contents($file, implode("\n", [
  'extends: _base',
  "label: 'Clinic bulletin board'",
  'weight: 45',
  'list:',
  '  fixture: hospital-notice-list.html',
  '  xpath: \'//*[contains(@class,"notice-board")]//a[@href]\'',
  'resource:',
  '  type: notice',
  '  status: draft',
  '  review: true',
  '  detail_limit: 5',
  'detail:',
  '  body_xpath:',
  '    - \'//*[contains(@class,"notice-content")]\'',
]));
$fresh = new L2TemplateRegistry();
$def = $fresh->loadFile($file);
$same('Clinic bulletin board', $def['label'], 'loadFile() parses and validates a candidate file');
$same('notice', $def['resource']['type'], 'the new template can retarget the DXEP resource type');
$fresh->addDirectory($dir2);
$same(TRUE, $fresh->has('clinic_bulletin'), 'dropping the file into a directory makes it selectable');
$same('notice', $fresh->definition('clinic_bulletin')['resource']['type'], 'definition() resolves the new template');
file_put_contents($dir2 . '/missing.yml', "label: 'x'\n");
$thrown = $throws(static fn () => (new L2TemplateRegistry())->loadFile($dir2 . '/nope_not_here.yml'), 'loadFile() refuses an unreadable path');
$has('not readable', $thrown, 'the unreadable path error is explicit');
file_put_contents($dir2 . '/Bad Name.txt', "label: 'x'\n");
$thrown = $throws(static fn () => (new L2TemplateRegistry())->loadFile($dir2 . '/Bad Name.txt'), 'loadFile() refuses a non machine-name file');
$has('machine name', $thrown, 'the file name error explains the expected shape');
file_put_contents($dir2 . '/bad_json.json', '{this is not json');
$fresh->refresh();
$thrown = $throws(static fn () => $fresh->definition('bad_json'), 'a malformed JSON template raises');
$has('cannot parse', $thrown, 'parse failures are reported, not swallowed');
$fresh->addDirectory($dir);
$thrown = $throws(static fn () => $fresh->definition('broken_one'), 'the runtime library keeps refusing the broken template');
$has('list.xpath', $thrown, 'every missing key is listed for the runtime library too');

// ---------------------------------------------------------------------------
$section('G1 adapter behaviour on the bundled fixtures');
$list = $adapter->loadHtml('', TRUE, 'hospital_notice');
$has('notice-board', $list, 'hospital_notice list fixture is loaded through the template');
$items = $adapter->parseList($list, 'probe', 'hospital_notice');
$same(3, count($items), 'hospital_notice fixture yields 3 notices');
$same('l1_58676174e399070d', $items[0]['external_id'], 'hospital_notice external id is stable');
$same('院区门诊搬迁公告', $items[0]['title'], 'hospital_notice title is extracted');
$same('/notice/3001', $items[0]['href'], 'hospital_notice href is extracted');
$same('draft', $items[0]['status'], 'hospital_notice items are drafted');
$has('<p>Source: probe</p>', $items[0]['body']['html'], 'the declarative body parts render the source line');
$same($items, $adapter->parseList($list, 'probe', 'hospital_notice'), 'parsing twice is deterministic');
$govItems = $adapter->parseList($adapter->loadHtml('', TRUE, 'gov_news'), 'probe', 'gov_news');
$same(3, count($govItems), 'gov_news fixture yields 3 items');
$same('l1_8d511815bcd04d32', $govItems[0]['external_id'], 'gov_news external id is unchanged');
$autoItems = $adapter->parseList($adapter->loadHtml('', TRUE, 'auto'), 'probe', 'auto');
$same(5, count($autoItems), 'auto falls back to the widest historical list fixture');
$same($govItems[0]['external_id'], $autoItems[0]['external_id'], 'auto and gov_news agree on shared items');
$detailHtml = (string) $adapter->loadDetailHtml('/notice/3001', '', TRUE, 'hospital_notice');
$has('notice-content', $detailHtml, 'the template specific fixture map resolves /notice/3001 -> notice-3001.html');
$detail = $adapter->parseDetail($detailHtml, 'hospital_notice');
$same('院区门诊搬迁公告', $detail['title'], 'detail title selector comes from the template');
$has('搬迁至新门诊楼三层', $detail['body_html'], 'detail body selector comes from the template');
$same('2026-04-01 08:00', $detail['published_at'], 'detail published date is extracted');
$same('院办公室', $detail['source'], 'detail source is extracted');
$same('<p><em>Published: 2026-04-01 08:00 · Source: 院办公室</em></p>', $adapter->detailMetaHtml($detail, 'hospital_notice'), 'meta line is assembled from detail.meta');
$same('article', $adapter->resourceType('hospital_notice'), 'hospital_notice keeps the inherited resource type');
$same(10, $adapter->detailLimit('hospital_notice'), 'hospital_notice keeps the inherited detail limit');
$same('notice', (new L1HtmlAdapter($fresh))->resourceType('clinic_bulletin'), 'the adapter consumes a template that only exists as data');
$same(NULL, $adapter->loadDetailHtml('/nothing/here', '', TRUE, 'hospital_notice'), 'unmapped href yields no detail html');

// ---------------------------------------------------------------------------
$section('G1 template rendering helpers');
$same('T|', L2TemplateRegistry::renderTemplate('{title}|{nope}', ['title' => 'T']), 'unknown placeholder renders empty');
$same('&lt;b&gt;', L2TemplateRegistry::renderTemplate('{title_esc}', ['title' => '<b>']), '_esc suffix escapes');
$same('<b>', L2TemplateRegistry::renderTemplate('{title}', ['title' => '<b>']), 'raw placeholder does not escape');
$same('/a\/b/u', L2TemplateRegistry::delimiterRegex('a/b'), 'bare PCRE gets one canonical delimiter rule');
$ok(preg_match(L2TemplateRegistry::delimiterRegex('首页|登录'), '首页栏目') === 1, 'the wrapped pattern is executable');
$same('&quot;x&quot;', L2TemplateRegistry::escape('"x"'), 'escape matches the legacy flags');
$same('notice-3001', L2TemplateRegistry::renderSlug('{1}-{2}', ['', 'notice', '3001']), 'slug template uses preg captures');
$same('', L2TemplateRegistry::renderParts([['if' => 'source', 'html' => '<p>{source}</p>']], ['source' => '']), 'conditional part is skipped when empty');
$same('<p>S</p>', L2TemplateRegistry::renderParts([['if' => 'source', 'html' => '<p>{source}</p>']], ['source' => 'S']), 'conditional part is kept when filled');
$same('<p>A</p><p>B</p>', L2TemplateRegistry::renderParts(['<p>A</p>', '<p>B</p>'], []), 'plain parts concatenate');
$same('gov_news', L2TemplateRegistry::machineName('/tmp/gov_news.yml'), '.yml maps to a machine name');
$same('gov_news', L2TemplateRegistry::machineName('/tmp/gov_news.yaml'), '.yaml maps to a machine name');
$same('gov_news', L2TemplateRegistry::machineName('/tmp/gov_news.json'), '.json maps to a machine name');
$same(NULL, L2TemplateRegistry::machineName('/tmp/Gov_News.yml'), 'upper case file names are refused');
$same(NULL, L2TemplateRegistry::machineName('/tmp/a.yml'), 'single character names are refused');
$same(NULL, L2TemplateRegistry::machineName('/tmp/README.txt'), 'unknown suffixes are refused');
$same(L2TemplateRegistry::SUPPORTED_SPEC_VERSIONS, ['1.0'], 'exactly one spec version is supported for now');

// ---------------------------------------------------------------------------
$section('G2 review batch: ids, ceiling and aggregation');
$plan = ReviewBatch::normalize(['#12', '12', '', '13', 'abc', '#0']);
$same(['12', '13'], $plan['accepted'], 'node ids accept the "#NN" list form and de-duplicate');
$same(['abc', '0'], array_map(static fn(array $r): string => $r['input'], $plan['rejected']), 'bad ids are rejected by value, not skipped');
$same('not a node id', $plan['rejected'][0]['reason'], 'a non numeric id explains itself');
$same('node id must be positive', $plan['rejected'][1]['reason'], 'node id 0 explains itself');
$same(ReviewBatch::MAX_ITEMS, $plan['limit'], 'the plan carries the effective ceiling');
$many = ReviewBatch::normalize(range(1, 60));
$same(50, count($many['accepted']), 'a batch never accepts more than the ceiling');
$same(10, count($many['overflow']), 'the overflow is reported instead of being lost');
$same(50, ReviewBatch::normalize(range(1, 60), 999)['limit'], 'the requested ceiling is clamped to the hard maximum');
$same(1, ReviewBatch::normalize(['5', '5'], 0)['limit'], 'a bogus ceiling degrades to 1, never to 0');
$ext = ReviewBatch::normalize(['l1_58676174e399070d', 'bad id!', str_repeat('x', 200)], kind: 'external_id');
$same(['l1_58676174e399070d'], $ext['accepted'], 'external ids are accepted in the DXEP charset');
$same(['bad id!', str_repeat('x', 200)], array_map(static fn(array $r): string => $r['input'], $ext['rejected']), 'invalid external ids are rejected');
$same(TRUE, ReviewBatch::isAction('replay'), 'replay is a known action');
$same(FALSE, ReviewBatch::isAction('delete'), 'unknown actions are refused before any write');
$results = [];
$results = ReviewBatch::record($results, '12', TRUE, 'published');
$results = ReviewBatch::record($results, '13', FALSE, 'node was deleted');
$summary = ReviewBatch::summarize($results);
$same(2, $summary['total'], 'summary counts every attempted item');
$same(1, $summary['succeeded'], 'summary counts the successes');
$same(1, $summary['failed'], 'summary counts the failures');
$same(['12'], array_column($summary['succeeded_items'], 'key'), 'the success list names its items');
$same(['13'], array_column($summary['failed_items'], 'key'), 'the failure list names its items');
$same(FALSE, $summary['ok'], 'a partially failed run is not ok');
$same(TRUE, ReviewBatch::summarize(ReviewBatch::record([], '9', TRUE))['ok'], 'a fully successful run is ok');
$same(FALSE, ReviewBatch::summarize([])['ok'], 'an empty run is not reported as success');
$report2 = ReviewBatch::report(ReviewBatch::ACTION_PUBLISH, ReviewBatch::normalize(['12', '13', 'abc', '14', '15'], 2), $results);
$same('publish', $report2['action'], 'the report echoes the action');
$same(2, $report2['limit'], 'the report echoes the effective limit');
$same(5, $report2['requested'], 'the report counts everything the operator asked for');
$same(2, count($report2['overflow']), 'items beyond the ceiling surface as overflow');
$same(1, count($report2['rejected']), 'bad ids surface as rejected');
$same(2, $report2['total'], 'only the accepted ids were attempted');
$same(ReviewBatch::ACTIONS, ['publish', 'discard', 'replay'], 'the batch action vocabulary is fixed');

// ---------------------------------------------------------------------------
$section('G2 payload snapshots: put, lookup, prune, stats');
$store = [];
$store = ReviewPayloadStore::put($store, 'article:ext-1', ['type' => 'article', 'external_id' => 'ext-1', 'captured_at' => 100]);
$store = ReviewPayloadStore::put($store, 'notice:ext-2', ['type' => 'notice', 'external_id' => 'ext-2', 'captured_at' => 200]);
$store = ReviewPayloadStore::put($store, 'article:ext-1', ['type' => 'article', 'external_id' => 'ext-1', 'captured_at' => 300]);
$same(2, count($store), 're-capturing the same resource does not duplicate it');
$same('article:ext-1', array_key_last($store), 'a refreshed snapshot becomes the newest entry');
$same(300, ReviewPayloadStore::lookup($store, 'ext-1')['captured_at'], 'lookup by external id finds the refreshed row');
$same(300, ReviewPayloadStore::lookup($store, 'ext-1', 'article')['captured_at'], 'lookup with an explicit type is exact');
$same(NULL, ReviewPayloadStore::lookup($store, 'ext-1', 'notice'), 'lookup with the wrong type yields nothing');
$same(NULL, ReviewPayloadStore::lookup($store, ''), 'an empty external id yields nothing');
$same(NULL, ReviewPayloadStore::lookup($store, 'absent'), 'an unknown external id yields nothing');
$store = ReviewPayloadStore::put($store, 'article:ext-9', ['type' => 'article', 'external_id' => 'ext-9', 'captured_at' => 400], 2);
$same(2, count($store), 'the store is pruned back to the ceiling');
$same(FALSE, isset($store['notice:ext-2']), 'the least recently captured snapshot is the one dropped');
$same(TRUE, isset($store['article:ext-1']), 'a snapshot refreshed after capture survives the prune');
$stats = ReviewPayloadStore::stats([
  'article:a' => ['type' => 'article', 'captured_at' => 1000],
  'notice:b' => ['type' => 'notice', 'captured_at' => 2000],
  'notice:c' => ['type' => 'notice', 'captured_at' => 500],
]);
$same(3, $stats['snapshots'], 'stats count the snapshots');
$same(['article' => 1, 'notice' => 2], $stats['by_type'], 'stats group by resource type');
$same(date('c', 500), $stats['oldest'], 'stats carry an oldest timestamp');
$ok($stats['newest'] !== '' && $stats['oldest'] !== '' && $stats['oldest'] <= $stats['newest'], 'oldest never postdates newest');

// ---------------------------------------------------------------------------
foreach ($scratch as $dir) {
  foreach (glob($dir . '/*') ?: [] as $f) {
    @unlink((string) $f);
  }
  @rmdir($dir);
}

printf("dx_migrate pure assertions: %d assertion(s), %d failure(s)\n", $assertions, $failures);
exit($failures === 0 ? 0 : 1);
