<?php

namespace Drupal\fws_goose\Plugin\Block;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\node\NodeInterface;

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
    $node = $this->routeMatch->getParameter('node');

    // Only show on permit node pages.
    if (!($node instanceof NodeInterface) || $node->bundle() !== 'permit') {
      return [];
    }

    // Check if the permit node is already certified.
    // Use field_applicant_signed on the node.
    if ($node->hasField('field_applicant_signed') && $node->get('field_applicant_signed')->value) {
      $build = [
        '#type' => 'container',
        '#attributes' => ['class' => ['alert', 'alert-success']],
        'content' => [
          '#markup' => '<i class="fa fa-check-circle"></i> ' . $this->t('This permit has been certified.'),
        ],
      ];
      // Add certified date if available.
      if ($node->hasField('field_dt_applicant_signed') && !$node->get('field_dt_applicant_signed')->isEmpty()) {
        // Get the timestamp directly from the DateTime object.
        $date_object = $node->get('field_dt_applicant_signed')->date;
        if ($date_object) {
          $timestamp = $date_object->getTimestamp();
          $formatted_date = \Drupal::service('date.formatter')->format($timestamp, 'medium');
          $build['content']['#markup'] .= '<br />' . $this->t('Certified on: @date', ['@date' => $formatted_date]);
        }
      }
      return $build;
    }

    // Build and return the certification form, passing the NODE ID.
    $form = $this->formBuilder->getForm('\Drupal\fws_goose\Form\InlineCertificationForm', $node->id());

    return [
      'form' => $form,
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account) {
    $node = $this->routeMatch->getParameter('node');

    // Only allow access on permit node routes.
    if (!($node instanceof NodeInterface) || $node->bundle() !== 'permit') {
      return AccessResult::forbidden();
    }

    // Allow access if the current user is the owner of the permit node or has admin permissions.
    if ($node->getOwnerId() == $account->id() || $account->hasPermission('administer nodes')) {
      return AccessResult::allowed();
    }

    return AccessResult::forbidden();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    // Vary cache by the node and user permissions.
    return ['route', 'user.permissions'];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    $node = $this->routeMatch->getParameter('node');
    if ($node instanceof NodeInterface) {
      // Invalidate cache if the node changes.
      return ['node:' . $node->id()];
    }
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    // Set cache max age to 0 if the node is not certified, allow caching if certified.
    $node = $this->routeMatch->getParameter('node');
    if ($node instanceof NodeInterface && $node->hasField('field_applicant_signed') && $node->get('field_applicant_signed')->value) {
      // Cache permanently if certified.
      return Cache::PERMANENT;
    }
    // No cache if not certified (form needs to be fresh)
    return 0;
  }

}
