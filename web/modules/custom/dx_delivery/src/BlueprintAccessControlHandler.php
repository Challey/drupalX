<?php

declare(strict_types=1);

namespace Drupal\dx_delivery;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access controller for delivery blueprint entities.
 */
class BlueprintAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResult {
    if ($account->hasPermission('administer dx delivery')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    if (in_array($operation, ['view', 'execute'], TRUE)) {
      return AccessResult::allowedIfHasPermission($account, 'administer dx delivery');
    }

    return AccessResult::neutral();
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResult {
    return AccessResult::allowedIfHasPermission($account, 'administer dx delivery');
  }

}
