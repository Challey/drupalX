<?php

declare(strict_types=1);

namespace Drupal\dx_delivery\Service;

/**
 * Builds delivery blueprints from wizard fields or natural language.
 */
final class BlueprintFactory {

  /**
   * Normalize wizard input into a blueprint payload + entity field map.
   *
   * @param array<string, mixed> $input
   *
   * @return array{fields: array<string, mixed>, payload: array<string, mixed>}
   */
  public function fromWizard(array $input): array {
    $siteType = ($input['site_type'] ?? 'government') === 'enterprise' ? 'enterprise' : 'government';
    $machine = $this->sanitizeMachine((string) ($input['machine_name'] ?? ''));
    $label = trim((string) ($input['label'] ?? $machine));
    $theme = (string) ($input['theme_pack'] ?? ($siteType === 'enterprise' ? 'ent_trust' : 'gov_steady'));
    $layout = (string) ($input['layout_profile'] ?? ($siteType === 'enterprise' ? 'ent_default' : 'gov_default'));
    $channels = array_values(array_filter((array) ($input['channels'] ?? ['web'])));
    if (!in_array('web', $channels, TRUE)) {
      array_unshift($channels, 'web');
    }
    $capabilities = array_values(array_filter((array) ($input['capabilities'] ?? [])));
    $migrate = (string) ($input['migrate_level'] ?? 'none');
    $source = trim((string) ($input['source_url'] ?? ''));
    $mail = trim((string) ($input['owner_mail'] ?? ''));

    $payload = [
      'spec' => 'DX-DELIVERY-BLUEPRINT',
      'spec_version' => '1.0',
      'site_type' => $siteType,
      'tenant' => [
        'machine_name' => $machine,
        'label' => $label,
        'owner_mail' => $mail,
      ],
      'theme_pack' => $theme,
      'layout_profile' => $layout,
      'channels' => $channels,
      'capabilities' => $capabilities,
      'migrate' => [
        'level' => $migrate,
        'source_url' => $source !== '' ? $source : NULL,
      ],
      'intake' => 'wizard',
    ];

    return [
      'fields' => [
        'label' => $label,
        'machine_name' => $machine,
        'site_type' => $siteType,
        'theme_pack' => $theme,
        'layout_profile' => $layout,
        'owner_mail' => $mail,
        'source_url' => $source,
        'migrate_level' => $migrate,
        'channels' => json_encode($channels, JSON_UNESCAPED_UNICODE),
        'capabilities' => json_encode($capabilities, JSON_UNESCAPED_UNICODE),
        'status' => 'draft',
      ],
      'payload' => $payload,
    ];
  }

  /**
   * Parse natural language into wizard-like input (heuristic + optional AI).
   *
   * @return array{fields: array<string, mixed>, payload: array<string, mixed>, notes: string}
   */
  public function fromChat(string $message, array $overrides = []): array {
    $message = trim($message);
    $notes = [];
    $input = [
      'site_type' => 'government',
      'machine_name' => 'portal' . substr(md5($message), 0, 6),
      'label' => '交钥匙门户',
      'theme_pack' => 'gov_steady',
      'layout_profile' => 'gov_default',
      'channels' => ['web'],
      'capabilities' => [],
      'migrate_level' => 'none',
      'source_url' => '',
      'owner_mail' => '',
    ];

    if (preg_match('/企业|公司|品牌|enterprise|company/iu', $message)) {
      $input['site_type'] = 'enterprise';
      $input['theme_pack'] = 'ent_trust';
      $input['layout_profile'] = 'ent_default';
      $notes[] = '检测到企业门户意向';
    }
    if (preg_match('/政府|政务|机关|事业单位|gov(ernment)?|portal/iu', $message)) {
      $input['site_type'] = 'government';
      $input['theme_pack'] = 'gov_steady';
      $input['layout_profile'] = 'gov_default';
      $notes[] = '检测到政务门户意向';
    }
    if (preg_match('/商城|电商|购物|commerce|mall/iu', $message)) {
      $input['capabilities'][] = 'commerce';
    }
    if (preg_match('/舆情|opinion/iu', $message)) {
      $input['capabilities'][] = 'opinion';
    }
    if (preg_match('/小程序|miniprogram|mini.?program/iu', $message)) {
      $input['channels'][] = 'miniprogram';
    }
    if (preg_match('/安卓|Android|iOS|苹果|App/iu', $message)) {
      $input['channels'][] = 'app';
    }
    if (preg_match('/沉稳|steady/iu', $message)) {
      $input['theme_pack'] = 'gov_steady';
    }
    if (preg_match('/名叫[「"“]?([^」"”\s]+)[」"”]?|叫做[「"“]?([^」"”\s]+)/u', $message, $m)) {
      $name = $m[1] !== '' ? $m[1] : ($m[2] ?? '');
      if ($name !== '') {
        $input['label'] = $name;
        $input['machine_name'] = $this->sanitizeMachine($name);
      }
    }

    // Optional AI refinement when gateway present.
    if (\Drupal::hasService('dx_ai_gateway.gateway') && mb_strlen($message) > 8) {
      try {
        /** @var \Drupal\dx_ai_gateway\Service\AiGateway $ai */
        $ai = \Drupal::service('dx_ai_gateway.gateway');
        $prompt = "你是 DrupalX 交钥匙助手。根据用户需求，只输出一行 JSON："
          . '{"site_type":"government|enterprise","label":"...","machine_name":"slug","theme_pack":"gov_steady|ent_trust|...","channels":["web","miniprogram","app"],"capabilities":["commerce","opinion"],"migrate_level":"none|l1|l2|l3","source_url":""}'
          . "\n用户：" . $message;
        $result = $ai->chat($prompt);
        $content = (string) ($result['content'] ?? '');
        if (preg_match('/\{.*\}/s', $content, $jm)) {
          $decoded = json_decode($jm[0], TRUE);
          if (is_array($decoded)) {
            $input = array_merge($input, array_intersect_key($decoded, $input));
            $notes[] = '已用 AI 网关辅助解析';
          }
        }
      }
      catch (\Throwable $e) {
        $notes[] = 'AI 解析跳过：' . $e->getMessage();
      }
    }

    $input = array_merge($input, $overrides);
    $input['channels'] = array_values(array_unique(array_map('strval', (array) $input['channels'])));
    $input['capabilities'] = array_values(array_unique(array_map('strval', (array) $input['capabilities'])));
    $built = $this->fromWizard($input);
    $built['payload']['intake'] = 'chat';
    $built['payload']['notes'] = $notes;
    $built['notes'] = implode('；', $notes);
    return $built;
  }

  public function sanitizeMachine(string $raw): string {
    $raw = strtolower(trim($raw));
    $raw = preg_replace('/[^a-z0-9_]+/', '_', $raw) ?: 'portal';
    $raw = trim($raw, '_');
    if ($raw === '' || !preg_match('/^[a-z]/', $raw)) {
      $raw = 't' . $raw;
    }
    return substr($raw, 0, 32);
  }

}
