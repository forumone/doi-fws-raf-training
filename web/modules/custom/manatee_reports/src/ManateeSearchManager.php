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

    foreach ($criteria as $condition) {
      if (empty($condition['field']) || empty($condition['value'])) {
        continue;
      }

      switch ($condition['field']) {
        case 'field_mlog':
          $query->condition('field_mlog', $condition['value'], '=');
          break;

        case 'field_animal_id':
          $animal_id_query = $this->entityTypeManager->getStorage('node')->getQuery()
            ->condition('type', 'manatee_animal_id')
            ->condition('field_animal_id', $condition['value'], '=')
            ->accessCheck(FALSE);
          $animal_ids = $animal_id_query->execute();

          if (!empty($animal_ids)) {
            $animal_id_node = $this->entityTypeManager->getStorage('node')->load(reset($animal_ids));
            if (!$animal_id_node->field_animal->isEmpty()) {
              $query->condition('nid', $animal_id_node->field_animal->target_id);
            }
          }
          break;

        case 'field_name':
          $name_query = $this->entityTypeManager->getStorage('node')->getQuery()
            ->condition('type', 'manatee_name')
            ->condition('field_name', '%' . $condition['value'] . '%', 'LIKE')
            ->accessCheck(FALSE);
          $name_matches = $name_query->execute();
          if (!empty($name_matches)) {
            $name_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($name_matches);
            $manatee_ids = [];
            foreach ($name_nodes as $name_node) {
              if (!$name_node->field_animal->isEmpty()) {
                $manatee_ids[] = $name_node->field_animal->target_id;
              }
            }
            if (!empty($manatee_ids)) {
              $query->condition('nid', $manatee_ids, 'IN');
            }
            else {
              $query->condition('nid', 0);
            }
          }
          else {
            $query->condition('nid', 0);
          }
          break;

        case 'field_tag_id':
          $tag_query = $this->entityTypeManager->getStorage('node')->getQuery()
            ->condition('type', 'manatee_tag')
            ->condition('field_tag_id', '%' . $condition['value'] . '%', 'LIKE');
          if (isset($condition['type'])) {
            $tag_query->condition('field_tag_type', $condition['type']);
          }
          $tag_query->accessCheck(FALSE);
          $tag_matches = $tag_query->execute();
          if (!empty($tag_matches)) {
            $tag_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($tag_matches);
            $manatee_ids = [];
            foreach ($tag_nodes as $tag_node) {
              if (!$tag_node->field_animal->isEmpty()) {
                $manatee_ids[] = $tag_node->field_animal->target_id;
              }
            }
            if (!empty($manatee_ids)) {
              $query->condition('nid', $manatee_ids, 'IN');
            }
            else {
              $query->condition('nid', 0);
            }
          }
          else {
            $query->condition('nid', 0);
          }
          break;

        case 'type':
          $event_type = str_replace('manatee_', '', $condition['value']);
          $event_query = $this->entityTypeManager->getStorage('node')->getQuery()
            ->condition('type', 'manatee_' . $event_type)
            ->condition('field_animal', NULL, 'IS NOT NULL')
            ->accessCheck(FALSE);

          if (isset($condition['from'])) {
            $event_query->condition('field_' . $event_type . '_date', $condition['from'], '>=');
          }
          if (isset($condition['to'])) {
            $event_query->condition('field_' . $event_type . '_date', $condition['to'], '<=');
          }

          $event_matches = $event_query->execute();
          if (!empty($event_matches)) {
            $event_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($event_matches);
            $manatee_ids = [];
            foreach ($event_nodes as $event_node) {
              if (!$event_node->field_animal->isEmpty()) {
                $manatee_ids[] = $event_node->field_animal->target_id;
              }
            }
            if (!empty($manatee_ids)) {
              $query->condition('nid', $manatee_ids, 'IN');
            }
            else {
              $query->condition('nid', 0);
            }
          }
          else {
            $query->condition('nid', 0);
          }
          break;

        case 'field_event_date':
          $operator = $condition['operator'] ?? '=';
          $event_types = ['birth', 'rescue', 'release', 'transfer', 'death'];
          $or_group = $query->orConditionGroup();

          foreach ($event_types as $type) {
            $event_query = $this->entityTypeManager->getStorage('node')->getQuery()
              ->condition('type', 'manatee_' . $type)
              ->condition('field_' . $type . '_date', $condition['value'], $operator)
              ->condition('field_animal', NULL, 'IS NOT NULL')
              ->accessCheck(FALSE);

            $event_matches = $event_query->execute();
            if (!empty($event_matches)) {
              $event_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($event_matches);
              foreach ($event_nodes as $event_node) {
                if (!$event_node->field_animal->isEmpty()) {
                  $or_group->condition('nid', $event_node->field_animal->target_id);
                }
              }
            }
          }
          $query->condition($or_group);
          break;

        case 'field_county':
        case 'field_state':
        case 'field_waterway':
          $operator = $condition['operator'] ?? '=';
          $query->condition($condition['field'], $condition['value'], $operator);
          break;

        case 'field_rescue_type':
        case 'field_rescue_cause':
        case 'field_organization':
        case 'field_cause_of_death':
          $query->condition($condition['field'], $condition['value']);
          break;
      }
    }

    return $query->execute();
  }

}
