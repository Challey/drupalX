<?php

/**
 * Pure PHP assertions for the dx_channel lane L2 deliverables (roadmap G3/G4).
 *
 * Run directly, no Drupal bootstrap, no database, no network:
 *
 *   php web/modules/custom/dx_channel/tests/pure-assertions.php
 *
 * Covered:
 *  - G3 SHA-256 ledger build / render / parse / compare, the apply-time guard,
 *    canonical digests and a regression check against the committed ZIP
 *    fixture; report paging, failure extraction and idempotent retry merging.
 *  - G4 delivery-health aggregation: counters, success rate, grading, per day
 *    windows, dead-letter backoff, and "nothing configured yet" behaviour.
 *  - contract: docs/openapi/dxep-v1.yaml, dx_channel.routing.yml and the stable
 *    error codes stay in sync (parsing the OpenAPI document with Symfony Yaml is
 *    also the cheapest way to catch an unquoted colon before a deploy does).
 *
 * @see \Drupal\dx_channel\Service\ExchangeChecksums
 * @see \Drupal\dx_channel\Service\ExchangeReport
 * @see \Drupal\dx_channel\Service\WebhookHealth
 */

declare(strict_types=1);

use Drupal\dx_channel\Service\ExchangeChecksums;
use Drupal\dx_channel\Service\ExchangeReport;
use Drupal\dx_channel\Service\ExchangeService;
use Drupal\dx_channel\Service\WebhookHealth;

$moduleDir = dirname(__DIR__);

// Only the classes under test are loaded; none of them touches the container,
// the state table or the filesystem, so this runs outside Drupal entirely.
spl_autoload_register(static function (string $class) use ($moduleDir): void {
  $prefix = 'Drupal\\dx_channel\\';
  if (!str_starts_with($class, $prefix)) {
    return;
  }
  $path = $moduleDir . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
  if (is_file($path)) {
    require_once $path;
  }
});

$assertions = 0;
$failures = 0;

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
 * Fail if the callable raises any PHP notice/warning/deprecation: a health
 * report built from a legacy state document must stay silent.
 *
 * @param callable(): mixed $fn
 */
$silent = static function (callable $fn, string $label) use ($report): mixed {
  $seen = [];
  $previous = set_error_handler(static function (int $level, string $message) use (&$seen): bool {
    $seen[] = $message;
    return TRUE;
  });
  try {
    $result = $fn();
  }
  finally {
    set_error_handler($previous);
  }
  $report($seen === [], $label, implode(' | ', $seen));
  return $result ?? NULL;
};

