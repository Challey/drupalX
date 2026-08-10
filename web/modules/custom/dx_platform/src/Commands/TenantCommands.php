<?php

declare(strict_types=1);

namespace Drupal\dx_platform\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\dx_platform\Entity\Tenant;
use Drupal\dx_platform\Service\TenantProvisioner;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for tenant management.
 */
class TenantCommands extends DrushCommands {

  /**
   * Constructs TenantCommands.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TenantProvisioner $tenantProvisioner,
  ) {
    parent::__construct();
  }

  /**
   * Provision a tenant by machine name.
   *
   * @param string $machine_name
   *   The tenant machine name.
   *
   * @option label
   *   Human-readable tenant label.
   * @option mail
   *   Owner email address.
   *
   * @command dx:tenant-provision
   * @aliases dx-tp,dcn-tp
   * @usage drush dx:tenant-provision acme --label="Acme Corp" --mail=owner@acme.com
   */
  public function tenantProvision(string $machine_name, array $options = ['label' => NULL, 'mail' => NULL]): void {
    $storage = $this->entityTypeManager->getStorage('dx_tenant');
    $existing = $storage->loadByProperties(['machine_name' => $machine_name]);

    if ($existing) {
      $tenant = reset($existing);
      $this->logger()->notice('Using existing tenant record for @machine.', ['@machine' => $machine_name]);
    }
    else {
      $label = $options['label'] ?: $machine_name;
      $tenant = Tenant::create([
        'machine_name' => $machine_name,
        'label' => $label,
        'owner_mail' => $options['mail'] ?: (getenv('DX_ADMIN_MAIL') ?: 'admin@drupalx.local'),
        'status' => 'draft',
      ]);
      $tenant->save();
      $this->logger()->success('Created tenant record @label.', ['@label' => $label]);
    }

    /** @var \Drupal\dx_platform\Entity\Tenant $tenant */
    $this->tenantProvisioner->provision($tenant);
    $this->logger()->success('Tenant @machine provisioned with status @status.', [
      '@machine' => $machine_name,
      '@status' => $tenant->getStatus(),
    ]);
  }

  /**
   * List all tenants.
   *
   * @command dx:tenant-list
   * @aliases dx-tl,dcn-tl
   * @field-labels
   *   id: ID
   *   label: Label
   *   machine_name: Machine name
   *   status: Status
   *   subdomain: Subdomain
   * @default-fields id,label,machine_name,status,subdomain
   */
  public function tenantList(): void {
    $storage = $this->entityTypeManager->getStorage('dx_tenant');
    $tenants = $storage->loadMultiple();

    $rows = [];
    foreach ($tenants as $tenant) {
      /** @var \Drupal\dx_platform\Entity\Tenant $tenant */
      $rows[] = [
        'id' => $tenant->id(),
        'label' => $tenant->label(),
        'machine_name' => $tenant->getMachineName(),
        'status' => $tenant->getStatus(),
        'subdomain' => $tenant->get('subdomain')->value,
      ];
    }

    if ($rows === []) {
      $this->logger()->warning('No tenants found.');
      return;
    }

    $this->io()->table(['ID', 'Label', 'Machine name', 'Status', 'Subdomain'], $rows);
  }

}
