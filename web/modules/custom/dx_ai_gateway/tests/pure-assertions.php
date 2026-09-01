<?php

/**
 * dx_ai_gateway offline pure assertions (lane L5 · roadmap Phase R3).
 *
 * Run: php web/modules/custom/dx_ai_gateway/tests/pure-assertions.php
 *
 * What this is: a dependency-free regression harness for the NEW read-only
 * readiness command AiStatusCommands (dx:ai-readiness). It boots nothing — no
 * Drupal kernel, no database, no HTTP traffic, no PHPUnit, and it never
 * contacts an AI provider. It pins two things:
 *
 *   1. AiStatusCommands::buildReport() — the pure model/key/quota shaper. It is
 *      a static method with no container access, so it runs on any checkout,
 *      including a lane worktree whose vendor/ is a symlink to production.
 *   2. The real config口径 in config/install/dx_ai_gateway.settings.yml, parsed
 *      read-only through vendor/symfony/yaml, so the provider ids / models /
 *      monthly quota the command will read are asserted against source of truth.
 *
 * Deliberately NOT covered here (needs a real container/site, see the lane doc):
 * the collect() wiring that calls AiGateway::hasApiKey()/getApiKeySource() and
 * UsageTracker::summary(), and the drush `--format=json` render path. Those are
 * exercised by tests/src/Unit/AiStatusCommandsTest.php under a maintenance
 * window's phpunit, plus the site段 of scripts/ci/auth-smoke.sh.
 */
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Harness (own function names: this file is always run standalone).
// ---------------------------------------------------------------------------

$air_pass = 0;
$air_fail = 0;
$air_failures = [];

function air_section(string $name): void {
  echo "\n== {$name}\n";
}

function air_ok(bool $cond, string $label): void {
  global $air_pass, $air_fail, $air_failures;
  if ($cond) {
    $air_pass++;
    echo "ok    {$label}\n";
    return;
  }
  $air_fail++;
  $air_failures[] = $label;
  echo "FAIL  {$label}\n";
}

function air_same(mixed $actual, mixed $expected, string $label): void {
  $cond = $actual === $expected;
  if (!$cond) {
    $label .= ' [expected ' . var_export($expected, TRUE) . ', got ' . var_export($actual, TRUE) . ']';
  }
  air_ok($cond, $label);
}

function air_has(string $haystack, string $needle, string $label): void {
  $cond = str_contains($haystack, $needle);
  if (!$cond) {
    $label .= ' [missing ' . $needle . ']';
  }
  air_ok($cond, $label);
}

// ---------------------------------------------------------------------------
// Bootstrap: composer autoload (Drush + symfony/yaml live in vendor) plus a
// read-only PSR-4 fallback for the module class this harness pins. Nothing here
// touches Drupal's container.
// ---------------------------------------------------------------------------

$ROOT = dirname(__DIR__, 5);
require $ROOT . '/vendor/autoload.php';

spl_autoload_register(static function (string $class) use ($ROOT): void {
  if (!str_starts_with($class, 'Drupal\\dx_ai_gateway\\')) {
    return;
  }
  $parts = explode('\\', $class);
  $sub = implode('/', array_slice($parts, 2)) . '.php';
  $file = $ROOT . '/web/modules/custom/dx_ai_gateway/src/' . $sub;
  if (is_file($file)) {
    require $file;
  }
});

use Drupal\dx_ai_gateway\Commands\AiStatusCommands;
use Symfony\Component\Yaml\Yaml;

$CMD = AiStatusCommands::class;

// ---------------------------------------------------------------------------
// 0 · The command class is the real, loadable production class, and buildReport
//     is a pure static we can call with no container at all.
// ---------------------------------------------------------------------------

