<?php

namespace Drupal\tracking_reports\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Utility\TableSort;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Controller for displaying current captive species by facility.
 */
class CurrentCaptivesController extends ControllerBase {

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
   * Constructs a CurrentCaptivesController object.
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
   * Returns the page content.
   *
   * @return array
   *   Render array for the page.
   */
  public function content() {
    // Get facility terms.
    $facility_terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties([
      'vid' => 'org',
    ]);

    // Build facility options.
    $facility_options = ['all' => $this->t('- All Facilities -')];
    foreach ($facility_terms as $term) {
      if ($term->hasField('field_organization') && !$term->field_organization->isEmpty()) {
        $facility_options[$term->field_organization->value] = $term->get('field_organization')->value;
      }
    }

    // Build the filter form.
    $form = [
      '#type' => 'container',
      '#attributes' => ['class' => ['tracking-filter-form']],
      'facility' => [
        '#type' => 'select',
        '#title' => $this->t('Filter by Facility:'),
        '#options' => $facility_options,
        '#default_value' => 'all',
        '#attributes' => [
          'class' => ['facility-filter'],
        ],
      ],
    ];

    // Get deceased species IDs.
    $death_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'species_death')
      ->condition('field_species_ref', NULL, 'IS NOT NULL')
      ->accessCheck(FALSE)
      ->execute();

    $deceased_ids = [];
    if (!empty($death_query)) {
      $death_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($death_query);
      foreach ($death_nodes as $death_node) {
        if ($death_node->hasField('field_species_ref') && !$death_node->field_species_ref->isEmpty()) {
          $deceased_ids[] = $death_node->field_species_ref->target_id;
        }
      }
    }

