<?php

declare(strict_types=1);

namespace Drupal\dx_trust\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Evaluates App Store / capability trust against the active policy profile.
 */
final class TrustPolicy {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * @return array<string, mixed>
   */
  public function settings(): array {
    $c = $this->configFactory->get('dx_trust.settings');
    return [
      'profile' => (string) ($c->get('profile') ?: 'government_default'),
      'allowed_trust_tiers' => array_values(array_map('strval', $c->get('allowed_trust_tiers') ?? [])),
      'block_community' => (bool) $c->get('block_community'),
      'require_manual_approve_community' => (bool) $c->get('require_manual_approve_community'),
      'default_visibility' => (string) ($c->get('default_visibility') ?: 'public'),
      'require_content_review' => (bool) $c->get('require_content_review'),
      'audit_retention_days' => (int) ($c->get('audit_retention_days') ?: 365),
      'notes' => (string) ($c->get('notes') ?: ''),
    ];
  }

  /**
   * Apply government defaults (idempotent config write).
   *
   * @return array{id: string, ok: bool, message: string, policy: array<string, mixed>}
   */
  public function applyGovernmentDefaults(): array {
    $editable = $this->configFactory->getEditable('dx_trust.settings');
    $editable
      ->set('profile', 'government_default')
      ->set('allowed_trust_tiers', ['platform', 'security', 'curated', 'demo'])
      ->set('block_community', TRUE)
      ->set('require_manual_approve_community', TRUE)
      ->set('default_visibility', 'public')
      ->set('require_content_review', TRUE)
      ->set('audit_retention_days', 365)
      ->set('notes', '政务租户默认仅允许 platform/security/curated/demo；community 须人工批准。')
      ->save();
    $policy = $this->settings();
    return [
      'id' => 'trust_policy',
      'ok' => TRUE,
      'message' => 'Applied government_default trust policy',
      'policy' => $policy,
    ];
  }

  /**
   * Apply enterprise defaults (allows stable + community with manual approve).
   *
   * @return array{id: string, ok: bool, message: string, policy: array<string, mixed>}
   */
  public function applyEnterpriseDefaults(): array {
    $editable = $this->configFactory->getEditable('dx_trust.settings');
    $editable
      ->set('profile', 'enterprise_default')
      ->set('allowed_trust_tiers', ['platform', 'security', 'curated', 'stable', 'demo', 'community'])
      ->set('block_community', FALSE)
      ->set('require_manual_approve_community', TRUE)
      ->set('default_visibility', 'public')
      ->set('require_content_review', FALSE)
      ->set('audit_retention_days', 180)
      ->set('notes', '企业默认允许 stable；community 需人工批准但可不整级阻断。')
      ->save();
    return [
      'id' => 'trust_policy',
      'ok' => TRUE,
      'message' => 'Applied enterprise_default trust policy',
      'policy' => $this->settings(),
    ];
  }

  /**
   * Whether a trust_level is allowed for auto-install.
   *
   * @return array{allowed: bool, reason: string}
   */
  public function evaluate(string $trustLevel): array {
    $trustLevel = strtolower(trim($trustLevel));
    $s = $this->settings();
    $allowed = $s['allowed_trust_tiers'];
    if ($trustLevel === 'community' && !empty($s['block_community'])) {
      return [
        'allowed' => FALSE,
        'reason' => 'community blocked by trust profile ' . $s['profile'],
      ];
    }
    if ($allowed !== [] && !in_array($trustLevel, $allowed, TRUE)) {
      return [
        'allowed' => FALSE,
        'reason' => 'trust_level ' . $trustLevel . ' not in allowed tiers [' . implode(',', $allowed) . ']',
      ];
    }
    if ($trustLevel === 'community' && !empty($s['require_manual_approve_community'])) {
      return [
        'allowed' => FALSE,
        'reason' => 'community requires manual approve',
      ];
    }
    return ['allowed' => TRUE, 'reason' => 'ok'];
  }

}