air_section('R3 · command surface');
air_ok(class_exists($CMD), 'AiStatusCommands class loads (extends DrushCommands via vendor)');
air_ok(method_exists($CMD, 'buildReport'), 'buildReport() exists');
air_ok(method_exists($CMD, 'readiness'), 'readiness() command method exists');
$rm = new ReflectionMethod($CMD, 'buildReport');
air_ok($rm->isStatic() && $rm->isPublic(), 'buildReport() is public static (offline-testable)');
air_same($rm->getNumberOfParameters(), 2, 'buildReport() takes (rows, quota)');

// The docblock registers dx:ai-readiness and NEVER re-registers dx:ai-status,
// which stays owned by AiCommands (L1's delivery-ops-smoke.sh greps its
// ready_count). Duplicate @command names would collide in Drush.
$doc = (string) (new ReflectionMethod($CMD, 'readiness'))->getDocComment();
air_has($doc, '@command dx:ai-readiness', 'readiness() declares @command dx:ai-readiness');
air_ok(!str_contains($doc, '@command dx:ai-status'), 'readiness() does NOT claim the existing dx:ai-status name');
air_has($doc, '@option format table|json', 'readiness() declares the --format option');

// The drush.services.yml append keeps the existing entry intact and adds ours.
$drushYml = (string) file_get_contents($ROOT . '/web/modules/custom/dx_ai_gateway/drush.services.yml');
air_has($drushYml, 'dx_ai_gateway.commands:', 'existing dx_ai_gateway.commands service still present (append-only)');
air_has($drushYml, 'dx_ai_gateway.status_commands:', 'new dx_ai_gateway.status_commands service appended');
air_has($drushYml, AiStatusCommands::class, 'new service points at AiStatusCommands');
air_same(2, substr_count($drushYml, 'name: drush.command'), 'exactly two drush.command tags (no clobber)');

// ---------------------------------------------------------------------------
// 1 · The real config口径 the command reads (parsed read-only).
// ---------------------------------------------------------------------------

air_section('R3 · config口径 (config/install/dx_ai_gateway.settings.yml)');
$cfg = Yaml::parseFile($ROOT . '/web/modules/custom/dx_ai_gateway/config/install/dx_ai_gateway.settings.yml');
air_same('deepseek', $cfg['default_provider'] ?? NULL, 'default_provider ships as deepseek');
air_same(['deepseek', 'qwen', 'zhipu', 'openai'], array_values($cfg['failover_order'] ?? []), 'failover_order');
air_same(100000, (int) ($cfg['monthly_quota'] ?? 0), 'monthly_quota ships as 100000');
$providerIds = array_keys($cfg['providers'] ?? []);
air_same(['openai', 'deepseek', 'qwen', 'zhipu'], $providerIds, 'four providers declared');
air_same('deepseek-chat', $cfg['providers']['deepseek']['model'] ?? NULL, 'deepseek model');
air_same('qwen-plus', $cfg['providers']['qwen']['model'] ?? NULL, 'qwen model');
air_same('glm-4', $cfg['providers']['zhipu']['model'] ?? NULL, 'zhipu model');
air_same('gpt-4o-mini', $cfg['providers']['openai']['model'] ?? NULL, 'openai model');

// Build the provider rows exactly as collect() would from the real config, with
// no key configured anywhere (the "无密钥环境" the task calls out).
$rowsFromConfig = static function (array $cfg, string $default, array $configured = []) use (&$rowsFromConfig): array {
  $rows = [];
  foreach (($cfg['providers'] ?? []) as $id => $meta) {
    $id = (string) $id;
    $src = $configured[$id] ?? 'none';
    $rows[] = [
      'id' => $id,
      'label' => (string) ($meta['label'] ?? $id),
      'model' => (string) ($meta['model'] ?? ''),
      'key_configured' => $src !== 'none',
      'key_source' => $src,
      'default' => $id === $default,
    ];
  }
  return $rows;
};
$quotaOk = ['period' => '2026-09', 'quota' => 100000, 'tokens_used' => 0, 'remaining' => 100000, 'calls' => 0, 'ok_calls' => 0];

// ---------------------------------------------------------------------------
// 2 · No-key environment: parseable, flags every missing provider.
// ---------------------------------------------------------------------------

