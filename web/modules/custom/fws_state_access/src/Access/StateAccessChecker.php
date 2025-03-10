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
    if (!$account->hasPermission('administer state based access')) {
      return AccessResult::neutral();
    }

    $user = $this->entityTypeManager->getStorage('user')->load($account->id());
    if (!$user->hasField('field_state_cd') || $user->get('field_state_cd')->isEmpty()) {
      return AccessResult::forbidden();
    }

    return AccessResult::allowed();
  }

}
