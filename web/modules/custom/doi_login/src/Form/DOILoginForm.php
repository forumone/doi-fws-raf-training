<?php

namespace Drupal\doi_login\Form;

use Drupal\user\UserFloodControlInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\user\UserAuthInterface;
use Drupal\user\UserStorageInterface;
use Drupal\Core\Render\BareHtmlPageRendererInterface;
use Drupal\user\Form\UserLoginForm;


/**
 * Provides an alternate user login form so an admin can login without using SSO.
 *
 * @internal
 */
class DOILoginForm extends UserLoginForm {

   /**
   * Constructs a new UserLoginForm.
   *
   * @param \Drupal\Core\Flood\FloodInterface $flood
   *   The flood service.
   * @param \Drupal\user\UserStorageInterface $user_storage
   *   The user storage.
   * @param \Drupal\user\UserAuthInterface $user_auth
   *   The user authentication object.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer.
   */
  public function __construct(UserFloodControlInterface $user_flood_control, UserStorageInterface $user_storage, UserAuthInterface $user_auth, RendererInterface $renderer, BareHtmlPageRendererInterface $bare_html_renderer) {

    parent::__construct($user_flood_control, $user_storage, $user_auth, $renderer, $bare_html_renderer);
  }

   /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'doi_login_form';
  }
}