    // Get primary names.
    $name_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'species_name')
      ->condition('field_species_ref', NULL, 'IS NOT NULL')
      ->condition('field_primary', 1)
      ->accessCheck(FALSE)
      ->execute();

    $primary_names = [];
    if (!empty($name_query)) {
      $name_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($name_query);
      foreach ($name_nodes as $name_node) {
        if ($name_node->hasField('field_species_ref') && !$name_node->field_species_ref->isEmpty()) {
          $species_id = $name_node->field_species_ref->target_id;
          if ($name_node->hasField('field_name') && !$name_node->field_name->isEmpty()) {
            $primary_names[$species_id] = $name_node->field_name->value;
          }
        }
      }
    }

    // Get species IDs.
    $species_id_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'species_id')
      ->condition('field_species_ref', NULL, 'IS NOT NULL')
      ->accessCheck(FALSE)
      ->execute();

    $species_ids = [];
    if (!empty($species_id_query)) {
      $species_id_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($species_id_query);
      foreach ($species_id_nodes as $species_id_node) {
        if ($species_id_node->hasField('field_species_ref') && !$species_id_node->field_species_ref->isEmpty()) {
          $species_id = $species_id_node->field_species_ref->target_id;
          if ($species_id_node->hasField('field_species_ref') && !$species_id_node->field_species_ref->isEmpty()) {
            $species_ids[$species_id] = $species_id_node->field_species_ref->value;
          }
        }
      }
    }

    // Get rescue events.
    $rescue_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'species_rescue')
      ->condition('field_species_ref', NULL, 'IS NOT NULL')
      ->condition('field_rescue_date', NULL, 'IS NOT NULL')
      ->accessCheck(FALSE)
      ->execute();

    $rescue_types = [];
    $type_b_rescue_dates = [];
    if (!empty($rescue_query)) {
      $rescue_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($rescue_query);
      $species_rescues = [];

      foreach ($rescue_nodes as $rescue_node) {
        if ($rescue_node->hasField('field_species_ref') && !$rescue_node->field_species_ref->isEmpty()) {
          $species_id = $rescue_node->field_species_ref->target_id;
          $date = $rescue_node->field_rescue_date->value;
          $rescue_type = '';
          if ($rescue_node->hasField('field_rescue_type') && !$rescue_node->field_rescue_type->isEmpty()) {
            $rescue_type = $rescue_node->field_rescue_type->entity->getName();
          }

          $species_rescues[$species_id][] = [
            'date' => $date,
            'type' => $rescue_type,
          ];

          if ($rescue_type === 'B') {
            if (!isset($type_b_rescue_dates[$species_id]) || $date > $type_b_rescue_dates[$species_id]) {
              $type_b_rescue_dates[$species_id] = $date;
            }
          }
        }
      }

      foreach ($species_rescues as $species_id => $rescues) {
        usort($rescues, function ($a, $b) {
          return strcmp($b['date'], $a['date']);
        });
        $rescue_types[$species_id] = $rescues[0]['type'];
      }
    }

    // Get birth dates.
    $birth_dates = [];
    $birth_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'species_birth')
      ->condition('field_species_ref', NULL, 'IS NOT NULL')
      ->condition('field_birth_date', NULL, 'IS NOT NULL')
      ->accessCheck(FALSE)
      ->execute();

    if (!empty($birth_query)) {
      $birth_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($birth_query);
      foreach ($birth_nodes as $birth_node) {
        if ($birth_node->hasField('field_species_ref') && !$birth_node->field_species_ref->isEmpty()) {
          $species_id = $birth_node->field_species_ref->target_id;
          $birth_dates[$species_id] = $birth_node->field_birth_date->value;
        }
      }
    }

    // Define event types.
    $event_types = [
      'species_birth' => 'field_birth_date',
      'species_rescue' => 'field_rescue_date',
      'transfer' => 'field_transfer_date',
      'species_release' => 'field_release_date',
    ];

    // Get all species with MLOGs that aren't deceased.
    $species_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'species')
      ->condition('field_number', NULL, 'IS NOT NULL');

    if (!empty($deceased_ids)) {
      $species_query->condition('nid', $deceased_ids, 'NOT IN');
    }

    $species_ids = $species_query->accessCheck(FALSE)->execute();

    // Get all events.
    $event_nodes = [];
    foreach ($event_types as $type => $date_field) {
      $query = $this->entityTypeManager->getStorage('node')->getQuery()
        ->condition('type', $type)
        ->condition('field_species_ref', $species_ids, 'IN')
        ->condition('field_species_ref', NULL, 'IS NOT NULL')
        ->condition($date_field, NULL, 'IS NOT NULL')
        ->accessCheck(FALSE);

      $results = $query->execute();

      if (!empty($results)) {
        $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($results);
        foreach ($nodes as $node) {
          if ($node->hasField('field_species_ref') && !$node->field_species_ref->isEmpty()) {
            $species_id = $node->field_species_ref->target_id;
            $date_value = $node->get($date_field)->value;

            $organization = '';
            if ($type === 'transfer' && $node->hasField('field_to_facility') && !$node->field_to_facility->isEmpty()) {
              $facility_term = $node->field_to_facility->entity;
              if ($facility_term && $facility_term->hasField('field_organization')) {
                $organization = $facility_term->field_organization->value ?? '';
              }
            }
            elseif ($node->hasField('field_org') && !$node->field_org->isEmpty()) {
              $facility_term = $node->field_org->entity;
              if ($facility_term && $facility_term->hasField('field_organization')) {
                $organization = $facility_term->field_organization->value ?? '';
              }
            }

            $event_nodes[$species_id][] = [
              'nid' => $node->id(),
              'type' => $type,
              'date' => $date_value,
              'date_field' => $date_field,
              'organization' => $organization,
            ];
          }
        }
      }
    }

    // Process events.
    $most_recent_events = [];
    foreach ($species_ids as $species_id) {
      if (isset($event_nodes[$species_id])) {
        usort($event_nodes[$species_id], function ($a, $b) {
          return strcmp($b['date'], $a['date']);
        });

        if ($event_nodes[$species_id][0]['type'] !== 'species_release') {
          $most_recent_events[$species_id] = $event_nodes[$species_id][0];
        }
      }
    }

    // Load species.
    $species = $this->entityTypeManager->getStorage('node')->loadMultiple($species);
    $current_date = new DrupalDateTime();

    // Prepare rows.
    $rows = [];
    foreach ($species as $species_entity) {
      if (!isset($most_recent_events[$species_entity->id()])) {
        continue;
      }

      $event = $most_recent_events[$species_entity->id()];

      $number = "N/A";
      $number_num = PHP_INT_MAX;
      if ($species_entity->hasField('field_number') && !$species_entity->field_number->isEmpty()) {
        $number_value = $species_entity->get('field_number')->getValue();
        $number = $number_value[0]['value'] ?? "N/A";
        if (preg_match('/(\d+)/', $number, $matches)) {
          $number_num = intval($matches[0]);
        }
      }

      // Create a link for the MLOG value.
      $number_link = Link::createFromRoute(
        $number,
        'entity.node.canonical',
        ['node' => $species_entity->id()]
      );

      $event_type = str_replace('species_', '', $event['type']);
      $event_type = str_replace('_', ' ', $event_type);
      $event_type = ucfirst($event_type);

      $date = new DrupalDateTime($event['date']);
      $formatted_date = $date->format('Y-m-d');

      $name = $primary_names[$species_entity->id()] ?? '';
      $species_id = $species_ids[$species_entity->id()] ?? '';
      $rescue_type = $rescue_types[$species_entity->id()] ?? 'none';

      $captivity_date = NULL;
      if (isset($type_b_rescue_dates[$species_entity->id()])) {
        $captivity_date = new DrupalDateTime($type_b_rescue_dates[$species_entity->id()]);
      }
      elseif (isset($birth_dates[$species_entity->id()])) {
        $captivity_date = new DrupalDateTime($birth_dates[$species_entity->id()]);
      }

      $days_in_captivity = NULL;
      if ($captivity_date) {
        $interval = $current_date->diff($captivity_date);
        $days_in_captivity = $interval->days;
      }

      if ($rescue_type === 'B' || $rescue_type === 'none') {
        $rows[] = [
          'data' => [
            ['data' => $number_link],
            ['data' => $name],
            ['data' => $species_id],
            ['data' => $event_type],
            ['data' => $formatted_date],
            ['data' => $days_in_captivity ?? 'N/A'],
            ['data' => $event['organization']],
          ],
          'data-facility' => $event['organization'],
          'number_num' => $number_num,
        ];
      }
    }

    // Prepare table headers with sorting.
    $header = [
      'number' => [
        'data' => $this->t('Tracking Number'),
        'field' => 'number',
        'sort' => 'asc',
        'class' => [RESPONSIVE_PRIORITY_LOW],
      ],
      'name' => [
        'data' => $this->t('Name'),
        'field' => 'name',
        'class' => [RESPONSIVE_PRIORITY_MEDIUM],
      ],
      'species_id' => [
        'data' => $this->t('Animal ID'),
        'field' => 'species_id',
        'class' => [RESPONSIVE_PRIORITY_MEDIUM],
      ],
      'event' => [
        'data' => $this->t('Event'),
        'field' => 'event',
        'class' => [RESPONSIVE_PRIORITY_MEDIUM],
      ],
      'event_date' => [
        'data' => $this->t('Event Date'),
        'field' => 'event_date',
        'class' => [RESPONSIVE_PRIORITY_LOW],
      ],
      'days_captive' => [
        'data' => $this->t('# Days in Captivity'),
        'field' => 'days_captive',
        'class' => [RESPONSIVE_PRIORITY_LOW],
      ],
      'facility' => [
        'data' => $this->t('Facility'),
        'field' => 'facility',
        'class' => [RESPONSIVE_PRIORITY_LOW],
      ],
    ];

    // Get current request for table sort.
    $request = $this->requestStack->getCurrentRequest();

    // Get the sort parameters using TableSort.
    $order = TableSort::getOrder($header, $request);
    $sort = TableSort::getSort($header, $request);
    $dir = ($sort == 'desc') ? SORT_DESC : SORT_ASC;

    // Sort rows based on the selected column.
    if (isset($order['sql'])) {
      $field = $order['sql'];

      // Create a comparison function based on the selected field.
      $compare = function ($a, $b) use ($field, $dir) {
        $a_val = '';
        $b_val = '';

        switch ($field) {
          case 'number':
            $a_val = $a['number_num'];
            $b_val = $b['number_num'];
            break;

          case 'name':
            $a_val = strtolower($a['data'][1]['data']);
            $b_val = strtolower($b['data'][1]['data']);
            break;

          case 'species_id':
            $a_val = strtolower($a['data'][2]['data']);
            $b_val = strtolower($b['data'][2]['data']);
            break;

          case 'event':
            $a_val = strtolower($a['data'][3]['data']);
            $b_val = strtolower($b['data'][3]['data']);
            break;

          case 'event_date':
            $a_val = strtotime($a['data'][4]['data']);
            $b_val = strtotime($b['data'][4]['data']);
            break;

          case 'days_captive':
            $a_val = is_numeric($a['data'][5]['data']) ? (int) $a['data'][5]['data'] : PHP_INT_MAX;
            $b_val = is_numeric($b['data'][5]['data']) ? (int) $b['data'][5]['data'] : PHP_INT_MAX;
            break;

          case 'facility':
            $a_val = strtolower($a['data'][6]['data']);
            $b_val = strtolower($b['data'][6]['data']);
            break;
        }

        if ($a_val == $b_val) {
          return 0;
        }
        return ($dir == SORT_ASC ? 1 : -1) * ($a_val < $b_val ? -1 : 1);
      };

      usort($rows, $compare);
    }

    // Build table with sorting enabled.
    $table = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => array_map(function ($row) {
        return [
          'data' => $row['data'],
          'data-facility' => $row['data-facility'],
        ];
      }, $rows),
      '#empty' => $this->t('No results found'),
      '#attributes' => ['class' => ['tracking-report-table']],
    ];

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['tracking-report-container']],
      'filters' => $form,
      'table' => $table,
      '#attached' => [
        'library' => [
          'tracking_reports/tracking_reports',
        ],
      ],
    ];
  }

}
