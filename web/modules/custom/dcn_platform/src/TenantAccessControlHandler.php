<?php

declare(strict_types=1);

namespace Drupal\dcn_platform;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access controller for tenant entities.
 */
class TenantAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResult {
    if ($account->hasPermission('administer dcn tenants')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    if ($operation === 'provision') {
      return AccessResult::allowedIfHasPermission($account, 'administer dcn tenants');
    }

    return AccessResult::neutral();
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResult {
    return AccessResult::allowedIfHasPermission($account, 'administer dcn tenants');
  }

}
