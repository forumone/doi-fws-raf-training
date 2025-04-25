<?php

namespace Drupal\fws_goose\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for handling redirects related to user permits.
 */
class PermitRedirectController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The URL generator service.
   *
   * @var \Drupal\Core\Routing\UrlGeneratorInterface
   */
  protected $urlGenerator;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * Constructs a new PermitRedirectController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\Core\Routing\UrlGeneratorInterface $url_generator
   *   The URL generator service.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, MessengerInterface $messenger, UrlGeneratorInterface $url_generator, AccountInterface $current_user) {
    $this->entityTypeManager = $entity_type_manager;
    $this->messenger = $messenger;
    $this->urlGenerator = $url_generator;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('messenger'),
      $container->get('url_generator'),
      $container->get('current_user')
    );
  }

  /**
   * Redirects the user to their latest permit or the permit add form.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect response.
   */
  public function redirectUser() {
    $user_id = $this->currentUser->id();
    $node_storage = $this->entityTypeManager->getStorage('node');

    // Query for the latest 'permit' node created by the current user.
    // Assuming 'permit' is the machine name of the content type.
    $query = $node_storage->getQuery()
      ->condition('type', 'permit')
      ->condition('uid', $user_id)
      ->sort('created', 'DESC')
      ->accessCheck(TRUE)
      ->range(0, 1);

    $nids = $query->execute();

    if (!empty($nids)) {
      // User has a permit, redirect to the latest one.
      $latest_nid = reset($nids);
      $url = $this->urlGenerator->generate('entity.node.canonical', ['node' => $latest_nid]);
    }
    else {
      // User does not have a permit, redirect to the add form with a message.
      $this->messenger->addStatus($this->t('You need to register first by creating a permit.'));
      $url = $this->urlGenerator->generate('node.add', ['node_type' => 'permit']);
    }

    return new RedirectResponse($url);
  }

}
