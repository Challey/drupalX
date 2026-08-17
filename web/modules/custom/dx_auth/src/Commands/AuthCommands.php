<?php

declare(strict_types=1);

namespace Drupal\dx_auth\Commands;

use Drupal\dx_auth\Service\EnterpriseAccountLinker;
use Drupal\dx_auth\Service\EnterpriseIdentityService;
use Drush\Commands\DrushCommands;

/**
 * Drush helpers for enterprise credit ID bindings.
 */
class AuthCommands extends DrushCommands {

  public function __construct(
    protected EnterpriseIdentityService $identity,
    protected EnterpriseAccountLinker $linker,
  ) {
    parent::__construct();
  }

  /**
   * Bind an enterprise credit ID to a Drupal user.
   *
   * @command dx:auth-bind
   * @param string $credit_code
   *   Unified social credit code (18 chars).
   * @param int $uid
   *   Drupal user id.
   * @param string $company_name
   *   Optional company display name.
   * @usage drush dx:auth-bind 91110000MA0123456P 2 "示例科技"
   */
  public function bind(string $credit_code, int $uid, string $company_name = ''): void {
    $normalized = $this->identity->normalize($credit_code);
    if (!$this->identity->validate($normalized)) {
      throw new \InvalidArgumentException('Invalid unified social credit code.');
    }
    if (!$this->linker->bind($normalized, $uid, $company_name)) {
      throw new \RuntimeException('Bind failed.');
    }
    $this->logger()->success(sprintf(
      'Bound %s → uid %d',
      $this->identity->mask($normalized),
      $uid,
    ));
  }

  /**
   * List enterprise credit ID bindings.
   *
   * @command dx:auth-list
   * @usage drush dx:auth-list
   */
  public function listBindings(): void {
    $rows = $this->linker->listBindings();
    if (!$rows) {
      $this->io()->writeln('No enterprise bindings.');
      return;
    }
    $table = [];
    foreach ($rows as $row) {
      $table[] = [
        $row['id'],
        $this->identity->mask($row['credit_code']),
        $row['uid'],
        $row['company_name'],
        $row['changed'] ? date('Y-m-d H:i', $row['changed']) : '',
      ];
    }
    $this->io()->table(['ID', 'Credit ID', 'UID', 'Company', 'Updated'], $table);
  }

  /**
   * Remove an enterprise binding by row id.
   *
   * @command dx:auth-unbind
   * @param int $id
   *   Binding row id from dx:auth-list.
   * @usage drush dx:auth-unbind 3
   */
  public function unbind(int $id): void {
    if (!$this->linker->unbind($id)) {
      throw new \RuntimeException('Unbind failed (id not found).');
    }
    $this->logger()->success(sprintf('Removed binding id %d', $id));
  }

  /**
   * Validate / normalize a credit code (GB 32100 checksum).
   *
   * @command dx:auth-validate
   * @param string $credit_code
   *   Raw credit code.
   * @usage drush dx:auth-validate 91110000MA0123456P
   */
  public function validate(string $credit_code): void {
    $normalized = $this->identity->normalize($credit_code);
    $ok = $this->identity->validate($normalized);
    $this->io()->writeln(json_encode([
      'input' => $credit_code,
      'normalized' => $normalized,
      'valid' => $ok,
      'masked' => $ok ? $this->identity->mask($normalized) : NULL,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  }

}
