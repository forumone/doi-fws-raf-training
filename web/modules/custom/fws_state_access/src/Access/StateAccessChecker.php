<?php

namespace Drupal\fws_state_access\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessCheckInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\Routing\Route;

/**
 * Checks access for state-based restrictions.
 */
class StateAccessChecker implements AccessCheckInterface {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a StateAccessChecker object.
   *
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(
    AccountInterface $current_user,
    EntityTypeManagerInterface $entity_type_manager,
  ) {
    $this->currentUser = $current_user;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function applies(Route $route) {
    return $route->hasRequirement('_state_access_check');
  }

  /**
   * Checks access based on user's state code.
   *
   * @param \Drupal\Core\Route\Route $route
   *   The route to check against.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(Route $route, AccountInterface $account) {
    if (!$account->hasPermission('access state based content')) {
      return AccessResult::neutral();
    }

    $user = $this->entityTypeManager->getStorage('user')->load($account->id());
    $user_state = fws_state_access_get_user_state($user);

    // Check if the route has a specific requirement for state access.
    $strict_check = $route->getRequirement('_state_access_check') === 'strict';

    if ($user_state === NULL && $strict_check) {
      return AccessResult::forbidden()
        ->addCacheContexts(['user'])
        ->addCacheTags(['user:' . $account->id()]);
    }

    return AccessResult::allowed()
      ->addCacheContexts(['user'])
      ->addCacheTags(['user:' . $account->id()]);
  }

}