// ---------------------------------------------------------------------------
$section('G3 ledger: build, render, parse');
$files = [
  'package.json' => '{"manifest":{"package_id":"pkg_x"}}',
  'payload/extra.txt' => "line one\nline two\n",
];
$ledger = ExchangeChecksums::ledger($files);
$same(64, strlen($ledger['package.json']), 'a digest is 64 hex characters');
$ok($ledger === ExchangeChecksums::ledger($files), 'hashing the same bytes twice is stable');
$same(array_keys($ledger), ['package.json', 'payload/extra.txt'], 'ledger rows are name-ordered');
$text = ExchangeChecksums::renderLedger($ledger, 'pkg_x');
$has('# DXEP sha256 ledger', $text, 'the ledger opens with a machine readable header');
$has('# package_id=pkg_x', $text, 'the ledger names the package it belongs to');
$has(hash('sha256', $files['package.json']) . '  package.json', $text, 'rows use the sha256sum "<hash>  <name>" form');
$parsed = ExchangeChecksums::parseLedger($text);
$same($ledger, $parsed['entries'], 'render -> parse is lossless');
$same([], $parsed['malformed'], 'the generated ledger is clean');
$parsed = ExchangeChecksums::parseLedger(
  "# comment\r\n\r\n" . str_repeat('a', 64) . "  package.json\n" . str_repeat('b', 64) . "  *binary.bin\n" . str_repeat('c', 63) . "  too-short.txt\nnonsense line\n",
);
$same([str_repeat('b', 64), str_repeat('a', 64)], array_values($parsed['entries']), 'CRLF, blank lines and the binary marker all parse');
$same(['binary.bin', 'package.json'], array_keys($parsed['entries']), 'the binary marker is stripped from the name');
$same(2, count($parsed['malformed']), 'a short digest and a junk line are both reported');
$has('digest is 63 chars', $parsed['malformed'][0]['reason'], 'the digest length complaint states what it saw');
$has('not a <digest>', $parsed['malformed'][1]['reason'], 'an unparsable row is reported, never dropped silently');
$same([], ExchangeChecksums::parseLedger('')['entries'], 'an empty ledger parses to nothing');
$same('a/b.json', ExchangeChecksums::normalizeName('./a/b.json'), 'a ./ prefix is normalised away');
$same('a/b.json', ExchangeChecksums::normalizeName(' a\\b.json '), 'windows separators are normalised');
$same('a/b.json', ExchangeChecksums::normalizeName('/a/b.json'), 'an absolute name is anchored at the archive root');
$escape = ExchangeChecksums::parseLedger(str_repeat('a', 64) . "  ../etc/passwd\n" . str_repeat('b', 64) . "  package.json\n");
$same(['package.json'], array_keys($escape['entries']), 'a valid row beside the escaping one is still read');
$same(1, count($escape['malformed']), 'a ledger path with a .. segment is refused as a malformed row');
$has('escapes the archive root', $escape['malformed'][0]['reason'], 'the reason names the traversal attempt');
$escapeCmp = ExchangeChecksums::compare($escape['entries'], ExchangeChecksums::ledger($files), $escape['malformed']);
$same(FALSE, $escapeCmp['ok'], 'one escaping row fails the whole archive');
$escapeIntegrity = ExchangeChecksums::integrityFromComparison($escapeCmp, $escape['entries']);
$same(ExchangeChecksums::STATUS_MISMATCHED, $escapeIntegrity['status'], 'the escaping archive is not verified');
$same(ExchangeChecksums::ERR_INVALID, $escapeIntegrity['error_code'], 'a broken ledger is CHECKSUM_INVALID, distinct from a content mismatch');
$same(ExchangeChecksums::ERR_INVALID, ExchangeChecksums::applyGuard($escapeIntegrity)['code'], 'and apply refuses it with the same code');
$ok(in_array('checksums.sha256[1]', array_column($escapeIntegrity['issues'], 'field'), TRUE), 'the issue points at the offending ledger line');

// ---------------------------------------------------------------------------
$section('G3 compare: mismatch, missing, undeclared');
$actual = $ledger + ['ghost.txt' => str_repeat('d', 64)];
$cmp = ExchangeChecksums::compare($ledger, $actual);
$same(FALSE, $cmp['ok'], 'an undeclared archive member fails verification');
$same(['ghost.txt'], $cmp['undeclared'], 'the undeclared member is named');
$cmp = ExchangeChecksums::compare(['package.json' => str_repeat('e', 64), 'gone.txt' => str_repeat('f', 64)], $ledger);
$same(FALSE, $cmp['ok'], 'a tampered file fails verification');
$same('package.json', $cmp['mismatched'][0]['name'], 'the mismatch names the file');
$same(str_repeat('e', 64), $cmp['mismatched'][0]['expected'], 'the mismatch carries the expected digest');
$same(['gone.txt'], $cmp['missing'], 'a declared file missing from the archive is reported');
$cmp = ExchangeChecksums::compare($ledger, $ledger);
$same(TRUE, $cmp['ok'], 'an untouched archive verifies');
$same(['package.json', 'payload/extra.txt'], $cmp['matched'], 'every verified file is listed');
$integrity = ExchangeChecksums::integrityFromComparison($cmp, $ledger, 'abc');
$same(ExchangeChecksums::STATUS_VERIFIED, $integrity['status'], 'a clean comparison yields status=verified');
$same('', $integrity['error_code'], 'a verified package has no error code');
$same('sha256', $integrity['algorithm'], 'the algorithm is recorded for the audit trail');
$broken = ExchangeChecksums::compare($ledger, ['package.json' => str_repeat('1', 64), 'payload/extra.txt' => $ledger['payload/extra.txt']]);
$integrity = ExchangeChecksums::integrityFromComparison($broken, $ledger);
$same(ExchangeChecksums::STATUS_MISMATCHED, $integrity['status'], 'a tampered member yields status=mismatched');
$same(ExchangeChecksums::ERR_MISMATCH, $integrity['error_code'], 'the stable error code is attached');
$has('checksums.package.json', $integrity['issues'][0]['field'], 'the issue is filed under the offending file');

