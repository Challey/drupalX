<?php

declare(strict_types=1);

namespace Drupal\dx_opinion\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Resolves opinion feed items from demo or licensed data sources.
 */
final class OpinionFeed {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * @return array{mode: string, notice: string, items: list<array<string, mixed>>, licensed_ok: bool}
   */
  public function load(): array {
    $c = $this->configFactory->get('dx_opinion.settings');
    $mode = (string) ($c->get('data_source_mode') ?: 'demo');
    $notice = (string) ($c->get('compliance_notice') ?: '');
    if ($mode === 'licensed') {
      $endpoint = trim((string) ($c->get('licensed_endpoint') ?: ''));
      $token = trim((string) ($c->get('licensed_token') ?: ''));
      if ($endpoint === '') {
        return [
          'mode' => 'licensed',
          'notice' => $notice . '（未配置 licensed_endpoint，已回退演示数据）',
          'items' => array_values($c->get('demo_items') ?? []),
          'licensed_ok' => FALSE,
        ];
      }
      $items = $this->fetchLicensed($endpoint, $token);
      if ($items === []) {
        return [
          'mode' => 'licensed',
          'notice' => $notice . '（授权源暂无数据或不可达，已回退演示数据）',
          'items' => array_values($c->get('demo_items') ?? []),
          'licensed_ok' => FALSE,
        ];
      }
      return [
        'mode' => 'licensed',
        'notice' => $notice,
        'items' => $items,
        'licensed_ok' => TRUE,
      ];
    }
    return [
      'mode' => 'demo',
      'notice' => $notice !== '' ? $notice : '演示数据 · 非全网抓取',
      'items' => array_values($c->get('demo_items') ?? []),
      'licensed_ok' => FALSE,
    ];
  }

  /**
   * @return list<array<string, mixed>>
   */
  protected function fetchLicensed(string $endpoint, string $token): array {
    // Local fixture sink for smoke / offline.
    if (str_contains($endpoint, 'fixture://') || str_contains($endpoint, 'example.com')) {
      return [
        [
          'title' => '授权源：营商环境周报',
          'source' => 'Licensed Provider',
          'sentiment' => 'neutral',
          'url' => 'https://example.com/licensed/1',
        ],
        [
          'title' => '授权源：政务服务满意度抽样',
          'source' => 'Licensed Provider',
          'sentiment' => 'positive',
          'url' => 'https://example.com/licensed/2',
        ],
      ];
    }
    try {
      $headers = "Accept: application/json\r\nUser-Agent: DrupalX-dx_opinion/1.0\r\n";
      if ($token !== '') {
        $headers .= 'Authorization: Bearer ' . $token . "\r\n";
      }
      $ctx = stream_context_create(['http' => ['timeout' => 10, 'header' => $headers]]);
      $raw = @file_get_contents($endpoint, FALSE, $ctx);
      if (!is_string($raw) || $raw === '') {
        return [];
      }
      $data = json_decode($raw, TRUE);
      if (!is_array($data)) {
        return [];
      }
      $list = $data['items'] ?? $data;
      if (!is_array($list)) {
        return [];
      }
      $out = [];
      foreach ($list as $row) {
        if (!is_array($row) || empty($row['title'])) {
          continue;
        }
        $out[] = [
          'title' => (string) $row['title'],
          'source' => (string) ($row['source'] ?? 'licensed'),
          'sentiment' => (string) ($row['sentiment'] ?? 'neutral'),
          'url' => (string) ($row['url'] ?? ''),
        ];
        if (count($out) >= 50) {
          break;
        }
      }
      return $out;
    }
    catch (\Throwable) {
      return [];
    }
  }

}
