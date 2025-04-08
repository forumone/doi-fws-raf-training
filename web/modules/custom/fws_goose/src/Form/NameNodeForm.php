<?php

namespace Drupal\fws_goose\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeForm;
use Drupal\user\Entity\User;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Form controller for the name node edit forms.
 */
class NameNodeForm extends NodeForm {

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Check if the current user has a permit number before building the form.
    $current_user = \Drupal::currentUser();
    if ($current_user->id() > 0) {
      $user = User::load($current_user->id());

      // If user has no permit number, redirect to location form.
      if (!$user || !$user->hasField('field_permit_no') || $user->get('field_permit_no')->isEmpty()) {
        // Set a message explaining why they're being redirected.
        \Drupal::messenger()->addWarning(t('You must create a location with a permit number before adding a name. Please complete this form first.'));

        // Redirect to the location add form.
        $url = Url::fromUri('internal:/node/add/location')->toString();
        $response = new RedirectResponse($url);
        $response->send();
        exit;
      }
    }

    // Build the form as usual.
    return parent::buildForm($form, $form_state);
  }

}
