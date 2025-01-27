<?php
/**
* @file
* Contains \Drupal\doi_login\Routing\RouteSubscriber.
*/

namespace Drupal\doi_login\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Listens to the dynamic route events.
 */
class RouteSubscriber extends RouteSubscriberBase {

  /**
  * {@inheritdoc}
  */
  public function alterRoutes(RouteCollection $collection) {
    // Always deny access to '/user/logout'.
    // Note that the second parameter of setRequirement() is a string.
    if ($route = $collection->get('user.pass')) {
      $password_disable = \Drupal::config('doi_login.settings_form')->get('doi_login_password_disable');
      if ($password_disable || $password_disable === NULL) {
        $route->setRequirement('_access', 'FALSE');
      }
    }
  }
}
