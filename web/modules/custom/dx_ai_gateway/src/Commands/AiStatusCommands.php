<?php

declare(strict_types=1);

namespace Drupal\dx_ai_gateway\Commands;

use Drupal\dx_ai_gateway\Service\AiGateway;
use Drupal\dx_ai_gateway\Service\UsageTracker;
use Drush\Commands\DrushCommands;

/**
 * Readiness report for the AI gateway: the model / key / quota triple.
 *
 * roadmap Phase R · R3. This is a NEW, strictly read-only command: it never
 * mutates configuration, never writes State and never contacts a provider. It
 * only reads what AiGateway / UsageTracker already expose.
 *
 * Naming note (deliberate): the existing `dx:ai-status` lives in AiCommands and
 * keeps its provider key-readiness contract — L1's delivery-ops-smoke.sh greps
 * its `ready_count`. Registering a second `dx:ai-status` would collide in Drush
 * and break that lane, and editing AiCommands is out of scope for this lane. So
 * the richer triple report ships under a distinct name, `dx:ai-readiness`, and
 * leaves `dx:ai-status` byte-for-byte untouched.
 *
 * Takes effect after `drush cr` (drush.services.yml is append-only here).
 */
class AiStatusCommands extends DrushCommands {

  public function __construct(
    protected AiGateway $aiGateway,
    protected UsageTracker $usageTracker,
  ) {
    parent::__construct();
  }

  /**
   * Report AI readiness as a model / key / quota triple (never any secret).
   *
   * @command dx:ai-readiness
   * @aliases dx-ai-readiness
   * @option format table|json
   * @usage drush dx:ai-readiness
   * @usage drush dx:ai-readiness --format=json
   */
  public function readiness(array $options = ['format' => 'table']): void {
    $report = $this->collect();
    if (($options['format'] ?? 'table') === 'json') {
      $this->output()->writeln(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
      return;
    }
    $this->io()->table(
      ['Provider', 'Model', 'Key', 'Source', 'Default'],
      array_map(static fn (array $p): array => [
        (string) $p['id'],
        (string) $p['model'],
        !empty($p['key_configured']) ? 'yes' : 'NO',
        (string) $p['key_source'],
        !empty($p['default']) ? 'default' : '',
      ], $report['providers'])
    );
    $q = $report['quota'];
    $this->io()->writeln(sprintf(
      'quota: %s used=%d/%d remaining=%d calls=%d ok=%d',
      (string) $q['period'],
      (int) $q['tokens_used'],
      (int) $q['quota'],
      (int) $q['remaining'],
      (int) $q['calls'],
      (int) $q['ok_calls']
    ));
    $this->io()->writeln('ready: ' . ($report['ready'] ? 'yes' : 'no') . '  hint: ' . (string) $report['hint']);
  }

  /**
   * Gathers the triple from the read-only gateway / usage services.
   *
   * @return array<string, mixed>
   *   The shaped report (see buildReport()).
   */
  protected function collect(): array {
    $default = $this->aiGateway->getDefaultProvider();
    $rows = [];
    foreach ((array) $this->aiGateway->getProviders() as $id => $meta) {
      $id = (string) $id;
      $rows[] = [
        'id' => $id,
        'label' => (string) (is_array($meta) ? ($meta['label'] ?? $id) : $id),
        'model' => $this->aiGateway->getModelForProvider($id),
        'key_configured' => $this->aiGateway->hasApiKey($id),
        'key_source' => $this->aiGateway->getApiKeySource($id),
        'default' => $id === $default,
      ];
    }
    return self::buildReport($rows, $this->usageTracker->summary());
  }

  /**
   * Pure shaper: provider rows + quota summary → model / key / quota triple.
   *
   * No Drupal, no container, no I/O — this is the part the offline harness
   * (tests/pure-assertions.php) exercises directly. Keys are never included;
   * only whether one is configured and where it comes from (site|environment|
   * none). With no key present the report stays valid JSON and names the
   * missing providers in `missing` + `hint`.
   *
   * @param list<array<string, mixed>> $rows
   *   One row per provider: id, label, model, key_configured, key_source,
   *   default.
   * @param array<string, mixed> $quota
   *   A UsageTracker::summary() shape: period, quota, tokens_used, remaining,
   *   calls, ok_calls.
   *
   * @return array<string, mixed>
   *   The report: default_provider, ready, ready_count, provider_count,
   *   models{id:model}, keys{id:{configured,source}}, quota{...},
   *   providers[list], missing[list], hint.
   */
  public static function buildReport(array $rows, array $quota): array {
    $providers = [];
    $models = [];
    $keys = [];
    $missing = [];
    $readyCount = 0;
    $defaultProvider = '';
    $defaultReady = FALSE;

    foreach ($rows as $row) {
      $id = (string) ($row['id'] ?? '');
      if ($id === '') {
        continue;
      }
      $configured = (bool) ($row['key_configured'] ?? FALSE);
      $source = (string) ($row['key_source'] ?? ($configured ? 'unknown' : 'none'));
      $model = (string) ($row['model'] ?? '');
      $isDefault = (bool) ($row['default'] ?? FALSE);

      if ($configured) {
        $readyCount++;
      }
      else {
        $missing[] = $id;
      }
      if ($isDefault) {
        $defaultProvider = $id;
        $defaultReady = $configured;
      }

      $providers[] = [
        'id' => $id,
        'label' => (string) ($row['label'] ?? $id),
        'model' => $model,
        'key_configured' => $configured,
        'key_source' => $source,
        'default' => $isDefault,
      ];
      $models[$id] = $model;
      $keys[$id] = ['configured' => $configured, 'source' => $source];
    }

    $remaining = (int) ($quota['remaining'] ?? 0);
    $quotaOk = $remaining > 0;
    // Ready means: the default provider has a usable key AND quota remains.
    $ready = $defaultReady && $quotaOk;

    $hints = [];
    if ($missing !== []) {
      $hints[] = 'missing API key(s): ' . implode(', ', $missing)
        . ' — set DX_AI_{PROVIDER}_KEY or configure at /admin/dx/ai';
    }
    if (!$quotaOk) {
      $hints[] = 'monthly quota exhausted (remaining=0)';
    }
    if ($defaultProvider === '') {
      $hints[] = 'no default provider resolved';
    }

    return [
      'default_provider' => $defaultProvider,
      'ready' => $ready,
      'ready_count' => $readyCount,
      'provider_count' => count($providers),
      'models' => $models,
      'keys' => $keys,
      'quota' => [
        'period' => (string) ($quota['period'] ?? ''),
        'quota' => (int) ($quota['quota'] ?? 0),
        'tokens_used' => (int) ($quota['tokens_used'] ?? 0),
        'remaining' => $remaining,
        'calls' => (int) ($quota['calls'] ?? 0),
        'ok_calls' => (int) ($quota['ok_calls'] ?? 0),
      ],
      'providers' => $providers,
      'missing' => $missing,
      'hint' => $hints === [] ? 'ok' : implode('; ', $hints),
    ];
  }

}
