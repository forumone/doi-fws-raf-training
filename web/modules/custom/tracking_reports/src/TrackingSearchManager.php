<?php

namespace Drupal\tracking_reports;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Utility\TableSort;

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
      $types[$term->id()] = $term->label();
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
          $species_id_query = $this->entityTypeManager->getStorage('node')->getQuery()
            ->condition('type', 'species_id')
            ->condition('field_species_ref', $condition['value'], '=')
            ->accessCheck(FALSE);
          $species_ids = $species_id_query->execute();

          if (!empty($species_ids)) {
            $species_id_node = $this->entityTypeManager->getStorage('node')->load(reset($species_ids));
            if (!$species_id_node->field_species_ref->isEmpty()) {
              $query->condition('nid', $species_id_node->field_species_ref->target_id);
            }
          }
          break;

        case 'field_name':
          $name_query = $this->entityTypeManager->getStorage('node')->getQuery()
            ->condition('type', 'species_name')
            ->condition('field_name', '%' . $condition['value'] . '%', 'LIKE')
            ->accessCheck(FALSE);
          $name_matches = $name_query->execute();
          if (!empty($name_matches)) {
            $name_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($name_matches);
            $species_ids = [];
            foreach ($name_nodes as $name_node) {
              if (!$name_node->field_species_ref->isEmpty()) {
                $species_ids[] = $name_node->field_species_ref->target_id;
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

    if ($specific_type) {
      if (isset($event_types[$specific_type])) {
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
            ];
          }
        }
        return ['type' => 'N/A', 'date' => 'N/A'];
      }
    }

    $events = [];
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
          $formatted_date = date('m/d/Y', strtotime($date_value));
          $events[] = [
            'type' => str_replace('species_', '', $type),
            'date' => $formatted_date,
          ];
        }
      }
    }

    if (empty($events)) {
      return ['type' => 'N/A', 'date' => 'N/A'];
    }

    usort($events, function ($a, $b) {
      return strcmp($b['date'], $a['date']);
    });

    $latest = $events[0];
    return [
      'type' => ucfirst(str_replace('_', ' ', $latest['type'])),
      'date' => $latest['date'],
    ];
  }

  /**
   * Build search results render array with header-based sorting.
   *
   * @param array $conditions
   *   The conditions array from processSearchParameters().
   *
   * @return array
   *   A render array of the search results table with header-based sorting.
   */
  public function buildSearchResults(array $conditions) {
    $species_ids = $this->searchSpecies($conditions);
    $total_items = count($species_ids);

    // Early return if no matches.
    if (empty($species_ids)) {
      return [
        '#markup' => '<div class="no-results">' . $this->t('No results found matching your search criteria.') . '</div>',
      ];
    }

    // Identify the event_type from conditions, if present.
    $event_type = NULL;
    foreach ($conditions as $condition) {
      if ($condition['field'] === 'type') {
        $event_type = $condition['value'];
        break;
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
        'data' => $this->t('Species') . ' ' . 'ID',
        'field' => 'species_id_value',
      ],
      'latest_event_type' => [
        'data' => $this->t('Event'),
        'field' => 'latest_event_type',
      ],
      'latest_event_date' => [
        'data' => $this->t('Last Event'),
        'field' => 'latest_event_date',
      // Set default sort.
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

      $data[] = [
        'species_id' => $sid,
        'number' => $this->getNumber($species_entity),
        'species_name' => $this->getPrimaryName($sid),
        'species_id_value' => $this->getSpeciesId($sid),
        'latest_event_type' => $latest_event['type'],
        'latest_event_date' => $latest_event['date'],
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
      'species_birth' => $this->t('Add Birth'),
      'species_rescue' => $this->t('Add Rescue'),
      'transfer' => $this->t('Add Transfer'),
      'species_release' => $this->t('Add Release'),
      'species_death' => $this->t('Add Death'),
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

      $rows[] = [
        'data' => [
          'number' => ['data' => $number_link],
          'species_name' => ['data' => $row['species_name']],
          'species_id_value' => ['data' => $row['species_id_value']],
          'latest_event_type' => ['data' => $row['latest_event_type']],
          'latest_event_date' => ['data' => $row['latest_event_date']],
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
    $name_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'species_name')
      ->condition('field_species_ref', $species_id)
      ->condition('field_primary', 1)
      ->accessCheck(FALSE)
      ->execute();

    if (!empty($name_query)) {
      $name_node = $this->entityTypeManager->getStorage('node')->load(reset($name_query));
      if ($name_node && !$name_node->field_name->isEmpty()) {
        return $name_node->field_name->value;
      }
    }
    return 'N/A';
  }

  /**
   * Get species ID for a species.
   */
  public function getSpeciesId($species_id) {
    $id_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'species_id')
      ->condition('field_species_ref', $species_id)
      ->accessCheck(FALSE)
      ->execute();

    if (!empty($id_query)) {
      $id_node = $this->entityTypeManager->getStorage('node')->load(reset($id_query));
      if ($id_node && !$id_node->field_species_id->isEmpty()) {
        return $id_node->field_species_id->value;
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
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'species_name')
      ->condition('field_name', $string, 'CONTAINS')
      ->accessCheck(FALSE)
      ->range(0, 10);

    $entity_ids = $query->execute();
    $matches = [];

    if (!empty($entity_ids)) {
      $entities = $this->entityTypeManager->getStorage('node')->loadMultiple($entity_ids);
      foreach ($entities as $entity) {
        if (!$entity->field_name->isEmpty()) {
          $name = $entity->field_name->value;
          $matches[] = [
            'value' => $name,
            'label' => $name,
          ];
        }
      }
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

}