// ---------------------------------------------------------------------------
$section('G3 canonical digests and the apply guard');
$docA = ['manifest' => ['b' => 2, 'a' => 1], 'resources' => [['type' => 'article', 'external_id' => 'x']]];
$docB = ['resources' => [['external_id' => 'x', 'type' => 'article']], 'manifest' => ['a' => 1, 'b' => 2]];
$same(
  ExchangeChecksums::canonicalDigest($docA),
  ExchangeChecksums::canonicalDigest($docB),
  'key order and nesting order do not change the digest',
);
$ok(
  ExchangeChecksums::canonicalDigest($docA) !== ExchangeChecksums::canonicalDigest(['manifest' => ['a' => 1, 'b' => 3], 'resources' => []]),
  'a content change does change the digest',
);
$manifest = ['package_id' => 'pkg_x', 'content_sha256' => str_repeat('9', 64)];
$same(
  ExchangeChecksums::contentDigest(['package_id' => 'pkg_x'], []),
  ExchangeChecksums::contentDigest($manifest, []),
  'the embedded digest never feeds back into its own input',
);
$same(64, strlen(ExchangeChecksums::contentDigest($manifest, [['type' => 'article', 'external_id' => 'a']])), 'a content digest is 64 hex chars');
$same(NULL, ExchangeChecksums::applyGuard(NULL), 'a package with no integrity record still applies (pre-G3 data)');
$same(NULL, ExchangeChecksums::applyGuard(['status' => ExchangeChecksums::STATUS_LEGACY]), 'legacy packages keep applying');
$same(NULL, ExchangeChecksums::applyGuard(['status' => ExchangeChecksums::STATUS_INLINE]), 'inline JSON registrations keep applying');
$same(NULL, ExchangeChecksums::applyGuard(['status' => ExchangeChecksums::STATUS_UNSIGNED]), 'unsigned archives keep applying');
$guard = ExchangeChecksums::applyGuard(['status' => ExchangeChecksums::STATUS_MISSING]);
$same(ExchangeChecksums::ERR_MISSING, $guard['code'], 'a missing ledger is refused with a stable code');
$has('checksums.sha256', $guard['message'], 'the refusal names the missing file');
$guard = ExchangeChecksums::applyGuard(['status' => ExchangeChecksums::STATUS_MISMATCHED]);
$same(ExchangeChecksums::ERR_MISMATCH, $guard['code'], 'a mismatched ledger is refused with a stable code');
$guard = ExchangeChecksums::applyGuard(['status' => ExchangeChecksums::STATUS_VERIFIED, 'content_sha256' => 'aaa'], 'bbb');
$same(ExchangeChecksums::ERR_MISMATCH, $guard['code'], 'state tampered after registration is refused at apply time');
$same(NULL, ExchangeChecksums::applyGuard(['status' => ExchangeChecksums::STATUS_VERIFIED, 'content_sha256' => 'aaa'], 'aaa'), 'an unchanged verified package applies');
$same(NULL, ExchangeChecksums::applyGuard(['status' => ExchangeChecksums::STATUS_VERIFIED, 'content_sha256' => 'aaa']), 'a verified package without a recomputed digest is not blocked');
$same('checksums.sha256', ExchangeChecksums::LEDGER_NAME, 'the ledger file name is part of the contract');
$same(ExchangeService::splitKey('notice:abc-1'), ['notice', 'abc-1'], 'a report key splits into type + external id');
$same(ExchangeService::splitKey('plain'), ['article', 'plain'], 'a key without a type defaults to article');

// ---------------------------------------------------------------------------
$section('G3 the committed ZIP fixture really is sealed');
$zipPath = $moduleDir . '/data/packages/demo-package.zip';
$ok(is_readable($zipPath), 'demo-package.zip is readable');
$members = [];
$zip = new ZipArchive();
$same(TRUE, $zip->open($zipPath) === TRUE, 'demo-package.zip opens');
for ($i = 0; $i < $zip->numFiles; $i++) {
  $name = $zip->getNameIndex($i);
  $members[$name] = $zip->getFromName($name);
}
$zip->close();
$ok(isset($members[ExchangeChecksums::PACKAGE_NAME]), 'the archive carries package.json');
$ok(isset($members[ExchangeChecksums::LEDGER_NAME]), 'the archive carries checksums.sha256');
$declared = ExchangeChecksums::parseLedger((string) $members[ExchangeChecksums::LEDGER_NAME]);
$cmp = ExchangeChecksums::compare($declared['entries'], ExchangeChecksums::ledger($members));
$same(TRUE, $cmp['ok'], 'the shipped ledger matches the shipped bytes');
$package = json_decode((string) $members[ExchangeChecksums::PACKAGE_NAME], TRUE);
$same('pkg_demo_zip_fixture', $package['manifest']['package_id'] ?? NULL, 'the fixture keeps its package id');
$same(2, count($package['resources'] ?? []), 'the fixture still carries two resources');
$tampered = $members;
$tampered[ExchangeChecksums::PACKAGE_NAME] = str_replace('pkg_demo_zip_fixture', 'pkg_demo_zip_tampered', (string) $tampered[ExchangeChecksums::PACKAGE_NAME]);
$cmp = ExchangeChecksums::compare($declared['entries'], ExchangeChecksums::ledger($tampered));
$same(FALSE, $cmp['ok'], 'a one-word edit of the archive is detected');
$same(ExchangeChecksums::ERR_MISMATCH, ExchangeChecksums::applyGuard(ExchangeChecksums::integrityFromComparison($cmp, $declared['entries']))['code'], 'the tampered archive is refused with DX.EXCHANGE.CHECKSUM_MISMATCH');

