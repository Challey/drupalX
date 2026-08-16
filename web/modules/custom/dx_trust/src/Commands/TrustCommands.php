<?php

declare(strict_types=1);

namespace Drupal\dx_trust\Commands;

use Drupal\dx_trust\Service\TrustPolicy;
use Drush\Commands\DrushCommands;

/**
 * Drush for trust policy.
 */
final class TrustCommands extends DrushCommands {

  public function __construct(
    private readonly TrustPolicy $policy,
  ) {
    parent::__construct();
  }

  /**
   * Show active trust policy.
   *
   * @command dx:trust-status
   */
  public function status(): void {
    $this->io()->writeln(json_encode($this->policy->settings(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  }

  /**
   * Apply government or enterprise defaults.
   *
   * @command dx:trust-apply
   * @param string $profile government|enterprise
   */
  public function apply(string $profile = 'government'): void {
    $result = str_starts_with(strtolower($profile), 'ent')
      ? $this->policy->applyEnterpriseDefaults()
      : $this->policy->applyGovernmentDefaults();
    $this->io()->writeln(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  }

  /**
   * Evaluate a trust_level against policy.
   *
   * @command dx:trust-check
   * @param string $trustLevel e.g. community
   */
  public function check(string $trustLevel): void {
    $this->io()->writeln(json_encode($this->policy->evaluate($trustLevel), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  }

}
