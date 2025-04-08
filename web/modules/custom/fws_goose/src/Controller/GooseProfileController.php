<?php

namespace Drupal\fws_goose\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for RCGR profile-related routes.
 */
class GooseProfileController extends ControllerBase {

  /**
   * Redirects the user to their edit page.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect response to the user edit page.
   */
  public function redirectToUserEdit() {
    // Get the current user's ID.
    $user_id = $this->currentUser()->id();

    // Create a URL to the user edit form.
    $url = Url::fromRoute('entity.user.edit_form', ['user' => $user_id])->toString();

    // Return a redirect response.
    return new RedirectResponse($url);
  }

}