// ---------------------------------------------------------------------------
$section('G3 apply report paging');
$rows = [];
for ($i = 1; $i <= 60; $i++) {
  $rows[] = ['type' => 'article', 'external_id' => 'a' . $i, 'ok' => $i % 20 !== 0, 'issues' => $i % 20 === 0 ? ['title is required'] : []];
}
$reportDoc = ['applied' => 57, 'failed' => 3, 'dry_run' => FALSE, 'items' => $rows];
$page1 = ExchangeReport::paginate($reportDoc, 1, 25);
$same(25, count($page1['items']), 'page 1 shows the configured page size');
$same(1, $page1['page'], 'page number is echoed back');
$same(3, $page1['total_pages'], '60 items at 25 per page is 3 pages');
$same(60, $page1['total_items'], 'the total item count survives paging');
$same(57, $page1['applied'], 'aggregate counters describe the whole run, not the page');
$same('a1', $page1['items'][0]['external_id'], 'page 1 starts at the first row');
$page3 = ExchangeReport::paginate($reportDoc, 3, 25);
$same(10, count($page3['items']), 'the last page carries the remainder');
$same('a51', $page3['items'][0]['external_id'], 'page 3 starts where page 2 ended');
$same(FALSE, $page3['page_clamped'], 'an in-range page is not clamped');
$far = ExchangeReport::paginate($reportDoc, 99, 25);
$same(3, $far['page'], 'a page beyond the end is clamped to the last page');
$same(TRUE, $far['page_clamped'], 'the clamp is visible to the client');
$same(25, ExchangeReport::paginate($reportDoc, 0, 0)['page_size'], 'a zero page size falls back to the minimum');
$same(ExchangeReport::MAX_PAGE_SIZE, ExchangeReport::paginate($reportDoc, 1, 5000)['page_size'], 'the page size is capped');
$same(1, ExchangeReport::paginate(['items' => []], 1)['total_pages'], 'an empty report still has one page');
$same(0, count(ExchangeReport::paginate(['items' => []], 5)['items']), 'an empty report pages to nothing');
$same(25, ExchangeReport::DEFAULT_PAGE_SIZE, 'the default page size is 25');
$noItems = ExchangeReport::paginate(['applied' => 0], 1, 10);
$same([], $noItems['items'], 'a report without items yields an empty page, not an error');
$failed = ExchangeReport::failedItems($reportDoc);
$same(3, count($failed), 'the failed rows are extractable');
$same('article:a20', $failed[0]['key'], 'a failure is addressed by type:external_id');
$same(['title is required'], $failed[0]['issues'], 'the failure reason travels with the key');
$same('article:x', ExchangeReport::key('article', 'x'), 'the key format is part of the contract');

