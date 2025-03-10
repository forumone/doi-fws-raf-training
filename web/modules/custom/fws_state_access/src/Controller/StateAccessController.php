<?php

namespace Drupal\fws_state_access\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Controller for testing state access.
 */
class StateAccessController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * Constructs a StateAccessController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, RequestStack $request_stack) {
    $this->entityTypeManager = $entity_type_manager;
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('request_stack')
    );
  }

  /**
   * Tests access to a node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to test access for.
   *
   * @return array
   *   A render array.
   */
  public function testAccess(NodeInterface $node) {
    $account = $this->currentUser();
    $user = User::load($account->id());

    $build = [
      '#type' => 'markup',
      '#markup' => '<h2>State Access Test</h2>',
    ];

    // User information.
    $build['user_info'] = [
      '#type' => 'details',
      '#title' => $this->t('User Information'),
      '#open' => TRUE,
      'content' => [
        '#theme' => 'item_list',
        '#items' => [
          $this->t('User ID: @uid', ['@uid' => $account->id()]),
          $this->t('Username: @name', ['@name' => $account->getAccountName()]),
          $this->t('Roles: @roles', ['@roles' => implode(', ', $account->getRoles())]),
          $this->t('Has permission "administer state based access": @perm', ['@perm' => $account->hasPermission('administer state based access') ? 'Yes' : 'No']),
        ],
      ],
    ];

    // User state code.
    if ($user->hasField('field_state_cd') && !$user->get('field_state_cd')->isEmpty()) {
      $field_definition = $user->getFieldDefinition('field_state_cd');
      $field_type = $field_definition->getType();
      $field_settings = $field_definition->getSettings();

      $user_state_items = [
        $this->t('Field type: @type', ['@type' => $field_type]),
      ];

      if ($field_type === 'entity_reference') {
        $user_state_items[] = $this->t('Target type: @target_type', ['@target_type' => $field_settings['target_type']]);
        $user_state_items[] = $this->t('Target ID: @target_id', ['@target_id' => $user->get('field_state_cd')->target_id]);

        // Load the referenced entity if possible.
        $target_id = $user->get('field_state_cd')->target_id;
        $target_type = $field_settings['target_type'];
        if ($target_id && $target_type) {
          $target_entity = $this->entityTypeManager->getStorage($target_type)->load($target_id);
          if ($target_entity) {
            $user_state_items[] = $this->t('Referenced entity: @label (ID: @id)', [
              '@label' => $target_entity->label(),
              '@id' => $target_entity->id(),
            ]);
          }
        }
      }
      else {
        $user_state_items[] = $this->t('Value: @value', ['@value' => $user->get('field_state_cd')->value]);
      }

      $build['user_state'] = [
        '#type' => 'details',
        '#title' => $this->t('User State Code'),
        '#open' => TRUE,
        'content' => [
          '#theme' => 'item_list',
          '#items' => $user_state_items,
        ],
      ];
    }
    else {
      $build['user_state'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('User does not have a state code.') . '</p>',
      ];
    }

    // Node information.
    $build['node_info'] = [
      '#type' => 'details',
      '#title' => $this->t('Node Information'),
      '#open' => TRUE,
      'content' => [
        '#theme' => 'item_list',
        '#items' => [
          $this->t('Node ID: @nid', ['@nid' => $node->id()]),
          $this->t('Title: @title', ['@title' => $node->getTitle()]),
          $this->t('Type: @type', ['@type' => $node->bundle()]),
        ],
      ],
    ];

    // Node owner state.
    if ($node->hasField('field_owner_state') && !$node->get('field_owner_state')->isEmpty()) {
      $field_definition = $node->getFieldDefinition('field_owner_state');
      $field_type = $field_definition->getType();
      $field_settings = $field_definition->getSettings();

      $node_state_items = [
        $this->t('Field type: @type', ['@type' => $field_type]),
      ];

      if ($field_type === 'entity_reference') {
        $node_state_items[] = $this->t('Target type: @target_type', ['@target_type' => $field_settings['target_type']]);
        $node_state_items[] = $this->t('Target ID: @target_id', ['@target_id' => $node->get('field_owner_state')->target_id]);

        // Load the referenced entity if possible.
        $target_id = $node->get('field_owner_state')->target_id;
        $target_type = $field_settings['target_type'];
        if ($target_id && $target_type) {
          $target_entity = $this->entityTypeManager->getStorage($target_type)->load($target_id);
          if ($target_entity) {
            $node_state_items[] = $this->t('Referenced entity: @label (ID: @id)', [
              '@label' => $target_entity->label(),
              '@id' => $target_entity->id(),
            ]);
          }
        }
      }
      else {
        $node_state_items[] = $this->t('Value: @value', ['@value' => $node->get('field_owner_state')->value]);
      }

      $build['node_state'] = [
        '#type' => 'details',
        '#title' => $this->t('Node Owner State'),
        '#open' => TRUE,
        'content' => [
          '#theme' => 'item_list',
          '#items' => $node_state_items,
        ],
      ];
    }
    else {
      $build['node_state'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('Node does not have an owner state.') . '</p>',
      ];
    }

    // Access check.
    $user_state = NULL;
    if ($user->hasField('field_state_cd') && !$user->get('field_state_cd')->isEmpty()) {
      if ($field_type === 'entity_reference') {
        $user_state = $user->get('field_state_cd')->target_id;
      }
      else {
        $user_state = $user->get('field_state_cd')->value;
      }
    }

    $node_state = NULL;
    if ($node->hasField('field_owner_state') && !$node->get('field_owner_state')->isEmpty()) {
      $node_state = $node->get('field_owner_state')->target_id;
    }

    $result = ($user_state == $node_state);

    $build['access_check'] = [
      '#type' => 'details',
      '#title' => $this->t('Access Check'),
      '#open' => TRUE,
      'content' => [
        '#theme' => 'item_list',
        '#items' => [
          $this->t('User state: @state', ['@state' => $user_state]),
          $this->t('Node state: @state', ['@state' => $node_state]),
          $this->t('Comparison result: @result', ['@result' => $result ? 'TRUE' : 'FALSE']),
          $this->t('Access decision: @decision', ['@decision' => $result ? 'ALLOWED' : 'FORBIDDEN']),
        ],
      ],
    ];

    return $build;
  }

}
