<?php

declare(strict_types=1);

namespace Drupal\dx_appstore;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Shared access control for App Store entities.
 */
class AppStoreAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResult {
    if ($account->hasPermission('administer dx appstore')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    if (in_array($operation, ['view', 'view label'], TRUE)) {
      return AccessResult::allowedIfHasPermission($account, 'browse dx appstore');
    }

    return AccessResult::neutral();
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResult {
    return AccessResult::allowedIfHasPermission($account, 'administer dx appstore');
  }

}