// ---------------------------------------------------------------------------
$section('G3 retry merging is idempotent');
$first = ['applied' => 1, 'failed' => 1, 'items' => [
  ['type' => 'article', 'external_id' => 'a1', 'ok' => TRUE],
  ['type' => 'article', 'external_id' => 'a2', 'ok' => FALSE, 'issues' => ['title is required']],
]];
$second = ['applied' => 1, 'failed' => 0, 'items' => [
  ['type' => 'article', 'external_id' => 'a2', 'ok' => TRUE],
]];
$merged = ExchangeReport::mergeReports($first, $second);
$same(2, count($merged['items']), 'a retry never grows the report');
$same(2, $merged['applied'], 'the merged counters recount every row');
$same(0, $merged['failed'], 'the previously failed row is now applied');
$same(1, $merged['retry_count'], 'the retry is counted once');
$same(2, (int) $merged['items'][1]['attempts'], 'the retried row records two attempts');
$same(1, (int) $merged['items'][0]['attempts'], 'an untouched row still records one attempt');
$again = ExchangeReport::mergeReports($merged, $second);
$same(2, count($again['items']), 'retrying twice still does not grow the report');
$same(3, (int) $again['items'][1]['attempts'], 'each retry bumps the attempt counter');
$same(2, $again['retry_count'], 'the retry counter accumulates');
$merged = ExchangeReport::mergeReports($first, $first);
$same(2, count($merged['items']), 're-applying the same rows replaces them');
$same(FALSE, ExchangeReport::retriesExhausted($merged, 5), 'one retry is not exhausted');
$hot = $merged;
$hot['retry_count'] = 5;
$same(TRUE, ExchangeReport::retriesExhausted($hot, 5), 'five retries exhausts the budget');
$clean = ['items' => [['type' => 'article', 'external_id' => 'a1', 'ok' => TRUE]], 'failed' => 0, 'retry_count' => 9, 'retries_exhausted' => TRUE];
$green = ExchangeReport::mergeReports($clean, $second);
$same(FALSE, $green['retries_exhausted'], 'a fully applied report clears the exhausted flag');
$blob = ExchangeReport::mergeItems(['items' => [['error' => 'manifest missing']]], ['items' => [['error' => 'no resources']]]);
$same(2, count($blob), 'unidentified rows keep their own slot');
$same('manifest missing', $blob[0]['error'], 'the first untagged row survives');
$same(1, (int) $blob[0]['attempts'], 'unidentified rows default to one attempt');
$merged = ExchangeReport::mergeReports($first, $second);
$counts = ExchangeReport::summarize(ExchangeReport::items($merged));
$same(['applied' => 2, 'failed' => 0, 'total' => 2], $counts, 'the summary is recomputed from the merged rows');
$has('applied=2 failed=0 items=2', ExchangeReport::message($merged), 'the log line is machine readable');
$has('dry-run', ExchangeReport::message(['dry_run' => TRUE, 'items' => []]), 'dry runs are distinguishable in logs');
$same([], ExchangeReport::items(['items' => 'not-an-array']), 'a corrupt item list degrades to empty');

// ---------------------------------------------------------------------------
$section('G4 webhook health counters');
$today = gmdate('Y-m-d');
$yesterday = gmdate('Y-m-d', strtotime('-1 day'));
$atToday = $today . 'T10:00:00+00:00';
$atYesterday = $yesterday . 'T10:00:00+00:00';
$stats = $silent(static fn () => WebhookHealth::normalize([]), 'normalizing a legacy/empty counter document is silent');
$same(0, $stats['dropped'], 'a fresh counter document has no dropped deliveries');
$same(0, $stats['attempts'], 'a fresh counter document has no attempts');
$same(0, $stats['failed'], 'a fresh counter document has no failures');
$stats = WebhookHealth::recordDispatch(
  $stats,
  'resource.published',
  [['endpoint' => 'wh_site', 'ok' => TRUE], ['endpoint' => 'wh_vendor', 'ok' => FALSE]],
  FALSE,
  $atYesterday,
);
$same(2, $stats['attempts'], 'one dispatch to two endpoints is two attempts');
$same(1, $stats['sent'], 'the successful delivery is counted');
$same(1, $stats['failed'], 'the failed delivery is counted');
$same('resource.published', $stats['last_event'], 'the last event is remembered');
$same(1, $stats['by_endpoint']['wh_site']['sent'], 'per endpoint counters are kept apart');
$same(1, $stats['by_endpoint']['wh_vendor']['failed'], 'the failing endpoint owns its failure');
$same($atYesterday, $stats['by_endpoint']['wh_vendor']['last_error_at'], 'the failing endpoint records when it last errored');
$same(2, $stats['days'][$yesterday]['attempts'], 'the daily series is bucketed by UTC day');
$before = $stats;
$stats = WebhookHealth::recordDispatch($stats, 'resource.published', [], FALSE, $atToday);
$same($before['attempts'], $stats['attempts'], 'matching no endpoint is not a delivery failure');
$same('resource.published', $stats['last_event'], 'the event is still noted');
$stats = WebhookHealth::recordDispatch($stats, 'resource.published', [['endpoint' => 'wh_site', 'ok' => TRUE]], TRUE, $atToday);
$same(1, $stats['rate_limited'], 'a throttled dispatch is recorded as rate limited');
$same(2, $stats['attempts'], 'a throttled dispatch adds no attempt');
$stats = WebhookHealth::recordRetry($stats, 3, 1, 1, $atToday);
$same(4, $stats['retried'], 'the retry run counts every replayed payload');
$same(3, $stats['retried_sent'], 'retry successes are tracked separately');
$same(6, $stats['attempts'], 'retried deliveries add to the lifetime attempts');
$same(1, $stats['dropped'], 'payloads dropped by the retry budget are counted');
$same($atToday, $stats['last_retry_at'], 'the last retry run is timestamped');
$same(2, $stats['days'][$yesterday]['attempts'], 'the earlier day keeps its own totals');
$same(4, $stats['days'][$today]['attempts'], 'the retry run lands on the day it was executed');

