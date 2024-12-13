<?php

namespace Drupal\tracking_reports;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\StringTranslation\StringTranslationTrait;

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
   * Build search results render array.
   */
  public function buildSearchResults($conditions) {
    $items_per_page = 20;
    $species_ids = $this->searchSpecies($conditions);
    $total_items = count($species_ids);

    $pager = \Drupal::service('pager.manager')->createPager($total_items, $items_per_page);
    $current_page = $pager->getCurrentPage();

    $page_species_ids = array_slice($species_ids, $current_page * $items_per_page, $items_per_page);

    if (empty($page_species_ids)) {
      return [
        '#markup' => '<div class="no-results">' . $this->t('No results found matching your search criteria.') . '</div>',
      ];
    }

    $species = $this->entityTypeManager->getStorage('node')->loadMultiple($page_species_ids);

    // Extract event type from conditions if it exists.
    $event_type = NULL;
    foreach ($conditions as $condition) {
      if ($condition['field'] === 'type') {
        $event_type = $condition['value'];
        break;
      }
    }

    $rows = [];
    foreach ($species as $species_entity) {
      $species_id = $species_entity->id();
      $latest_event = $this->getLatestEvent($species_id, $event_type);

      $number = $this->getNumber($species_entity);
      $number_link = Link::createFromRoute(
        $number,
        'entity.node.canonical',
        ['node' => $species_id]
      );

      $rows[] = [
        'data' => [
          ['data' => $number_link],
          ['data' => $this->getPrimaryName($species_id)],
          ['data' => $this->getSpeciesId($species_id)],
          ['data' => $latest_event['type']],
          ['data' => $latest_event['date']],
        ],
      ];
    }

    $header = [
      $this->t('Tracking Number'),
      $this->t('Name'),
      $this->t('Species ID'),
      $this->t('Event'),
      $this->t('Last Event'),
    ];

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['species-search-results']],
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

}
