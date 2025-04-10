<?php

namespace Drupal\fws_goose\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Access\AccessResult;

/**
 * Provides a certification form block.
 *
 * @Block(
 *   id = "certification_form_block",
 *   admin_label = @Translation("Certification Form Block"),
 *   category = @Translation("RCGR")
 * )
 */
class CertificationBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The form builder.
   *
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected $formBuilder;

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * Constructs a new CertificationBlock.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Form\FormBuilderInterface $form_builder
   *   The form builder.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, FormBuilderInterface $form_builder, RouteMatchInterface $route_match, AccountInterface $current_user) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->formBuilder = $form_builder;
    $this->routeMatch = $route_match;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('form_builder'),
      $container->get('current_route_match'),
      $container->get('current_user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    // Only show content on user canonical routes.
    if ($this->routeMatch->getRouteName() !== 'entity.user.canonical') {
      return [];
    }

    $user = $this->routeMatch->getParameter('user');
    if (!$user) {
      return [];
    }

    // Check if the user already has certified.
    if ($user->hasField('field_applicant_agree_to_certify') &&
        $user->get('field_applicant_agree_to_certify')->value) {
      return [
        '#type' => 'container',
        '#attributes' => ['class' => ['alert', 'alert-info']],
        'content' => [
          '#markup' => '<i class="fa fa-check-circle"></i> You have certified this registration.',
        ],
      ];
    }

    // Build and return the certification form.
    $form = $this->formBuilder->getForm('Drupal\fws_goose\Form\InlineCertificationForm', $user->id());

    return [
      'form' => $form,
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account) {
    // Only allow access on user canonical routes.
    if ($this->routeMatch->getRouteName() !== 'entity.user.canonical') {
      return AccessResult::forbidden();
    }

    $user = $this->routeMatch->getParameter('user');
    if (!$user) {
      return AccessResult::forbidden();
    }

    // If viewing someone else's profile and not an admin.
    if ($user->id() != $account->id() && !$account->hasPermission('administer users')) {
      return AccessResult::forbidden();
    }

    return AccessResult::allowed();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    // Disable caching for this block.
    return 0;
  }

}
