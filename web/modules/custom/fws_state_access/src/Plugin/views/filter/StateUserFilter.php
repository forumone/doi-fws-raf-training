<?php

namespace Drupal\fws_state_access\Plugin\views\filter;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\Entity\User;
use Drupal\views\Plugin\views\filter\FilterPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Filter handler for filtering users by the current user's state.
 *
 * @ingroup views_filter_handlers
 *
 * @ViewsFilter("state_user_filter")
 */
class StateUserFilter extends FilterPluginBase implements ContainerFactoryPluginInterface {

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
   * Constructs a StateUserFilter object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, AccountInterface $current_user, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->currentUser = $current_user;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_user'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function adminSummary() {
    return $this->t('Filtered by current user state');
  }

  /**
   * {@inheritdoc}
   */
  protected function valueForm(&$form, FormStateInterface $form_state) {
    // No options to select - this filter automatically uses the current user's state.
    $form['value'] = [
      '#type' => 'value',
      '#value' => TRUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    // Only apply the filter if the user has the 'create state based user' permission.
    if (!$this->currentUser->hasPermission('create state based user')) {
      return;
    }

    // Load the current user entity.
    $user = User::load($this->currentUser->id());

    // Check if the user has a state code.
    if ($user->hasField('field_state_cd') && !$user->get('field_state_cd')->isEmpty()) {
      $state_term_id = $user->get('field_state_cd')->target_id;

      // Ensure the table for the field_state_cd field is added to the query.
      $field_state_cd_table = $this->query->ensureTable('user__field_state_cd', $this->relationship);

      // Add the condition to the query.
      $this->query->addWhere($this->options['group'], "$field_state_cd_table.field_state_cd_target_id", $state_term_id, '=');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function canExpose() {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);
    // Remove the expose button.
    unset($form['expose_button']);
  }

}
