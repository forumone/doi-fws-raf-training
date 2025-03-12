<?php

namespace Drupal\tracking_reports;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Utility\TableSort;
use Drupal\node\NodeInterface;

/**
 * Service for handling tracking search operations.
 */
class TrackingSearchManager {

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
   * Constructs a new TrackingSearchManager.
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
      if ($term->hasField('field_tag_type_text') && !$term->field_tag_type_text->isEmpty()) {
        $types[$term->id()] = $term->field_tag_type_text->value;
      }
    }
    asort($types);
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
      ->loadByProperties(['vid' => 'rescue_type']);
    foreach ($terms as $term) {
      $types[$term->id()] = $term->field_rescue_type_text->value;
    }
    asort($types);
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
    asort($orgs);
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
    asort($counties);
    return $counties;
  }

  /**
   * Gets states list.
   *
   * @return array
   *   Array of states with term ID as key and state name as value.
   */
  public function getStates() {
    $terms = $this->entityTypeManager
      ->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => 'state']);

    $states = [];
    foreach ($terms as $term) {
      $states[$term->id()] = $term->get('field_state_name')->value;
    }
    asort($states);
    return $states;
  }

  /**
   * Gets event types.
   *
   * @return array
   *   Array of event types.
   */
  public function getEventTypes() {
    return [
      'species_birth' => $this->t('Birth'),
      'species_rescue' => $this->t('Rescue'),
      'transfer' => $this->t('Transfer'),
      'species_release' => $this->t('Release'),
      'species_death' => $this->t('Death'),
    ];
  }

  /**
   * Searches for species based on criteria.
   *
   * @param array $criteria
   *   Search criteria.
   *
   * @return array
   *   Search results.
   */
  public function searchSpecies(array $criteria) {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'species')
      ->accessCheck(FALSE);

    foreach ($criteria as $condition) {
      if (empty($condition['field']) || empty($condition['value'])) {
        continue;
      }

      switch ($condition['field']) {
        case 'field_number':
          $query->condition('field_number', $condition['value'], '=');
          break;

        case 'field_species_id':
          // 1) Query for nodes of type 'species_id' where the text in
          // field_species_id matches whatever user typed (exact match or
          // partial match).
          $species_id_query = $this->entityTypeManager->getStorage('node')->getQuery()
            ->condition('type', 'species_id')
            ->condition('field_species_id', $condition['value'], 'CONTAINS')
            ->accessCheck(FALSE);

          $id_node_ids = $species_id_query->execute();

          if (!empty($id_node_ids)) {
            // 2) Load those nodes, extract the referenced Species node IDs,
            // collect in an array.
            $id_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($id_node_ids);
            $referenced_species_ids = [];
            foreach ($id_nodes as $id_node) {
              if (!$id_node->get('field_species_ref')->isEmpty()) {
                $referenced_species_ids[] = $id_node->get('field_species_ref')->target_id;
              }
            }

            // 3) Filter the main query on those species node IDs.
            if (!empty($referenced_species_ids)) {
              $query->condition('nid', $referenced_species_ids, 'IN');
            }
            else {
              $query->condition('nid', 0);
            }
          }
          else {
            $query->condition('nid', 0);
          }
          break;

        case 'field_name':
          // Get all species nodes that have a name containing the search value.
          $species_query = $this->entityTypeManager->getStorage('node')->getQuery()
            ->condition('type', 'species')
            ->accessCheck(FALSE)
            ->execute();

          if (!empty($species_query)) {
            $matching_species_ids = [];
            $species_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($species_query);

            foreach ($species_nodes as $species_node) {
              if ($species_node->hasField('field_names') && !$species_node->field_names->isEmpty()) {
                $names_paragraphs = $species_node->field_names->referencedEntities();
                foreach ($names_paragraphs as $paragraph) {
                  if ($paragraph->hasField('field_name') && !$paragraph->field_name->isEmpty()) {
                    $name = $paragraph->field_name->value;
                    if (stripos($name, $condition['value']) !== FALSE) {
                      $matching_species_ids[] = $species_node->id();
                      // Found a match, no need to check other names.
                      break;
                    }
                  }
                }
              }
            }

            if (!empty($matching_species_ids)) {
              $query->condition('nid', $matching_species_ids, 'IN');
            }
            else {
              // Force no results if no matches found.
              $query->condition('nid', 0);
            }
          }
          else {
            // Force no results if no species found.
            $query->condition('nid', 0);
          }
          break;

        case 'field_tag_id':
          $tag_query = $this->entityTypeManager->getStorage('node')->getQuery()
            ->condition('type', 'species_tag')
            ->condition('field_tag_id', $condition['value']);
          $tag_query->accessCheck(FALSE);
          $tag_matches = $tag_query->execute();

          if (!empty($tag_matches)) {
            $tag_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($tag_matches);
            $species_ids = [];
            foreach ($tag_nodes as $tag_node) {
              if (!$tag_node->field_species_ref->isEmpty()) {
                $species_ids[] = $tag_node->field_species_ref->target_id;
              }
            }
            if (!empty($species_ids)) {
              $query->condition('nid', $species_ids, 'IN');
            }
            else {
              $query->condition('nid', 0);
            }
          }
          else {
            $query->condition('nid', 0);
          }
          break;

        case 'field_tag_type':
          if (!empty($condition['value']) && $condition['value'] !== 'All') {
            $tag_query = $this->entityTypeManager->getStorage('node')->getQuery()
              ->condition('type', 'species_tag')
              ->condition('field_tag_type', $condition['value'])
              ->condition('field_species_ref', NULL, 'IS NOT NULL')
              ->accessCheck(FALSE);

            $tag_matches = $tag_query->execute();

            if (!empty($tag_matches)) {
              $tag_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($tag_matches);
              $species_ids = [];
              foreach ($tag_nodes as $tag_node) {
                if (!$tag_node->field_species_ref->isEmpty()) {
                  $species_ids[] = $tag_node->field_species_ref->target_id;
                }
              }
              if (!empty($species_ids)) {
                $query->condition('nid', $species_ids, 'IN');
              }
              else {
                $query->condition('nid', 0);
              }
            }
            else {
              $query->condition('nid', 0);
            }
          }
          break;

        case 'field_waterway':
          $rescue_query = $this->entityTypeManager->getStorage('node')->getQuery()
            ->condition('type', 'species_rescue')
            ->condition('field_waterway', '%' . $condition['value'] . '%', 'LIKE')
            ->condition('field_species_ref', NULL, 'IS NOT NULL')
            ->accessCheck(FALSE);

          $rescue_matches = $rescue_query->execute();
          if (!empty($rescue_matches)) {
            $rescue_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($rescue_matches);
            $species_ids = [];
            foreach ($rescue_nodes as $rescue_node) {
              if (!$rescue_node->field_species_ref->isEmpty()) {
                $species_ids[] = $rescue_node->field_species_ref->target_id;
              }
            }
            if (!empty($species_ids)) {
              $query->condition('nid', $species_ids, 'IN');
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
          $event_type = $condition['value'];
          $date_fields = [
            'species_birth' => 'field_birth_date',
            'species_rescue' => 'field_rescue_date',
            'transfer' => 'field_transfer_date',
            'species_release' => 'field_release_date',
            'species_death' => 'field_death_date',
          ];

          $event_query = $this->entityTypeManager->getStorage('node')->getQuery()
            ->condition('type', $event_type)
            ->condition('field_species_ref', NULL, 'IS NOT NULL')
            ->accessCheck(FALSE);

          if (isset($condition['from']) && isset($date_fields[$event_type])) {
            $event_query->condition($date_fields[$event_type], $condition['from'], '>=');
          }
          if (isset($condition['to']) && isset($date_fields[$event_type])) {
            $event_query->condition($date_fields[$event_type], $condition['to'], '<=');
          }

          $event_matches = $event_query->execute();
          if (!empty($event_matches)) {
            $event_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($event_matches);
            $species_ids = [];
            foreach ($event_nodes as $event_node) {
              if (!$event_node->field_species_ref->isEmpty()) {
                $species_ids[] = $event_node->field_species_ref->target_id;
              }
            }
            if (!empty($species_ids)) {
              $query->condition('nid', $species_ids, 'IN');
            }
            else {
              $query->condition('nid', 0);
            }
          }
          else {
            $query->condition('nid', 0);
          }
          break;

        case 'field_county':
        case 'field_state':
          $rescue_query = $this->entityTypeManager->getStorage('node')->getQuery()
            ->condition('type', 'species_rescue')
            ->condition('field_state', $condition['value'])
            ->condition('field_species_ref', NULL, 'IS NOT NULL')
            ->accessCheck(FALSE);

          $rescue_matches = $rescue_query->execute();
          if (!empty($rescue_matches)) {
            $rescue_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($rescue_matches);
            $species_ids = [];
            foreach ($rescue_nodes as $rescue_node) {
              if (!$rescue_node->field_species_ref->isEmpty()) {
                $species_ids[] = $rescue_node->field_species_ref->target_id;
              }
            }
            if (!empty($species_ids)) {
              $query->condition('nid', $species_ids, 'IN');
            }
            else {
              $query->condition('nid', 0);
            }
          }
          else {
            $query->condition('nid', 0);
          }
          break;

        case 'field_rescue_type':
          if (!empty($condition['value']) && $condition['value'] !== 'All') {
            $rescue_query = $this->entityTypeManager->getStorage('node')->getQuery()
              ->condition('type', 'species_rescue')
              ->condition('field_rescue_type', $condition['value'])
              ->condition('field_species_ref', NULL, 'IS NOT NULL')
              ->accessCheck(FALSE);

            $rescue_matches = $rescue_query->execute();

            if (!empty($rescue_matches)) {
              $rescue_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($rescue_matches);
              $species_ids = [];
              foreach ($rescue_nodes as $rescue_node) {
                if (!$rescue_node->field_species_ref->isEmpty()) {
                  $species_ids[] = $rescue_node->field_species_ref->target_id;
                }
              }
              if (!empty($species_ids)) {
                $query->condition('nid', $species_ids, 'IN');
              }
              else {
                $query->condition('nid', 0);
              }
            }
            else {
              $query->condition('nid', 0);
            }
          }
          break;

        case 'field_rescue_cause':
          $rescue_query = $this->entityTypeManager->getStorage('node')->getQuery()
            ->condition('type', 'species_rescue')
            ->condition('field_species_ref', NULL, 'IS NOT NULL')
            ->accessCheck(FALSE);

          $or_group = $rescue_query->orConditionGroup()
            ->condition('field_primary_cause', $condition['value'])
            ->condition('field_secondary_cause', $condition['value']);

          $rescue_query->condition($or_group);

          $rescue_matches = $rescue_query->execute();

          if (!empty($rescue_matches)) {
            $rescue_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($rescue_matches);
            $species_ids = [];
            foreach ($rescue_nodes as $rescue_node) {
              if (!$rescue_node->field_species_ref->isEmpty()) {
                $species_ids[] = $rescue_node->field_species_ref->target_id;
              }
            }
            if (!empty($species_ids)) {
              $query->condition('nid', $species_ids, 'IN');
            }
            else {
              $query->condition('nid', 0);
            }
          }
          else {
            $query->condition('nid', 0);
          }
          break;

        case 'field_organization':
          $event_types = [
            ['type' => 'species_birth', 'field' => 'field_org'],
            ['type' => 'species_death', 'field' => 'field_org'],
            ['type' => 'species_release', 'field' => 'field_org'],
            ['type' => 'species_rescue', 'field' => 'field_org'],
            ['type' => 'transfer', 'field' => 'field_from_facility'],
            ['type' => 'transfer', 'field' => 'field_to_facility'],
          ];

          $event_queries = [];
          foreach ($event_types as $event) {
            $event_query = $this->entityTypeManager->getStorage('node')->getQuery()
              ->condition('type', $event['type'])
              ->condition($event['field'], $condition['value'])
              ->condition('field_species_ref', NULL, 'IS NOT NULL')
              ->accessCheck(FALSE);

            $event_matches = $event_query->execute();
            if (!empty($event_matches)) {
              $event_nodes = $this->entityTypeManager->getStorage('node')
                ->loadMultiple($event_matches);
              foreach ($event_nodes as $event_node) {
                if (!$event_node->field_species_ref->isEmpty()) {
                  $event_queries[] = $event_node->field_species_ref->target_id;
                }
              }
            }
          }

          if (!empty($event_queries)) {
            $query->condition('nid', $event_queries, 'IN');
          }
          else {
            $query->condition('nid', 0);
          }
          break;

        case 'field_cause_id':
          if (!empty($condition['value']) && $condition['value'] !== 'All') {
            $death_query = $this->entityTypeManager->getStorage('node')->getQuery()
              ->condition('type', 'species_death')
              ->condition('field_cause_id', $condition['value'])
              ->condition('field_species_ref', NULL, 'IS NOT NULL')
              ->accessCheck(FALSE);

            $death_matches = $death_query->execute();
            if (!empty($death_matches)) {
              $death_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($death_matches);
              $species_ids = [];
              foreach ($death_nodes as $death_node) {
                if (!$death_node->field_species_ref->isEmpty()) {
                  $species_ids[] = $death_node->field_species_ref->target_id;
                }
              }
              if (!empty($species_ids)) {
                $query->condition('nid', $species_ids, 'IN');
              }
              else {
                $query->condition('nid', 0);
              }
            }
            else {
              $query->condition('nid', 0);
            }
          }
          break;

        case 'nid':
          $operator = $condition['operator'] ?? '=';
          $query->condition('nid', $condition['value'], $operator);
          break;
      }
    }

    return $query->execute();
  }

  /**
   * Get latest event for a species.
   */
  public function getLatestEvent($species_id, $specific_type = NULL) {
    $event_types = [
      'species_birth' => 'field_birth_date',
      'species_rescue' => 'field_rescue_date',
      'transfer' => 'field_transfer_date',
      'species_release' => 'field_release_date',
      'species_death' => 'field_death_date',
    ];

    // If a specific type is requested.
    if ($specific_type && isset($event_types[$specific_type])) {
      $date_field = $event_types[$specific_type];
      $query = $this->entityTypeManager->getStorage('node')->getQuery()
        ->condition('type', $specific_type)
        ->condition('field_species_ref', $species_id)
        ->condition($date_field, NULL, 'IS NOT NULL')
        ->sort($date_field, 'DESC')
        ->range(0, 1)
        ->accessCheck(FALSE);

      $results = $query->execute();
      if (!empty($results)) {
        $node = $this->entityTypeManager->getStorage('node')->load(reset($results));
        if ($node && !$node->get($date_field)->isEmpty()) {
          $date_value = $node->get($date_field)->value;
          $formatted_date = date('m/d/Y', strtotime($date_value));
          return [
            'type' => ucfirst(str_replace(['species_', '_'], ['', ' '], $specific_type)),
            'date' => $formatted_date,
          // Added for sorting.
            'timestamp' => strtotime($date_value),
          ];
        }
      }
      return ['type' => 'N/A', 'date' => 'N/A', 'timestamp' => 0];
    }

    // Otherwise, find the latest event across all types.
    $latest_event = NULL;
    $latest_timestamp = 0;

    foreach ($event_types as $type => $date_field) {
      $query = $this->entityTypeManager->getStorage('node')->getQuery()
        ->condition('type', $type)
        ->condition('field_species_ref', $species_id)
        ->condition($date_field, NULL, 'IS NOT NULL')
        ->sort($date_field, 'DESC')
        ->range(0, 1)
        ->accessCheck(FALSE);

      $results = $query->execute();
      if (!empty($results)) {
        $node = $this->entityTypeManager->getStorage('node')->load(reset($results));
        if ($node && !$node->get($date_field)->isEmpty()) {
          $date_value = $node->get($date_field)->value;
          $timestamp = strtotime($date_value);

          if ($timestamp > $latest_timestamp) {
            $latest_timestamp = $timestamp;
            $latest_event = [
              'type' => ucfirst(str_replace(['species_', '_'], ['', ' '], $type)),
              'date' => date('m/d/Y', $timestamp),
              'timestamp' => $timestamp,
            ];
          }
        }
      }
    }

    return $latest_event ?: ['type' => 'N/A', 'date' => 'N/A', 'timestamp' => 0];
  }

  /**
   * Build search results render array with header-based sorting.
   */
  protected function buildSortableData($species_nodes, $event_type = NULL) {
    $data = [];
    foreach ($species_nodes as $species_entity) {
      $sid = $species_entity->id();
      $latest_event = $this->getLatestEvent($sid, $event_type);

      // Get the event node to fetch details.
      $event_query = $this->entityTypeManager->getStorage('node')->getQuery()
        ->condition('type', strtolower(str_replace(' ', '_', 'species_' . $latest_event['type'])))
        ->condition('field_species_ref', $sid)
        ->sort($event_type['date_field'], 'DESC')
        ->range(0, 1)
        ->accessCheck(FALSE);

      $event_results = $event_query->execute();
      $event_details = 'N/A';

      if (!empty($event_results)) {
        $event_node = $this->entityTypeManager->getStorage('node')->load(reset($event_results));
        if ($event_node) {
          $event_details = $this->getEventDetails($event_node);
        }
      }

      $data[] = [
        'species_id' => $sid,
        'number' => $this->getNumber($species_entity),
        'species_name' => $this->getPrimaryName($sid),
        'species_id_value' => $this->getSpeciesId($sid),
        'latest_event_type' => $latest_event['type'],
        'latest_event_date' => $latest_event['date'],
        'latest_event_details' => $event_details,
        'latest_event_timestamp' => $latest_event['timestamp'],
      ];
    }

    return $data;
  }

  /**
   * Build search results render array with header-based sorting.
   *
   * @param array|null $conditions
   *   Optional conditions array from processSearchParameters().
   * @param int|null $species_id
   *   Optional specific species ID to show results for.
   *
   * @return array
   *   A render array of the search results table with header-based sorting.
   */
  public function buildSearchResults(?array $conditions = NULL, ?int $species_id = NULL) {
    if ($species_id) {
      $species_ids = [$species_id];
    }
    else {
      $species_ids = !empty($conditions) ? $this->searchSpecies($conditions) : [];
    }

    $total_items = count($species_ids);

    // Early return if no matches.
    if (empty($species_ids)) {
      return [
        '#markup' => '<div class="no-results">' . $this->t('No results found.') . '</div>',
      ];
    }

    // Identify the event_type from conditions, if present.
    $event_type = NULL;
    if (!empty($conditions)) {
      foreach ($conditions as $condition) {
        if ($condition['field'] === 'type') {
          $event_type = $condition['value'];
          break;
        }
      }
    }

    // Define table header with event date as default sort.
    $header = [
      'number' => [
        'data' => $this->t('Tracking Number'),
        'field' => 'number',
      ],
      'species_name' => [
        'data' => $this->t('Name'),
        'field' => 'species_name',
      ],
      'species_id_value' => [
        'data' => $this->t('Species ID'),
        'field' => 'species_id_value',
      ],
      'latest_event_type' => [
        'data' => $this->t('Event'),
        'field' => 'latest_event_type',
      ],
      'latest_event_date' => [
        'data' => $this->t('Last Event'),
        'field' => 'latest_event_date',
        'sort' => 'desc',
      ],
      'add_event' => [
        'data' => $this->t('Add Event'),
        'sortable' => FALSE,
      ],
    ];

    // Load all species nodes and create a data array for sorting.
    $species_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($species_ids);
    $data = [];

    foreach ($species_nodes as $species_entity) {
      $sid = $species_entity->id();
      $latest_event = $this->getLatestEvent($sid, $event_type);

      // Get the event node to fetch details.
      $event_type_bundle = strtolower(str_replace(' ', '_', 'species_' . $latest_event['type']));
      if ($event_type_bundle !== 'species_n/a') {
        $event_query = $this->entityTypeManager->getStorage('node')->getQuery()
          ->condition('type', $event_type_bundle)
          ->condition('field_species_ref', $sid)
          ->accessCheck(FALSE);

        // Add date field condition based on event type.
        $date_fields = [
          'species_birth' => 'field_birth_date',
          'species_rescue' => 'field_rescue_date',
          'transfer' => 'field_transfer_date',
          'species_release' => 'field_release_date',
          'species_death' => 'field_death_date',
        ];

        if (isset($date_fields[$event_type_bundle])) {
          $event_query->sort($date_fields[$event_type_bundle], 'DESC');
        }

        $event_query->range(0, 1);
        $event_results = $event_query->execute();

        $event_details = 'N/A';
        if (!empty($event_results)) {
          $event_node = $this->entityTypeManager->getStorage('node')->load(reset($event_results));
          if ($event_node) {
            $event_details = $this->getEventDetails($event_node);
          }
        }
      }
      else {
        $event_details = 'N/A';
      }

      $data[] = [
        'species_id' => $sid,
        'number' => $this->getNumber($species_entity),
        'species_name' => $this->getPrimaryName($sid),
        'species_id_value' => $this->getSpeciesId($sid),
        'latest_event_type' => $latest_event['type'],
        'latest_event_date' => $latest_event['date'],
        'latest_event_details' => $event_details,
        'latest_event_timestamp' => $latest_event['timestamp'],
      ];
    }

    // Get current request from the request stack.
    $request = \Drupal::request();

    // Use TableSort with the request object.
    $order = TableSort::getOrder($header, $request);
    $sort = TableSort::getSort($header, $request);

    // Sort the data array.
    usort($data, function ($a, $b) use ($order, $sort) {
      // Default to event date if no sort specified.
      $field = $order['sql'] ?? 'latest_event_date';

      if ($field === 'latest_event_date') {
        $timeA = strtotime($a[$field]) ?: 0;
        $timeB = strtotime($b[$field]) ?: 0;
        return ($sort === 'asc') ? $timeA <=> $timeB : $timeB <=> $timeA;
      }
      else {
        $valA = strtolower($a[$field] ?? '');
        $valB = strtolower($b[$field] ?? '');
        return ($sort === 'asc') ? $valA <=> $valB : $valB <=> $valA;
      }
    });

    // Get event types for select options.
    $event_types = [
      '' => $this->t('- Select event -'),
      'species_rescue' => $this->t('Add Rescue'),
      'transfer' => $this->t('Add Transfer'),
      'species_prerelease' => $this->t('Add Pre-release'),
      'species_release' => $this->t('Add Release'),
      'species_death' => $this->t('Add Death'),
      'status_report' => $this->t('Add Status Report'),
      'species_entangle' => $this->t('Add Entanglement'),
    ];

    // Implement pager.
    $items_per_page = 20;
    $pager = \Drupal::service('pager.manager')->createPager($total_items, $items_per_page);
    $current_page = $pager->getCurrentPage();
    $offset = $current_page * $items_per_page;
    $paged_data = array_slice($data, $offset, $items_per_page);

    // Build rows.
    $rows = [];
    foreach ($paged_data as $row) {
      $number_link = Link::createFromRoute($row['number'], 'entity.node.canonical', ['node' => $row['species_id']]);

      // Create a select element for each row.
      $select = [
        '#type' => 'select',
        '#title' => $this->t('Add event'),
        '#title_display' => 'invisible',
        '#options' => $event_types,
        '#empty_option' => $this->t('- Select event -'),
        '#name' => 'add_event_' . $row['species_id'],
        '#attributes' => [
          'class' => ['add-event-select'],
          'data-species-id' => $row['species_id'],
        ],
      ];

      // Combine date and details for the last event column.
      $event_info = $row['latest_event_date'];
      if ($row['latest_event_details'] !== 'N/A') {
        $event_info .= ' - ' . $row['latest_event_details'];
      }

      $rows[] = [
        'data' => [
          'number' => ['data' => $number_link],
          'species_name' => ['data' => $row['species_name']],
          'species_id_value' => ['data' => $row['species_id_value']],
          'latest_event_type' => ['data' => $row['latest_event_type']],
          'latest_event_date' => ['data' => $event_info],
          'add_event' => ['data' => $select],
        ],
      ];
    }

    // Add JavaScript to handle the select change.
    $form['#attached']['library'][] = 'tracking_reports/tracking_reports';

    // Return the table render array.
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['species-search-results']],
      '#attached' => [
        'library' => ['tracking_reports/tracking_reports'],
        'drupalSettings' => [
          'trackingReports' => [
            'baseUrl' => '/node/add/',
          ],
        ],
      ],
      'count' => [
        '#markup' => $this->t('@count results found', ['@count' => $total_items]),
        '#prefix' => '<div class="results-count">',
        '#suffix' => '</div>',
      ],
      'table' => [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => $this->t('No results found'),
        '#attributes' => ['class' => ['species-report-table']],
        '#tablesort' => TRUE,
      ],
      'pager' => [
        '#type' => 'pager',
      ],
    ];
  }

  /**
   * Get Number value for a species.
   */
  public function getNumber($species_entity) {
    return !$species_entity->field_number->isEmpty() ? $species_entity->field_number->value : 'N/A';
  }

  /**
   * Get primary name for a species.
   */
  public function getPrimaryName($species_id) {
    $species_node = $this->entityTypeManager->getStorage('node')->load($species_id);
    if ($species_node && $species_node->hasField('field_names') && !$species_node->field_names->isEmpty()) {
      $names_paragraphs = $species_node->field_names->referencedEntities();
      foreach ($names_paragraphs as $paragraph) {
        if ($paragraph->hasField('field_primary') &&
            !$paragraph->field_primary->isEmpty() &&
            $paragraph->field_primary->value == 1 &&
            $paragraph->hasField('field_name') &&
            !$paragraph->field_name->isEmpty()) {
          return $paragraph->field_name->value;
        }
      }
    }
    return 'N/A';
  }

  /**
   * Get species IDs for a species.
   *
   * @param int $species_id
   *   The species node ID.
   *
   * @return string
   *   Comma-separated list of species IDs, or 'N/A' if none found.
   */
  public function getSpeciesId($species_id) {
    $id_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'species_id')
      ->condition('field_species_ref', $species_id)
      ->accessCheck(FALSE)
      ->execute();

    if (!empty($id_query)) {
      $id_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($id_query);
      $species_ids = [];

      foreach ($id_nodes as $id_node) {
        if (!$id_node->field_species_id->isEmpty()) {
          $species_ids[] = $id_node->field_species_id->value;
        }
      }

      if (!empty($species_ids)) {
        return implode(', ', $species_ids);
      }
    }
    return 'N/A';
  }

  /**
   * Gets rescue causes.
   *
   * @return array
   *   Array of rescue causes.
   */
  public function getRescueCauses() {
    $causes = [];
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => 'rescue_cause']);
    foreach ($terms as $term) {
      if ($term->hasField('field_rescue_cause') && !$term->field_rescue_cause->isEmpty()) {
        $label = $term->field_rescue_cause->value;
        if ($term->hasField('field_rescue_cause_detail') && !$term->field_rescue_cause_detail->isEmpty()) {
          $label .= ': ' . $term->field_rescue_cause_detail->value;
        }
        $causes[$term->id()] = $label;
      }
    }
    asort($causes);
    return $causes;
  }

  /**
   * Gets death causes.
   *
   * @return array
   *   Array of death causes.
   */
  public function getDeathCauses() {
    $causes = [];
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => 'death_cause']);
    foreach ($terms as $term) {
      if ($term->hasField('field_death_cause') && !$term->field_death_cause->isEmpty()) {
        $label = $term->field_death_cause->value;
        if ($term->hasField('field_death_cause_detail') && !$term->field_death_cause_detail->isEmpty()) {
          $label .= ': ' . $term->field_death_cause_detail->value;
        }
        $causes[$term->id()] = $label;
      }
    }
    asort($causes);
    return $causes;
  }

  /**
   * Get matching tracking numbers for autocomplete.
   *
   * @param string $string
   *   The string to match against.
   *
   * @return array
   *   Array of matching tracking numbers.
   */
  public function getTrackingNumberMatches($string) {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'species')
      ->condition('field_number', $string, 'CONTAINS')
      ->accessCheck(FALSE)
      ->range(0, 10);

    $entity_ids = $query->execute();
    $matches = [];

    if (!empty($entity_ids)) {
      $entities = $this->entityTypeManager->getStorage('node')->loadMultiple($entity_ids);
      foreach ($entities as $entity) {
        if (!$entity->field_number->isEmpty()) {
          $number = $entity->field_number->value;
          $matches[] = [
            'value' => $number,
            'label' => $number,
          ];
        }
      }
    }

    return $matches;
  }

  /**
   * Get matching species IDs for autocomplete.
   *
   * @param string $string
   *   The string to match against.
   *
   * @return array
   *   Array of matching species IDs.
   */
  public function getSpeciesIdMatches($string) {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'species_id')
      ->condition('field_species_id', $string, 'CONTAINS')
      ->accessCheck(FALSE)
      ->range(0, 10);

    $entity_ids = $query->execute();
    $matches = [];

    if (!empty($entity_ids)) {
      $entities = $this->entityTypeManager->getStorage('node')->loadMultiple($entity_ids);
      foreach ($entities as $entity) {
        if (!$entity->field_species_id->isEmpty()) {
          $species_id = $entity->field_species_id->value;
          $matches[] = [
            'value' => $species_id,
            'label' => $species_id,
          ];
        }
      }
    }

    return $matches;
  }

  /**
   * Get matching species names for autocomplete.
   *
   * @param string $string
   *   The string to match against.
   *
   * @return array
   *   Array of matching species names.
   */
  public function getSpeciesNameMatches($string) {
    // First, get all species nodes.
    $species_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'species')
      ->accessCheck(FALSE)
      ->execute();

    $matches = [];
    if (!empty($species_query)) {
      $species_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($species_query);

      foreach ($species_nodes as $species_node) {
        if ($species_node->hasField('field_names') && !$species_node->field_names->isEmpty()) {
          $names_paragraphs = $species_node->field_names->referencedEntities();
          foreach ($names_paragraphs as $paragraph) {
            if ($paragraph->hasField('field_name') && !$paragraph->field_name->isEmpty()) {
              $name = $paragraph->field_name->value;
              // Check if the name contains the search string.
              if (stripos($name, $string) !== FALSE) {
                $matches[] = [
                  'value' => $name,
                  'label' => $name,
                ];
              }
            }
          }
        }
      }

      // Remove duplicates and limit to 10 results.
      $matches = array_unique($matches, SORT_REGULAR);
      $matches = array_slice($matches, 0, 10);
    }

    return $matches;
  }

  /**
   * Get matching Tag IDs for autocomplete.
   *
   * @param string $string
   *   The string to match against Tag IDs.
   *
   * @return array
   *   Array of matching Tag IDs.
   */
  public function getTagIdMatches($string) {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'species_tag')
      ->condition('field_tag_id', '%' . $string . '%', 'LIKE')
      ->accessCheck(FALSE)
      ->range(0, 10);

    $entity_ids = $query->execute();
    $matches = [];

    if (!empty($entity_ids)) {
      $entities = $this->entityTypeManager->getStorage('node')->loadMultiple($entity_ids);
      foreach ($entities as $entity) {
        if (!$entity->field_tag_id->isEmpty()) {
          $tag_id = $entity->field_tag_id->value;
          $matches[] = [
            'value' => $tag_id,
            'label' => $tag_id,
          ];
        }
      }
    }

    return $matches;
  }

  /**
   * Get matching Waterways for autocomplete.
   *
   * @param string $string
   *   The string to match against Waterways.
   *
   * @return array
   *   Array of matching Waterways.
   */
  public function getWaterwayMatches($string) {
    // Query nodes of type 'species_rescue' where 'field_waterway' contains the input string.
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'species_rescue')
      ->condition('field_waterway', '%' . $string . '%', 'LIKE')
      ->accessCheck(FALSE)
    // Limit to 10 suggestions for performance.
      ->range(0, 10);

    $entity_ids = $query->execute();
    $matches = [];

    if (!empty($entity_ids)) {
      $entities = $this->entityTypeManager->getStorage('node')->loadMultiple($entity_ids);
      $waterways = [];

      foreach ($entities as $entity) {
        if (!$entity->field_waterway->isEmpty()) {
          $waterway = $entity->field_waterway->value;
          $waterways[] = $waterway;
        }
      }

      // Remove duplicates and sort the waterways.
      $waterways = array_unique($waterways);
      sort($waterways);

      // Prepare the matches array.
      foreach ($waterways as $waterway) {
        $matches[] = [
          'value' => $waterway,
          'label' => $waterway,
        ];
      }
    }

    return $matches;
  }

  /**
   * Build chronological events table for a species.
   *
   * @param int $species_id
   *   The species node ID.
   *
   * @return array
   *   A render array of the events table.
   */
  public function buildChronologicalEvents($species_id) {
    $event_types = [
      'species_birth' => [
        'date_field' => 'field_birth_date',
        'location_field' => 'field_org',
        'label' => $this->t('Birth'),
      ],
      'species_rescue' => [
        'date_field' => 'field_rescue_date',
        'location_field' => 'field_waterway',
        'label' => $this->t('Rescue'),
      ],
      'transfer' => [
        'date_field' => 'field_transfer_date',
        'location_field' => ['from' => 'field_from_facility', 'to' => 'field_to_facility'],
        'details_field' => 'field_reason',
        'label' => $this->t('Transfer'),
      ],
      'species_release' => [
        'date_field' => 'field_release_date',
        'location_field' => 'field_waterway',
        'label' => $this->t('Release'),
      ],
      'species_death' => [
        'date_field' => 'field_death_date',
        'location_field' => 'field_death_location',
        'label' => $this->t('Death'),
      ],
    ];

    $events = [];
    foreach ($event_types as $type => $config) {
      $query = $this->entityTypeManager->getStorage('node')->getQuery()
        ->condition('type', $type)
        ->condition('field_species_ref', $species_id)
        ->condition($config['date_field'], NULL, 'IS NOT NULL')
        ->accessCheck(FALSE)
        ->execute();

      if (!empty($query)) {
        $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($query);
        foreach ($nodes as $node) {
          $date_value = $node->get($config['date_field'])->value;

          // Get location based on event type.
          $location = 'N/A';

          if ($type === 'species_death') {
            // For death events, get location from the referenced taxonomy term.
            if (!$node->field_death_location->isEmpty()) {
              $term_id = $node->field_death_location->target_id;
              $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($term_id);
              if ($term && !$term->field_death_location->isEmpty()) {
                $location = $term->field_death_location->value;
              }
            }
          }
          elseif ($type === 'species_birth') {
            // For birth events, get organization from the referenced taxonomy term.
            if (!$node->field_org->isEmpty()) {
              $term_id = $node->field_org->target_id;
              $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($term_id);
              if ($term && !$term->field_organization->isEmpty()) {
                $location = $term->field_organization->value;
              }
            }
          }
          elseif ($type === 'transfer') {
            // For transfer events, get facility names from taxonomy terms.
            $from_facility = 'N/A';
            $to_facility = 'N/A';

            if (!$node->field_from_facility->isEmpty()) {
              $term_id = $node->field_from_facility->target_id;
              $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($term_id);
              if ($term) {
                $from_facility = $term->getName();
              }
            }

            if (!$node->field_to_facility->isEmpty()) {
              $term_id = $node->field_to_facility->target_id;
              $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($term_id);
              if ($term) {
                $to_facility = $term->getName();
              }
            }

            $location = "To: {$to_facility} From: {$from_facility}";
          }
          else {
            // For all other events, get location directly from waterway field.
            if (!$node->get($config['location_field'])->isEmpty()) {
              $location = $node->get($config['location_field'])->value;
            }
          }

          // Get event details.
          $details = '';
          if ($type === 'transfer' && !$node->field_reason->isEmpty()) {
            $term_id = $node->field_reason->target_id;
            $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($term_id);
            $details = 'Reason: ' . ($term && !$term->field_transfer_reason->isEmpty() ? $term->field_transfer_reason->value : 'N/A');
          }
          else {
            $details = $this->getEventDetails($node);
          }

          $events[] = [
            'date' => $date_value,
            'type' => $config['label'],
            'node_id' => $node->id(),
            'location' => $location,
            'details' => $details,
          ];
        }
      }
    }

    // Sort events by date in ascending order (oldest first).
    usort($events, function ($a, $b) {
      return strcmp($a['date'], $b['date']);
    });

    // Format the data for display.
    $rows = [];
    foreach ($events as $event) {
      $rows[] = [
        'date' => ['data' => ['#markup' => date('m/d/Y', strtotime($event['date']))]],
        'type' => [
          'data' => [
            '#type' => 'link',
            '#title' => $event['type'],
            '#url' => Url::fromRoute('entity.node.canonical', ['node' => $event['node_id']]),
          ],
        ],
        'details' => ['data' => ['#markup' => $event['details']]],
        'location' => ['data' => ['#markup' => $event['location']]],
      ];
    }

    // Build and return the table.
    return [
      '#type' => 'table',
      '#header' => [
        $this->t('Date'),
        $this->t('Event'),
        $this->t('Details'),
        $this->t('Location'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No events found'),
      '#attributes' => ['class' => ['species-events-table']],
    ];
  }

  /**
   * Get event-specific details based on node type.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   *
   * @return string
   *   Formatted details string.
   */
  protected function getEventDetails(NodeInterface $node) {
    $details = '';

    switch ($node->bundle()) {
      case 'species_birth':
        if ($node->hasField('field_species_ref') && !$node->field_species_ref->isEmpty()) {
          $species_node = $this->entityTypeManager->getStorage('node')->load($node->field_species_ref->target_id);
          if ($species_node && $species_node->hasField('field_dam') && !$species_node->field_dam->isEmpty()) {
            $dam_id = $species_node->field_dam->target_id;
            $dam_node = $this->entityTypeManager->getStorage('node')->load($dam_id);
            if ($dam_node) {
              $dam_name = $this->getPrimaryName($dam_node->id());
              $details = $this->t('Name of Dam: @name', ['@name' => $dam_name]);
            }
          }
        }
        break;

      case 'species_death':
        if ($node->hasField('field_cause_id') && !$node->field_cause_id->isEmpty()) {
          $cause_id = $node->field_cause_id->target_id;
          $cause_term = $this->entityTypeManager->getStorage('taxonomy_term')->load($cause_id);
          if ($cause_term && $cause_term->hasField('field_death_cause') && !$cause_term->field_death_cause->isEmpty()) {
            $details = $this->t('Cause: @cause', ['@cause' => $cause_term->field_death_cause->value]);
          }
        }
        break;

      case 'species_rescue':
        if ($node->hasField('field_org') && !$node->field_org->isEmpty()) {
          $org_name = 'N/A';
          $primary_cause = 'N/A';

          $org_id = $node->field_org->target_id;
          $org_name = $this->getOrganizationName($org_id);

          if ($node->hasField('field_primary_cause') && !$node->field_primary_cause->isEmpty()) {
            $cause_id = $node->field_primary_cause->target_id;
            $cause_term = $this->entityTypeManager->getStorage('taxonomy_term')->load($cause_id);
            if ($cause_term && $cause_term->hasField('field_rescue_cause') && !$cause_term->field_rescue_cause->isEmpty()) {
              $primary_cause = $cause_term->field_rescue_cause->value;
            }
          }

          $details = $this->t('To: @org (@cause)', [
            '@org' => $org_name,
            '@cause' => $primary_cause,
          ]);
        }
        else {
          $rescue_type = 'N/A';
          if ($node->hasField('field_rescue_type') && !$node->field_rescue_type->isEmpty()) {
            $type_id = $node->field_rescue_type->target_id;
            $type_term = $this->entityTypeManager->getStorage('taxonomy_term')->load($type_id);
            if ($type_term && $type_term->hasField('field_rescue_type_text') && !$type_term->field_rescue_type_text->isEmpty()) {
              $rescue_type = $type_term->field_rescue_type_text->value;
            }
          }
          $details = $rescue_type;
        }
        break;

      case 'species_release':
        if ($node->hasField('field_species_ref') && !$node->field_species_ref->isEmpty()) {
          $species_ref = $node->field_species_ref->target_id;

          // Find the prerelease node with the same species_ref.
          $prerelease_query = $this->entityTypeManager->getStorage('node')->getQuery()
            ->condition('type', 'species_prerelease')
            ->condition('field_species_ref', $species_ref)
            ->accessCheck(FALSE)
            ->execute();

          if (!empty($prerelease_query)) {
            $prerelease_node = $this->entityTypeManager->getStorage('node')->load(reset($prerelease_query));
            if ($prerelease_node && $prerelease_node->hasField('field_org') && !$prerelease_node->field_org->isEmpty()) {
              $org_id = $prerelease_node->field_org->target_id;
              $org_name = $this->getOrganizationName($org_id);
              $details = $this->t('From: @org', ['@org' => $org_name]);
            }
          }
        }
        break;

      default:
        $details = 'N/A';
    }

    return empty($details) ? 'N/A' : $details;
  }

  /**
   * Helper function to get organization name from term ID.
   *
   * @param int $org_id
   *   The organization term ID.
   *
   * @return string
   *   The organization name or 'N/A' if not found.
   */
  protected function getOrganizationName($org_id) {
    $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($org_id);
    if ($term && $term->hasField('field_organization') && !$term->field_organization->isEmpty()) {
      return $term->field_organization->value;
    }
    return 'N/A';
  }

  /**
   * Get primary species ID for a species node.
   *
   * @param int $species_node_id
   *   The species node ID.
   *
   * @return string|null
   *   The primary species ID value, or null if not found.
   */
  public function getPrimarySpeciesId($species_node_id) {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'species_id')
      ->condition('field_species_ref', $species_node_id)
      ->condition('field_primary_id', 1)
      ->accessCheck(FALSE)
      ->range(0, 1)
      ->execute();

    if (!empty($query)) {
      $species_id_node = $this->entityTypeManager->getStorage('node')->load(reset($query));
      if ($species_id_node && !$species_id_node->field_species_id->isEmpty()) {
        return $species_id_node->field_species_id->value;
      }
    }

    return NULL;
  }

}