// ---------------------------------------------------------------------------
$section('G4 health report, grading and windows');
$endpoints = [
  'wh_site' => ['url' => 'https://hooks.example.test/dx', 'enabled' => TRUE, 'events' => ['resource.published']],
  'wh_vendor' => ['url' => 'https://vendor.example.test/hook', 'enabled' => FALSE, 'events' => []],
];
$view = WebhookHealth::report($stats, $endpoints, 2, 7);
$same(TRUE, $view['configured'], 'a site with an endpoint URL counts as configured');
$same(TRUE, $view['enabled'], 'an enabled endpoint with a URL switches delivery on');
$same(2, $view['endpoints_total'], 'the report lists every endpoint');
$same(1, $view['endpoints_enabled'], 'and how many of them are enabled');
$same(6, $view['attempts'], 'the seven day window sees all six attempts');
$same(4, $view['sent'], 'four of them arrived');
$same(2, $view['failed'], 'two of them did not');
$same(6, $view['lifetime']['attempts'], 'lifetime counters stay visible next to the window');
$same(2, $view['dead_letters'], 'the dead-letter depth is part of the report');
$same(0.6667, $view['success_rate'], 'success rate is sent / attempts');
$same(66.67, $view['success_rate_percent'], 'and is offered as a percentage too');
$same('degraded', $view['status'], 'a two thirds success rate grades as degraded');
$same(['wh_vendor'], array_slice(array_column($view['by_endpoint'], 'id'), 0, 1), 'the most failing endpoint sorts first');
$same(1, $view['by_endpoint'][1]['sent'], 'the healthy endpoint reports its own traffic');
$same(2, count($view['daily']), 'two days of traffic yield two daily rows');
$same($yesterday, $view['daily'][0]['day'], 'the daily rows are oldest first');
$empty = WebhookHealth::report(WebhookHealth::emptyStats(), [], 0, 7);
$same('unconfigured', $empty['status'], 'with no endpoint at all the report says unconfigured');
$same(NULL, $empty['success_rate'], 'and does not divide by zero');
$same(0, $empty['attempts'], 'an unused site has no attempts');
$same(FALSE, $empty['configured'], 'nothing is configured');
$same([], $empty['by_endpoint'], 'and there is no endpoint table to render');
$same([], $empty['daily'], 'nor a daily series');
$same(FALSE, WebhookHealth::enabled([['url' => 'https://x.test', 'enabled' => FALSE]]), 'a disabled endpoint never counts as enabled');
$same(FALSE, WebhookHealth::configured([['url' => '   ']]), 'a blank URL is not configured');
$same(TRUE, WebhookHealth::configured([['url' => 'https://x.test/hook']]), 'a real URL is configured');
$same('unknown', WebhookHealth::grade(0, 0), 'configured but never tried is unknown, not healthy');
$same('healthy', WebhookHealth::grade(100, 96), 'a 96% window is healthy');
$same('degraded', WebhookHealth::grade(100, 90), 'a 90% window is degraded');
$same('failing', WebhookHealth::grade(100, 50), 'a 50% window is failing');
$same('unconfigured', WebhookHealth::grade(100, 100, FALSE), 'an unconfigured site is never graded on traffic');
$same(0.95, WebhookHealth::HEALTHY_RATE, 'the healthy threshold is 95%');
$same(0.5, WebhookHealth::FAILING_RATE, 'the failing threshold is 50%');
$window = WebhookHealth::windowTotals([
  '2026-04-30' => ['attempts' => 9, 'sent' => 9, 'failed' => 0],
  '2026-05-01' => ['attempts' => 2, 'sent' => 1, 'failed' => 1],
  '2026-05-02' => ['attempts' => 4, 'sent' => 3, 'failed' => 1],
], 2, '2026-05-02');
$same(6, $window['attempts'], 'a two day window excludes older days');
$same(2, count($window['rows']), 'and lists exactly the days inside it');
$same(4, $window['sent'], 'the window sums successes');
$same(2, $window['failed'], 'and failures');
$same(0, WebhookHealth::windowTotals([], 0)['attempts'], 'a zero day window is widened to one day, never to nothing');
$future = WebhookHealth::windowTotals(['2099-01-01' => ['attempts' => 5]], 7, '2026-05-02');
$same(0, $future['attempts'], 'a future-dated bucket is excluded rather than breaking the sum');

