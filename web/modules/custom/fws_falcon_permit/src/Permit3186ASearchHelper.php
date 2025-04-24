<?php

namespace Drupal\fws_falcon_permit;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Service for handling search operations.
 */
class Permit3186ASearchHelper {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Constructs a new TrackingSearchManager.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, AccountProxyInterface $current_user) {
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
  }

  /**
   * Get field value as contain string.
   *
   * @param string $field
   *   The field name.
   * @param string $string
   *   The field value.
   *
   * @return array
   *   An array with field value in value and label as keys.
   */
  public function getFieldValueContains($field, $string) {
    $nids = $this->entityTypeManager
      ->getStorage('node')
      ->getQuery()
      ->accessCheck()
      ->condition('type', 'permit_3186a')
      ->condition('status', TRUE)
      ->condition($field, $string, 'CONTAINS')
      ->range(0, 10)
      ->execute();
    if (empty($nids)) {
      return [];
    }

    $results = [];
    foreach ($this->entityTypeManager->getStorage('node')->loadMultiple($nids) as $node) {
      $value = $node->get($field)->value;
      $results[] = [
        'value' => $value,
        'label' => $value,
      ];
    }
    return $results;
  }

  /**
   * Get taxonomy term options.
   *
   * @param string $vid
   *   The vocabulary ID.
   *
   * @return array
   *   An array terms contain key as term ID and value as term name.
   */
  public function getTaxonomyTermOptions($vid) {
    $terms = $this->entityTypeManager
      ->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => $vid]);

    $options = [];
    foreach ($terms as $term) {
      $options[$term->id()] = $term->label();
    }

    return $options;
  }

  /**
   * Get search results by filter values.
   *
   * @param array $filter_values
   *   An array filter with input and value.
   *
   * @return array
   *   An array of permit records ID.
   */
  public function getSearchResults(array $filter_values) {
    $equal_filters = [
      'record_number',
      'transaction_number',
      'field_species_cd',
      'field_species_name',
      'uid',
    ];

    $mapping_fields = [
      'record_number' => 'field_recno',
      'transaction_number' => 'field_question_no',
      'authorized' => 'field_authorized_cd',
      'species_code' => 'field_species_cd',
      'ownership' => 'uid',
      'transfer_type' => 'field_sender_transfer_type_cd',
    ];

    $query = $this->entityTypeManager
      ->getStorage('node')
      ->getQuery()
      ->accessCheck()
      ->condition('type', 'permit_3186a')
      ->condition('status', TRUE);

    foreach ($filter_values as $filter => $value) {
      $field = $mapping_fields[$filter] ?? "field_{$filter}";
      $query->condition($field, $value, in_array($filter, $equal_filters) ? '=' : 'CONTAINS');
    }

    if ($this->currentUser->hasRole('state_admin')) {
      $user = $this->entityTypeManager
        ->getStorage('user')
        ->load($this->currentUser->id());
      $state = $user->get('field_state_cd')?->entity?->id() ?? 'N/A';
      $query->condition('uid.entity.field_state_cd', $state);
    }

    return $query->pager(20)->execute();
  }

}
