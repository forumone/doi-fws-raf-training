<?php

namespace Drupal\fws_goose\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

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

  /**
   * Updates the certification status of a user.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect response to the user profile page.
   */
  public function updateCertification(Request $request) {
    // Get the POST data and log it for debugging.
    $post_data = $request->request->all();
    \Drupal::logger('fws_goose')->notice('Certification form submission: @data', ['@data' => print_r($post_data, TRUE)]);

    // Simple sanity check for required fields.
    if (empty($post_data['user_id'])) {
      $this->messenger()->addError($this->t('Missing user ID in the request.'));
      return $this->redirectToProfile();
    }

    $user_id = (int) $post_data['user_id'];

    // Check if the user_id matches the current user or the user has admin privileges.
    if ($user_id != $this->currentUser()->id() && !$this->currentUser()->hasPermission('administer users')) {
      $this->messenger()->addError($this->t('You do not have permission to update this certification.'));
      return $this->redirectToProfile();
    }

    // Load the user account.
    $user = User::load($user_id);
    if (!$user) {
      $this->messenger()->addError($this->t('User not found.'));
      return $this->redirectToProfile();
    }

    // Check if user has the certification field.
    if (!$user->hasField('field_applicant_agree_to_certify')) {
      $this->messenger()->addError($this->t('Certification field not found on user.'));
      return $this->redirectToProfile();
    }

    // Check if the certify checkbox was checked (will be set if the checkbox was checked)
    if (!isset($post_data['certify']) || empty($post_data['certify'])) {
      $this->messenger()->addError($this->t('You must check the certification box to complete your registration.'));
      return $this->redirectToProfile();
    }

    // All validations passed, update the certification field.
    $user->set('field_applicant_agree_to_certify', TRUE);
    $user->save();

    // Add a success message.
    $this->messenger()->addStatus($this->t('Your certification has been recorded. Your registration is now complete.'));

    // Redirect to the user profile page.
    return $this->redirectToProfile();
  }

  /**
   * Helper to redirect to the current user's profile page.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect response.
   */
  protected function redirectToProfile() {
    $url = Url::fromRoute('entity.user.canonical', ['user' => $this->currentUser()->id()])->toString();
    return new RedirectResponse($url);
  }

}