// ---------------------------------------------------------------------------
$section('G4 dead-letter backoff and retry budget');
$same(WebhookHealth::MAX_ATTEMPTS, 8, 'the retry budget matches docs/data-exchange.md §10.3');
$same(1, WebhookHealth::backoffSeconds(1), 'the first retry waits one second');
$same(5, WebhookHealth::backoffSeconds(2), 'the second retry waits five seconds');
$same(7200, WebhookHealth::backoffSeconds(8), 'the last allowed retry waits two hours');
$same(-1, WebhookHealth::backoffSeconds(9), 'beyond the budget no delay is offered');
$same(0, WebhookHealth::backoffSeconds(0), 'attempt zero does not wait at all');
$same(count(WebhookHealth::BACKOFF_SECONDS), WebhookHealth::MAX_ATTEMPTS, 'the backoff table covers every attempt');
$same(TRUE, WebhookHealth::mayRetry(7), 'a seventh attempt may be retried');
$same(FALSE, WebhookHealth::mayRetry(8), 'an eighth attempt is the last one');
$same(FALSE, WebhookHealth::mayRetry(3, 3), 'the budget is injectable for tooling');
$monotonic = TRUE;
$last = -1;
foreach (range(1, WebhookHealth::MAX_ATTEMPTS) as $attempt) {
  $wait = WebhookHealth::backoffSeconds($attempt);
  $monotonic = $monotonic && $wait >= $last;
  $last = $wait;
}
$ok($monotonic, 'the backoff schedule never shortens');
$days = WebhookHealth::normalize(WebhookHealth::recordDispatch([], 'resource.published', [['endpoint' => 'wh_site', 'ok' => FALSE]]));
$ok(is_string($days['updated_at']) && $days['updated_at'] !== '', 'a dispatch without an explicit timestamp still stamps itself');

// ---------------------------------------------------------------------------
$section('contract: the OpenAPI document, the routes and the error codes agree');