air_section('R3 · 无密钥环境 (all keys absent)');
$noKey = AiStatusCommands::buildReport($rowsFromConfig($cfg, 'deepseek'), $quotaOk);
air_same(FALSE, $noKey['ready'], 'not ready when the default provider has no key');
air_same(0, $noKey['ready_count'], 'ready_count 0');
air_same(4, $noKey['provider_count'], 'provider_count 4');
air_same(['openai', 'deepseek', 'qwen', 'zhipu'], $noKey['missing'], 'every provider is reported missing');
air_has((string) $noKey['hint'], 'missing API key(s)', 'hint names the missing keys');
air_has((string) $noKey['hint'], 'DX_AI_{PROVIDER}_KEY', 'hint tells the operator how to fix it');
air_has((string) $noKey['hint'], '/admin/dx/ai', 'hint points at the config page');
air_same('deepseek', $noKey['default_provider'], 'default_provider resolved');
air_same(['configured' => FALSE, 'source' => 'none'], $noKey['keys']['deepseek'], 'key block carries no secret, only state');
air_same('deepseek-chat', $noKey['models']['deepseek'], 'model block present per provider');

// The report is valid, round-trippable JSON (the --format=json contract).
$json = json_encode($noKey, JSON_UNESCAPED_UNICODE);
air_ok($json !== FALSE && json_validate($json), 'no-key report is valid JSON');
$decoded = json_decode((string) $json, TRUE);
air_same($noKey, $decoded, 'JSON round-trips identically (parseable by CI)');

// The 三元组 keys are always present, even with nothing configured.
foreach (['models', 'keys', 'quota'] as $tripleKey) {
  air_ok(array_key_exists($tripleKey, $noKey), "triple key '{$tripleKey}' always present");
}

// ---------------------------------------------------------------------------
// 3 · Partial keys: only the default matters for `ready`, others still listed.
// ---------------------------------------------------------------------------

air_section('R3 · 部分密钥 (default configured via environment)');
$partial = AiStatusCommands::buildReport(
  $rowsFromConfig($cfg, 'deepseek', ['deepseek' => 'environment']),
  $quotaOk
);
air_same(TRUE, $partial['ready'], 'ready once the default provider has a key and quota remains');
air_same(1, $partial['ready_count'], 'ready_count 1');
air_same(['openai', 'qwen', 'zhipu'], $partial['missing'], 'the still-unkeyed providers are listed');
air_ok(!in_array('deepseek', $partial['missing'], TRUE), 'the keyed default is not in missing');
air_same(['configured' => TRUE, 'source' => 'environment'], $partial['keys']['deepseek'], 'source=environment propagated');
air_same('site', AiStatusCommands::buildReport(
  $rowsFromConfig($cfg, 'deepseek', ['deepseek' => 'site']),
  $quotaOk
)['keys']['deepseek']['source'], 'source=site propagated');
air_has((string) $partial['hint'], 'missing API key(s)', 'hint still flags the other providers');

// ---------------------------------------------------------------------------
// 4 · Quota gate: a keyed default with remaining=0 is still not ready.
// ---------------------------------------------------------------------------

air_section('R3 · 配额闸门 (quota exhausted)');
$quotaZero = ['period' => '2026-09', 'quota' => 100000, 'tokens_used' => 100000, 'remaining' => 0, 'calls' => 12, 'ok_calls' => 9];
$exhausted = AiStatusCommands::buildReport(
  $rowsFromConfig($cfg, 'deepseek', ['deepseek' => 'site', 'qwen' => 'site', 'zhipu' => 'site', 'openai' => 'site']),
  $quotaZero
);
air_same(FALSE, $exhausted['ready'], 'a spent quota makes it not-ready even with keys');
air_same(4, $exhausted['ready_count'], 'all four keys counted');
air_same([], $exhausted['missing'], 'no provider missing');
air_has((string) $exhausted['hint'], 'monthly quota exhausted', 'hint names the exhausted quota');
air_same(0, $exhausted['quota']['remaining'], 'quota.remaining passthrough');
air_same(12, $exhausted['quota']['calls'], 'quota.calls passthrough');
air_same(9, $exhausted['quota']['ok_calls'], 'quota.ok_calls passthrough');

