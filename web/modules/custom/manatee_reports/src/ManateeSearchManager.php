<?php

namespace Drupal\manatee_reports;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
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
      'manatee_birth' => $this->t('Birth'),
      'manatee_rescue' => $this->t('Rescue'),
      'transfer' => $this->t('Transfer'),
      'manatee_release' => $this->t('Release'),
      'manatee_death' => $this->t('Death'),
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
            ->condition('field_tag_id', $condition['value']);
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

        case 'field_tag_type':
          if (!empty($condition['value']) && $condition['value'] !== 'All') {
            $tag_query = $this->entityTypeManager->getStorage('node')->getQuery()
              ->condition('type', 'manatee_tag')
              ->condition('field_tag_type', $condition['value'])
              ->condition('field_animal', NULL, 'IS NOT NULL')
              ->accessCheck(FALSE);

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
          }
          break;

        case 'field_waterway':
          $rescue_query = $this->entityTypeManager->getStorage('node')->getQuery()
            ->condition('type', 'manatee_rescue')
            ->condition('field_waterway', '%' . $condition['value'] . '%', 'LIKE')
            ->condition('field_animal', NULL, 'IS NOT NULL')
            ->accessCheck(FALSE);

          $rescue_matches = $rescue_query->execute();
          if (!empty($rescue_matches)) {
            $rescue_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($rescue_matches);
            $manatee_ids = [];
            foreach ($rescue_nodes as $rescue_node) {
              if (!$rescue_node->field_animal->isEmpty()) {
                $manatee_ids[] = $rescue_node->field_animal->target_id;
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
          $event_type = $condition['value'];
          $date_fields = [
            'manatee_birth' => 'field_birth_date',
            'manatee_rescue' => 'field_rescue_date',
            'transfer' => 'field_transfer_date',
            'manatee_release' => 'field_release_date',
            'manatee_death' => 'field_death_date',
          ];

          $event_query = $this->entityTypeManager->getStorage('node')->getQuery()
            ->condition('type', $event_type)
            ->condition('field_animal', NULL, 'IS NOT NULL')
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

        case 'field_county':
        case 'field_state':
          $rescue_query = $this->entityTypeManager->getStorage('node')->getQuery()
            ->condition('type', 'manatee_rescue')
            ->condition('field_state', $condition['value'])
            ->condition('field_animal', NULL, 'IS NOT NULL')
            ->accessCheck(FALSE);

          $rescue_matches = $rescue_query->execute();
          if (!empty($rescue_matches)) {
            $rescue_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($rescue_matches);
            $manatee_ids = [];
            foreach ($rescue_nodes as $rescue_node) {
              if (!$rescue_node->field_animal->isEmpty()) {
                $manatee_ids[] = $rescue_node->field_animal->target_id;
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

        case 'field_rescue_type':
          if (!empty($condition['value']) && $condition['value'] !== 'All') {
            $rescue_query = $this->entityTypeManager->getStorage('node')->getQuery()
              ->condition('type', 'manatee_rescue')
              ->condition('field_rescue_type', $condition['value'])
              ->condition('field_animal', NULL, 'IS NOT NULL')
              ->accessCheck(FALSE);

            $rescue_matches = $rescue_query->execute();

            if (!empty($rescue_matches)) {
              $rescue_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($rescue_matches);
              $manatee_ids = [];
              foreach ($rescue_nodes as $rescue_node) {
                if (!$rescue_node->field_animal->isEmpty()) {
                  $manatee_ids[] = $rescue_node->field_animal->target_id;
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
          }
          break;

        case 'field_rescue_cause':
          $rescue_query = $this->entityTypeManager->getStorage('node')->getQuery()
            ->condition('type', 'manatee_rescue')
            ->condition('field_animal', NULL, 'IS NOT NULL')
            ->accessCheck(FALSE);

          $or_group = $rescue_query->orConditionGroup()
            ->condition('field_primary_cause', $condition['value'])
            ->condition('field_secondary_cause', $condition['value']);

          $rescue_query->condition($or_group);

          $rescue_matches = $rescue_query->execute();

          if (!empty($rescue_matches)) {
            $rescue_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($rescue_matches);
            $manatee_ids = [];
            foreach ($rescue_nodes as $rescue_node) {
              if (!$rescue_node->field_animal->isEmpty()) {
                $manatee_ids[] = $rescue_node->field_animal->target_id;
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

        case 'field_organization':
          $event_types = [
            ['type' => 'manatee_birth', 'field' => 'field_org'],
            ['type' => 'manatee_death', 'field' => 'field_org'],
            ['type' => 'manatee_release', 'field' => 'field_org'],
            ['type' => 'manatee_rescue', 'field' => 'field_org'],
            ['type' => 'transfer', 'field' => 'field_from_facility'],
            ['type' => 'transfer', 'field' => 'field_to_facility'],
          ];

          $event_queries = [];
          foreach ($event_types as $event) {
            $event_query = $this->entityTypeManager->getStorage('node')->getQuery()
              ->condition('type', $event['type'])
              ->condition($event['field'], $condition['value'])
              ->condition('field_animal', NULL, 'IS NOT NULL')
              ->accessCheck(FALSE);

            $event_matches = $event_query->execute();
            if (!empty($event_matches)) {
              $event_nodes = $this->entityTypeManager->getStorage('node')
                ->loadMultiple($event_matches);
              foreach ($event_nodes as $event_node) {
                if (!$event_node->field_animal->isEmpty()) {
                  $event_queries[] = $event_node->field_animal->target_id;
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
              ->condition('type', 'manatee_death')
              ->condition('field_cause_id', $condition['value'])
              ->condition('field_animal', NULL, 'IS NOT NULL')
              ->accessCheck(FALSE);

            $death_matches = $death_query->execute();
            if (!empty($death_matches)) {
              $death_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($death_matches);
              $manatee_ids = [];
              foreach ($death_nodes as $death_node) {
                if (!$death_node->field_animal->isEmpty()) {
                  $manatee_ids[] = $death_node->field_animal->target_id;
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
   * Get latest event for a manatee.
   */
  public function getLatestEvent($manatee_id, $specific_type = NULL) {
    $event_types = [
      'manatee_birth' => 'field_birth_date',
      'manatee_rescue' => 'field_rescue_date',
      'transfer' => 'field_transfer_date',
      'manatee_release' => 'field_release_date',
      'manatee_death' => 'field_death_date',
    ];

    if ($specific_type) {
      if (isset($event_types[$specific_type])) {
        $date_field = $event_types[$specific_type];
        $query = $this->entityTypeManager->getStorage('node')->getQuery()
          ->condition('type', $specific_type)
          ->condition('field_animal', $manatee_id)
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
              'type' => ucfirst(str_replace(['manatee_', '_'], ['', ' '], $specific_type)),
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
        ->condition('field_animal', $manatee_id)
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
            'type' => str_replace('manatee_', '', $type),
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
    $manatee_ids = $this->searchManatees($conditions);
    $total_items = count($manatee_ids);

    $pager = \Drupal::service('pager.manager')->createPager($total_items, $items_per_page);
    $current_page = $pager->getCurrentPage();

    $page_manatee_ids = array_slice($manatee_ids, $current_page * $items_per_page, $items_per_page);

    if (empty($page_manatee_ids)) {
      return [
        '#markup' => '<div class="no-results">' . $this->t('No manatees found matching your search criteria.') . '</div>',
      ];
    }

    $manatees = $this->entityTypeManager->getStorage('node')->loadMultiple($page_manatee_ids);

    // Extract event type from conditions if it exists.
    $event_type = NULL;
    foreach ($conditions as $condition) {
      if ($condition['field'] === 'type') {
        $event_type = $condition['value'];
        break;
      }
    }

    $rows = [];
    foreach ($manatees as $manatee) {
      $manatee_id = $manatee->id();
      $latest_event = $this->getLatestEvent($manatee_id, $event_type);

      $mlog = $this->getMlog($manatee);
      $mlog_link = Link::createFromRoute(
        $mlog,
        'entity.node.canonical',
        ['node' => $manatee_id]
      );

      $rows[] = [
        'data' => [
          ['data' => $mlog_link],
          ['data' => $this->getPrimaryName($manatee_id)],
          ['data' => $this->getAnimalId($manatee_id)],
          ['data' => $latest_event['type']],
          ['data' => $latest_event['date']],
        ],
      ];
    }

    $header = [
      $this->t('MLog'),
      $this->t('Name'),
      $this->t('Animal ID'),
      $this->t('Event'),
      $this->t('Last Event'),
    ];

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['manatee-search-results']],
      'count' => [
        '#markup' => $this->t('@count manatees found', ['@count' => $total_items]),
        '#prefix' => '<div class="results-count">',
        '#suffix' => '</div>',
      ],
      'table' => [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => $this->t('No results found'),
        '#attributes' => ['class' => ['manatee-report-table']],
      ],
      'pager' => [
        '#type' => 'pager',
      ],
    ];
  }

  /**
   * Get MLog value for a manatee.
   */
  public function getMlog($manatee) {
    return !$manatee->field_mlog->isEmpty() ? $manatee->field_mlog->value : 'N/A';
  }

  /**
   * Get primary name for a manatee.
   */
  public function getPrimaryName($manatee_id) {
    $name_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'manatee_name')
      ->condition('field_animal', $manatee_id)
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
   * Get animal ID for a manatee.
   */
  public function getAnimalId($manatee_id) {
    $id_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'manatee_animal_id')
      ->condition('field_animal', $manatee_id)
      ->accessCheck(FALSE)
      ->execute();

    if (!empty($id_query)) {
      $id_node = $this->entityTypeManager->getStorage('node')->load(reset($id_query));
      if ($id_node && !$id_node->field_animal_id->isEmpty()) {
        return $id_node->field_animal_id->value;
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