$projectRoot = dirname(__DIR__, 5);
$composerAutoload = $projectRoot . '/vendor/autoload.php';
if (!is_file($composerAutoload)) {
  // Symfony Yaml lives in the composer vendor tree. Without it this section
  // cannot run, so say so loudly instead of pretending it passed.
  fwrite(STDOUT, "SKIP  contract section: {$composerAutoload} not found (composer install first)\n");
}
else {
  require_once $composerAutoload;
  /** @var array<string, mixed> $openapi */
  $openapi = Symfony\Component\Yaml\Yaml::parseFile($projectRoot . '/docs/openapi/dxep-v1.yaml');
  /** @var array<string, array<string, mixed>> $channelRoutes */
  $channelRoutes = Symfony\Component\Yaml\Yaml::parseFile($moduleDir . '/dx_channel.routing.yml');
  /** @var array<string, array<string, mixed>> $migrateRoutes */
  $migrateRoutes = Symfony\Component\Yaml\Yaml::parseFile(
    $projectRoot . '/web/modules/custom/dx_migrate/dx_migrate.routing.yml',
  );

  $paths = array_keys(is_array($openapi['paths'] ?? NULL) ? $openapi['paths'] : []);
  $ok(count($paths) >= 16, 'the OpenAPI document still lists every endpoint', 'got ' . count($paths));

  // Every exchange / webhook endpoint we promise must exist as a route.
  $routePaths = [];
  foreach ($channelRoutes as $route) {
    if (isset($route['path'])) {
      $routePaths[(string) $route['path']] = TRUE;
    }
  }
  $promised = 0;
  foreach ($paths as $path) {
    if (!preg_match('#^/api/dx/v1/(exchange|webhooks)#', (string) $path)) {
      continue;
    }
    $promised++;
    $ok(isset($routePaths[$path]), "documented endpoint {$path} has a route");
  }
  $ok($promised >= 11, 'the contract covers the G3/G4 surface', "only {$promised} endpoints");

  // ... and every API route we ship for those two families is documented, so a
  // new endpoint cannot land in routing.yml without the contract being updated.
  foreach (array_keys($routePaths) as $routePath) {
    if (!preg_match('#^/api/dx/v1/(exchange|webhooks)#', $routePath)) {
      continue;
    }
    $ok(in_array($routePath, $paths, TRUE), "route {$routePath} is documented in dxep-v1.yaml");
  }

  // The four endpoints that used to be documented as /exchange/* really are
  // /api/dx/v1/exchange/* in routing.yml; a stale alias would resurface here.
  $hasnt('/exchange/changes:', implode(PHP_EOL, $paths), 'no legacy /exchange/changes path');
  $hasnt('/exchange/push:', implode(PHP_EOL, $paths), 'no legacy /exchange/push path');

  // $ref targets must all resolve, otherwise the document cannot be rendered.
  $refs = [];
  $collect = static function (mixed $node) use (&$collect, &$refs): void {
    if (!is_array($node)) {
      return;
    }
    foreach ($node as $key => $value) {
      if ($key === '$ref' && is_string($value)) {
        $refs[] = $value;
        continue;
      }
      $collect($value);
    }
  };
  $collect($openapi);
  $ok($refs !== [], 'the document uses component schemas');
  foreach (array_unique($refs) as $ref) {
    $node = $openapi;
    foreach (explode('/', ltrim((string) $ref, '#')) as $leg) {
      $leg = str_replace(['~1', '~0'], ['/', '~'], $leg);
      $leg = ltrim($leg, '/');
      if ($leg === '') {
        continue;
      }
      $node = is_array($node) && array_key_exists($leg, $node) ? $node[$leg] : NULL;
    }
    $ok(is_array($node), "schema reference {$ref} resolves");
  }

  // The wire contract must name the same ledger file and error codes the code uses.
  $doc = (string) file_get_contents($projectRoot . '/docs/data-exchange.md');
  $spec = (string) file_get_contents($projectRoot . '/docs/openapi/dxep-v1.yaml');
  foreach ([
    ExchangeChecksums::LEDGER_NAME,
    ExchangeChecksums::PACKAGE_NAME,
    ExchangeChecksums::ERR_MISSING,
    ExchangeChecksums::ERR_MISMATCH,
    ExchangeChecksums::ERR_INVALID,
    'DX.EXCHANGE.RETRY_EXHAUSTED',
  ] as $token) {
    $ok(str_contains($doc, $token), "docs/data-exchange.md names {$token}");
    $ok(str_contains($spec, $token), "dxep-v1.yaml names {$token}");
  }
  $has('checksums.sha256', $spec, 'the register endpoint tells clients about the ledger file');

  // G2: the batch form is reachable through a routed Drupal form (tokenised),
  // not through a GET link, and the class actually exists.
  $batch = $migrateRoutes['dx_migrate.review_batch'] ?? NULL;
  $ok(is_array($batch), 'dx_migrate defines the review_batch route');
  $same('/admin/dx/migrate/review/batch', (string) ($batch['path'] ?? ''), 'at the documented path');
  $same('\Drupal\dx_migrate\Form\ReviewQueueBatchForm', (string) ($batch['defaults']['_form'] ?? ''), 'served by a form class (so it carries a form token)');
  $ok(is_file($projectRoot . '/web/modules/custom/dx_migrate/src/Form/ReviewQueueBatchForm.php'), 'the batch form class exists on disk');
  $has('administer dx migrate', (string) ($batch['requirements']['_permission'] ?? ''), 'behind the existing migrate permission');

  // The two new page callbacks are API routes guarded by Bearer scope, so they
  // must not be reachable with _access: 'TRUE' plus no controller check.
  foreach (['dx_channel.exchange_package_report', 'dx_channel.exchange_package_retry', 'dx_channel.webhooks_health'] as $routeName) {
    $def = $channelRoutes[$routeName] ?? NULL;
    $ok(is_array($def), "route {$routeName} is registered");
    $controller = (string) ($def['defaults']['_controller'] ?? '');
    $ok(str_contains($controller, 'Controller::'), "{$routeName} dispatches to a controller", $controller);
  }
}

printf("dx_channel pure assertions: %d assertion(s), %d failure(s)\n", $assertions, $failures);
exit($failures === 0 ? 0 : 1);
