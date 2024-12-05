<?php

namespace Drupal\manatee_reports;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Service for handling manatee search operations.
 */
class ManateeSearchManager {

  use StringTranslationTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructs a new ManateeSearchManager.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    Connection $database,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->database = $database;
  }

  /**
   * Gets available tag types.
   *
   * @return array
   *   Array of tag types.
   */
  public function getTagTypes() {
    $types = [];
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => 'tag_type']);
    foreach ($terms as $term) {
      $types[$term->id()] = $term->label();
    }
    return $types;
  }

  /**
   * Gets available rescue types.
   *
   * @return array
   *   Array of rescue types.
   */
  public function getRescueTypes() {
    $types = [];
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => 'rescue_types']);
    foreach ($terms as $term) {
      $types[$term->id()] = $term->label();
    }
    return $types;
  }

  /**
   * Gets available organizations.
   *
   * @return array
   *   Array of organizations.
   */
  public function getOrganizations() {
    $orgs = [];
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => 'org']);
    foreach ($terms as $term) {
      if ($term->hasField('field_organization') && !$term->field_organization->isEmpty()) {
        $orgs[$term->id()] = $term->field_organization->value;
      }
    }
    return $orgs;
  }

  /**
   * Gets counties list.
   *
   * @return array
   *   Array of counties.
   */
  public function getCounties() {
    $counties = [];
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => 'county']);
    foreach ($terms as $term) {
      $counties[$term->id()] = $term->label();
    }
    return $counties;
  }

  /**
   * Gets states list.
   *
   * @return array
   *   Array of states.
   */
  public function getStates() {
    return [
      'FL' => 'Florida',
      // Add other states as needed.
    ];
  }

  /**
   * Gets event types.
   *
   * @return array
   *   Array of event types.
   */
  public function getEventTypes() {
    return [
      'birth' => $this->t('Birth'),
      'rescue' => $this->t('Rescue'),
      'release' => $this->t('Release'),
      'transfer' => $this->t('Transfer'),
      'death' => $this->t('Death'),
    ];
  }

  /**
   * Searches for manatees based on criteria.
   *
   * @param array $criteria
   *   Search criteria.
   *
   * @return array
   *   Search results.
   */
  public function searchManatees(array $criteria) {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'manatee')
      ->accessCheck(FALSE);

    // Apply search criteria.
    foreach ($criteria as $field => $value) {
      if (!empty($value)) {
        switch ($field) {
          case 'mlog':
            $query->condition('field_mlog', $value, '=');
            break;

          case 'animal_id':
            // First find matches in animal_id nodes.
            $animal_id_query = $this->entityTypeManager->getStorage('node')->getQuery()
              ->condition('type', 'manatee_animal_id')
              ->condition('field_animal_id', '%' . $value . '%', 'LIKE')
              ->accessCheck(FALSE);
            $animal_ids = $animal_id_query->execute();
            if (!empty($animal_ids)) {
              $query->condition('nid', $animal_ids, 'IN');
            }
            break;

          // Add more field conditions as needed.
        }
      }
    }

    return $query->execute();
  }

}