// Fully ready → hint is the clean sentinel.
$allReady = AiStatusCommands::buildReport(
  $rowsFromConfig($cfg, 'deepseek', ['deepseek' => 'site', 'qwen' => 'site', 'zhipu' => 'site', 'openai' => 'site']),
  $quotaOk
);
air_same(TRUE, $allReady['ready'], 'keyed default + quota → ready');
air_same('ok', $allReady['hint'], 'clean hint sentinel when nothing is missing');
air_same([], $allReady['missing'], 'missing empty when all keyed');

// ---------------------------------------------------------------------------
// 5 · Edge shapes: empty rows, partial quota, stray secrets, blank ids.
// ---------------------------------------------------------------------------

air_section('R3 · 边界形状');
$empty = AiStatusCommands::buildReport([], ['remaining' => 5]);
air_same(0, $empty['provider_count'], 'no providers → provider_count 0');
air_same(FALSE, $empty['ready'], 'no providers → not ready');
air_same('', $empty['default_provider'], 'no default resolved');
air_has((string) $empty['hint'], 'no default provider resolved', 'hint explains the empty catalog');
air_same([], $empty['models'], 'models empty');
air_same([], $empty['keys'], 'keys empty');

// A partial/absent quota summary still yields a fully-typed quota block.
$noQuota = AiStatusCommands::buildReport($rowsFromConfig($cfg, 'deepseek'), []);
air_same(0, $noQuota['quota']['quota'], 'absent quota → int 0');
air_same('', $noQuota['quota']['period'], 'absent period → empty string');
air_same(0, $noQuota['quota']['remaining'], 'absent remaining → 0 (thus not ready)');
air_same(FALSE, $noQuota['ready'], 'absent quota means not ready');

// Rows with a blank id are ignored, not emitted.
$withBlank = AiStatusCommands::buildReport([
  ['id' => '', 'model' => 'ghost', 'key_configured' => TRUE, 'key_source' => 'site', 'default' => TRUE],
  ['id' => 'deepseek', 'label' => 'DeepSeek', 'model' => 'deepseek-chat', 'key_configured' => TRUE, 'key_source' => 'site', 'default' => FALSE],
], $quotaOk);
air_same(1, $withBlank['provider_count'], 'blank-id row skipped');
air_ok(!isset($withBlank['models']['']), 'no empty-id model entry');

// Secret safety: even if a caller leaks an api_key into a row, buildReport
// whitelists fields and never re-emits it.
$leaky = AiStatusCommands::buildReport([
  ['id' => 'deepseek', 'model' => 'deepseek-chat', 'key_configured' => TRUE, 'key_source' => 'site', 'default' => TRUE, 'api_key' => 'sk-SUPER-SECRET-123'],
], $quotaOk);
$leakyJson = (string) json_encode($leaky, JSON_UNESCAPED_UNICODE);
air_ok(!str_contains($leakyJson, 'sk-SUPER-SECRET-123'), 'a stray api_key never reaches the report output');
air_ok(!str_contains($leakyJson, 'api_key'), 'no api_key field in the shaped report');
air_same(['id', 'label', 'model', 'key_configured', 'key_source', 'default'], array_keys($leaky['providers'][0]), 'provider row schema is a fixed whitelist');

// ---------------------------------------------------------------------------
// Summary.
// ---------------------------------------------------------------------------

echo "\n";
if ($air_fail > 0) {
  echo "FAILURES:\n";
  foreach ($air_failures as $f) {
    echo "  - {$f}\n";
  }
}
printf("OK: %d assertion(s), %d failure(s)\n", $air_pass, $air_fail);
exit($air_fail === 0 ? 0 : 1);
