<?php

namespace Drupal\tracking_reports\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Controller for displaying current captive species by facility.
 */
class FacilityInventoryController extends ControllerBase {

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
   * Constructs a FacilityInventoryController object.
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
   * Calculate time in captivity in years and months through end of filtered year.
   *
   * @param \Drupal\Core\Datetime\DrupalDateTime $start_date
   *   The start date.
   * @param string $year
   *   The filtered year.
   *
   * @return string
   *   Formatted string showing years and/or months.
   */
  protected function calculateTimeInCaptivity(DrupalDateTime $start_date, $year) {
    // Create end date as December 31st of the filtered year.
    $end_date = new DrupalDateTime($year . '-12-31');

    $interval = $end_date->diff($start_date);
    $years = $interval->y;
    $months = $interval->m;

    if ($years > 0) {
      if ($months > 0) {
        return sprintf('%d yr, %d mo', $years, $months);
      }
      return sprintf('%d yr', $years);
    }
    return sprintf('%d mo', $months);
  }

  /**
   * Returns the year options for the filter.
   *
   * @return array
   *   Array of year options.
   */
  protected function getYearOptions() {
    $current_year = date('Y');
    return [
      $current_year - 1 => $current_year - 1,
      $current_year => $current_year,
    ];
  }

  /**
   * Content callback for the report page.
   */
  public function content() {
    $request = $this->requestStack->getCurrentRequest();

    // Get selected year from query parameter or default to previous year.
    $year_options = $this->getYearOptions();
    $default_year = date('Y') - 1;
    $year = $request->query->get('year', $default_year);

    // Ensure year is within available options.
    if (!isset($year_options[$year])) {
      $year = $default_year;
    }

    $year_start = $year . '-01-01';
    $year_end = $year . '-12-31';

    // Build the year filter form.
    $base_url = Url::fromRoute('<current>')->toString();
    $form = [
      '#type' => 'container',
      '#attributes' => ['class' => ['tracking-report-filters']],
      'year_filter' => [
        '#type' => 'select',
        '#title' => $this->t('Select Year:'),
        '#options' => $year_options,
        '#value' => $year,
        '#attributes' => [
          'onchange' => "window.location.href = '" . $base_url . "' + (this.value ? '?year=' + this.value : '')",
        ],
      ],
    ];

    // -------------------------------------------------------
    // 1) FETCH & PROCESS ALL RELEVANT DATA (Statuses, Deaths, Releases, IDs, Rescues, etc.)
    // -------------------------------------------------------

    // Get status reports for the species.
    $status_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'status_report')
      ->condition('field_species_ref', NULL, 'IS NOT NULL')
      ->accessCheck(FALSE)
      ->execute();

    $species_statuses = [];
    if (!empty($status_query)) {
      $status_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($status_query);
      foreach ($status_nodes as $status_node) {
        if ($status_node->hasField('field_species_ref') && !$status_node->field_species_ref->isEmpty()) {
          $species_entity_id = $status_node->field_species_ref->target_id;
          if ($status_node->hasField('field_health') && !$status_node->field_health->isEmpty()) {
            $health_term = $status_node->field_health->entity;
            if ($health_term && $health_term->hasField('field_health_status')) {
              $species_statuses[$species_entity_id] = $health_term->field_health_status->value;
            }
          }
        }
      }
    }

    // Get deceased species IDs within the specified year.
    $death_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'species_death')
      ->condition('field_species_ref', NULL, 'IS NOT NULL')
      ->condition('field_death_date', $year_start, '>=')
      ->condition('field_death_date', $year_end, '<=')
      ->accessCheck(FALSE)
      ->execute();

    $deceased_entity_ids = [];
    if (!empty($death_query)) {
      $death_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($death_query);
      foreach ($death_nodes as $death_node) {
        if ($death_node->hasField('field_species_ref') && !$death_node->field_species_ref->isEmpty()) {
          $deceased_entity_ids[] = $death_node->field_species_ref->target_id;
        }
      }
    }

    // Get release events within the specified year.
    $release_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'species_release')
      ->condition('field_species_ref', NULL, 'IS NOT NULL')
      ->condition('field_release_date', $year_start, '>=')
      ->condition('field_release_date', $year_end, '<=')
      ->accessCheck(FALSE)
      ->execute();

    $released_entity_ids = [];
    if (!empty($release_query)) {
      $release_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($release_query);
      foreach ($release_nodes as $release_node) {
        if ($release_node->hasField('field_species_ref') && !$release_node->field_species_ref->isEmpty()) {
          $released_entity_ids[] = $release_node->field_species_ref->target_id;
        }
      }
    }

    // Get species IDs.
    $species_id_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'species_id')
      ->condition('field_species_ref', NULL, 'IS NOT NULL')
      ->accessCheck(FALSE)
      ->execute();

    $species_id_values = [];
    if (!empty($species_id_query)) {
      $species_id_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($species_id_query);
      foreach ($species_id_nodes as $species_id_node) {
        if ($species_id_node->hasField('field_species_ref') && !$species_id_node->field_species_ref->isEmpty()) {
          $species_entity_id = $species_id_node->field_species_ref->target_id;
          if ($species_id_node->hasField('field_species_id') && !$species_id_node->field_species_id->isEmpty()) {
            $species_id_values[$species_entity_id] = $species_id_node->field_species_id->value;
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
    $rescue_cause_details = [];
    $type_b_rescue_dates = [];
    $latest_rescue_dates = [];
    $rescue_counties = [];
    if (!empty($rescue_query)) {
      $rescue_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($rescue_query);
      $species_rescues = [];

      foreach ($rescue_nodes as $rescue_node) {
        if ($rescue_node->hasField('field_species_ref') && !$rescue_node->field_species_ref->isEmpty()) {
          $species_entity_id = $rescue_node->field_species_ref->target_id;
          $date = $rescue_node->field_rescue_date->value;
          $rescue_type = '';
          $rescue_cause_detail = 'N/A';

          if ($rescue_node->hasField('field_rescue_type') && !$rescue_node->field_rescue_type->isEmpty()) {
            $rescue_type = $rescue_node->field_rescue_type->entity->getName();
          }

          if ($rescue_node->hasField('field_primary_cause') && !$rescue_node->field_primary_cause->isEmpty()) {
            $primary_cause_term = $rescue_node->field_primary_cause->entity;
            if ($primary_cause_term->hasField('field_rescue_cause') &&
              !$primary_cause_term->field_rescue_cause->isEmpty()) {
              $rescue_cause_detail = $primary_cause_term->field_rescue_cause->value;
            }
          }

          // Get county information.
          $county = 'N/A';
          if ($rescue_node->hasField('field_county') && !$rescue_node->field_county->isEmpty()) {
            $county = $rescue_node->field_county->entity->getName();
          }

          $species_rescues[$species_entity_id][] = [
            'date' => $date,
            'type' => $rescue_type,
            'cause_detail' => $rescue_cause_detail,
            'county' => $county,
          ];

          // Update latest rescue date if this is more recent and store county.
          if (!isset($latest_rescue_dates[$species_entity_id]) || $date > $latest_rescue_dates[$species_entity_id]) {
            $latest_rescue_dates[$species_entity_id] = $date;
            $rescue_counties[$species_entity_id] = $county;
            $rescue_cause_details[$species_entity_id] = $rescue_cause_detail;
          }

          // Track type 'B' rescues specifically.
          if ($rescue_type === 'B') {
            if (!isset($type_b_rescue_dates[$species_entity_id]) || $date > $type_b_rescue_dates[$species_entity_id]) {
              $type_b_rescue_dates[$species_entity_id] = $date;
            }
          }
        }
      }

      foreach ($species_rescues as $species_entity_id => $rescues) {
        usort($rescues, function ($a, $b) {
          return strcmp($b['date'], $a['date']);
        });
        $rescue_types[$species_entity_id] = $rescues[0]['type'];
      }
    }

    // Get birth dates within the specified year.
    $birth_dates = [];
    $birth_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'species_birth')
      ->condition('field_species_ref', NULL, 'IS NOT NULL')
      ->condition('field_birth_date', NULL, 'IS NOT NULL')
      ->condition('field_birth_date', $year_start, '>=')
      ->condition('field_birth_date', $year_end, '<=')
      ->accessCheck(FALSE)
      ->execute();

    if (!empty($birth_query)) {
      $birth_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($birth_query);
      foreach ($birth_nodes as $birth_node) {
        if ($birth_node->hasField('field_species_ref') && !$birth_node->field_species_ref->isEmpty()) {
          $species_entity_id = $birth_node->field_species_ref->target_id;
          $birth_dates[$species_entity_id] = $birth_node->field_birth_date->value;
        }
      }
    }

    // Define event types we care about.
    $event_types = [
      'species_birth' => 'field_birth_date',
      'species_rescue' => 'field_rescue_date',
      'transfer' => 'field_transfer_date',
      'species_release' => 'field_release_date',
    ];

    // Get all species that might be active.
    $species_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'species')
      ->condition('field_number', NULL, 'IS NOT NULL')
      ->accessCheck(FALSE);

    // Exclude species that died or were released within that year.
    if (!empty($deceased_entity_ids)) {
      $species_query->condition('nid', $deceased_entity_ids, 'NOT IN');
    }
    if (!empty($released_entity_ids)) {
      $species_query->condition('nid', $released_entity_ids, 'NOT IN');
    }

    $species_entity_ids = $species_query->execute();

    // Get primary names from paragraphs.
    $primary_names = [];
    if (!empty($species_entity_ids)) {
      $species_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($species_entity_ids);
      foreach ($species_nodes as $species_node) {
        if ($species_node->hasField('field_names') && !$species_node->field_names->isEmpty()) {
          foreach ($species_node->field_names->referencedEntities() as $name_paragraph) {
            if ($name_paragraph->hasField('field_primary') &&
                $name_paragraph->field_primary->value == 1 &&
                $name_paragraph->hasField('field_name') &&
                !$name_paragraph->field_name->isEmpty()) {
              $primary_names[$species_node->id()] = $name_paragraph->field_name->value;
              // Stop after finding the primary name.
              break;
            }
          }
        }
      }
    }

    // Collect event data.
    $event_nodes = [];
    foreach ($event_types as $type => $date_field) {
      $query = $this->entityTypeManager->getStorage('node')->getQuery()
        ->condition('type', $type)
        ->condition('field_species_ref', $species_entity_ids, 'IN')
        ->condition('field_species_ref', NULL, 'IS NOT NULL')
        ->condition($date_field, NULL, 'IS NOT NULL')
        ->condition($date_field, $year_start, '>=')
        ->condition($date_field, $year_end, '<=')
        ->accessCheck(FALSE);

      $results = $query->execute();
      if (!empty($results)) {
        $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($results);
        foreach ($nodes as $node) {
          if ($node->hasField('field_species_ref') && !$node->field_species_ref->isEmpty()) {
            $species_entity_id = $node->field_species_ref->target_id;
            $date_value = $node->get($date_field)->value;

            // Determine the facility term (e.g., to_facility, org, etc.).
            $facility_term = NULL;
            if ($type === 'transfer' && $node->hasField('field_to_facility') && !$node->field_to_facility->isEmpty()) {
              $facility_term = $node->field_to_facility->entity;
            }
            elseif ($node->hasField('field_org') && !$node->field_org->isEmpty()) {
              $facility_term = $node->field_org->entity;
            }

            $weight = $node->hasField('field_weight') && !$node->field_weight->isEmpty()
              ? $node->field_weight->value
              : 'N/A';
            $length = $node->hasField('field_length') && !$node->field_length->isEmpty()
              ? $node->field_length->value
              : 'N/A';

            $event_nodes[$species_entity_id][] = [
              'nid' => $node->id(),
              'type' => $type,
              'date' => $date_value,
              'date_field' => $date_field,
              'facility_term' => $facility_term,
              'weight' => $weight,
              'length' => $length,
            ];
          }
        }
      }
    }

    // Determine most recent event for each species.
    $most_recent_events = [];
    if (!empty($species_entity_ids)) {
      foreach ($species_entity_ids as $species_entity_id) {
        if (isset($event_nodes[$species_entity_id])) {
          usort($event_nodes[$species_entity_id], function ($a, $b) {
            return strcmp($b['date'], $a['date']);
          });
          // Only consider most-recent if it's not a release event
          // (since we want species still in captivity).
          if ($event_nodes[$species_entity_id][0]['type'] !== 'species_release') {
            $most_recent_events[$species_entity_id] = $event_nodes[$species_entity_id][0];
          }
        }
      }
    }

    // -------------------------------------------------------
    // 2) BUILD $sortable_data ARRAY
    // -------------------------------------------------------
    // Always initialize $sortable_data to avoid "null given" errors in usort.
    $sortable_data = [];

    // Load species and prepare data.
    if (!empty($species_entity_ids)) {
      $species = $this->entityTypeManager->getStorage('node')->loadMultiple($species_entity_ids);

      foreach ($species as $species_entity) {
        // If we have no "most recent event" for this entity, skip.
        if (!isset($most_recent_events[$species_entity->id()])) {
          continue;
        }

        $event = $most_recent_events[$species_entity->id()];

        // Gather fields.
        $number = 'N/A';
        if ($species_entity->hasField('field_number') && !$species_entity->field_number->isEmpty()) {
          $number_value = $species_entity->get('field_number')->getValue();
          $number = $number_value[0]['value'] ?? 'N/A';
        }

        $rescue_date = isset($latest_rescue_dates[$species_entity->id()])
          ? (new DrupalDateTime($latest_rescue_dates[$species_entity->id()]))->format('Y-m-d')
          : 'N/A';

        $name = $primary_names[$species_entity->id()] ?? '';
        $species_id = $species_id_values[$species_entity->id()] ?? '';
        $rescue_type = $rescue_types[$species_entity->id()] ?? 'none';
        $rescue_cause_detail = $rescue_cause_details[$species_entity->id()] ?? 'N/A';
        $county = $rescue_counties[$species_entity->id()] ?? 'N/A';

        // Determine start of captivity date (type B rescue date or birth date).
        $captivity_date = NULL;
        if (isset($type_b_rescue_dates[$species_entity->id()])) {
          $captivity_date = new DrupalDateTime($type_b_rescue_dates[$species_entity->id()]);
        }
        elseif (isset($birth_dates[$species_entity->id()])) {
          $captivity_date = new DrupalDateTime($birth_dates[$species_entity->id()]);
        }

        // Calculate time in captivity.
        $time_in_captivity = 'N/A';
        if ($captivity_date) {
          $time_in_captivity = $this->calculateTimeInCaptivity($captivity_date, $year);
        }

        // Weight/length display.
        $weight_length = 'N/A';
        if ($event['weight'] !== 'N/A' || $event['length'] !== 'N/A') {
          $weight_pieces = [];
          if ($event['weight'] !== 'N/A') {
            $weight_pieces[] = $event['weight'] . ' kg';
          }
          if ($event['length'] !== 'N/A') {
            $weight_pieces[] = $event['length'] . ' cm';
          }
          $weight_length = implode(', ', $weight_pieces);
        }

        // Facility name.
        $facility_name = 'N/A';
        if ($event['facility_term'] && $event['facility_term']->hasField('name')) {
          $facility_name = $event['facility_term']->getName();
        }

        // Medical status (from the status_report if any).
        $medical_status = $species_statuses[$species_entity->id()] ?? 'N/A';

        // Add to $sortable_data only if the rescue is Type B or none.
        if ($rescue_type === 'B' || $rescue_type === 'none') {
          $sortable_data[] = [
            'facility_name' => $facility_name,
            'name' => $name,
            'species_id' => $species_id,
            'number' => $number,
            'weight_length' => $weight_length,
            'county' => $county,
            'rescue_date' => $rescue_date,
            'rescue_cause' => $rescue_cause_detail,
            'time_in_captivity' => $time_in_captivity,
            'medical_status' => $medical_status,
            'species_nid' => $species_entity->id(),
          ];
        }
      }
    }

    // -------------------------------------------------------
    // 3) SORT ONLY BY FACILITY (IF DATA EXISTS)
    // -------------------------------------------------------
    // Get current sort field and direction.
    $order_by = \Drupal::request()->query->get('order', 'facility_name');
    $sort = \Drupal::request()->query->get('sort', 'asc');

    // The only valid sort field is 'facility_name'.
    $valid_sort_fields = [
      'facility_name',
    ];

    // If $order_by is not 'facility_name', default to it.
    if (!in_array($order_by, $valid_sort_fields)) {
      $order_by = 'facility_name';
    }

    // Sort only if $sortable_data is not empty.
    if (!empty($sortable_data)) {
      usort($sortable_data, function ($a, $b) use ($order_by, $sort) {
        if (!isset($a[$order_by]) || !isset($b[$order_by])) {
          return 0;
        }
        $result = strnatcasecmp($a[$order_by], $b[$order_by]);
        return ($sort === 'asc') ? $result : -$result;
      });
    }

    // -------------------------------------------------------
    // 4) BUILD TABLE HEADERS & ROWS
    // -------------------------------------------------------
    // Only Facility is sortable.
    $header = [
      [
        'data' => $this->t('Facility'),
        'field' => 'facility_name',
        'sort' => 'asc',
      ],
      ['data' => $this->t('Name')],
      ['data' => $this->t('Species') . ' ' . $this->t('ID')],
      ['data' => $this->t('Species') . ' ' . $this->t('Number')],
      ['data' => $this->t('Weight, Length')],
      ['data' => $this->t('County')],
      ['data' => $this->t('Rescue Date')],
      ['data' => $this->t('Cause of Rescue')],
      ['data' => $this->t('Time in Captivity')],
      ['data' => $this->t('Medical Status')],
    ];

    $rows = [];
    foreach ($sortable_data as $data) {
      $rows[] = [
        'data' => [
          ['data' => $data['facility_name']],
          ['data' => $data['name']],
          ['data' => $data['species_id']],
          [
            'data' => Link::createFromRoute(
              $data['number'],
              'entity.node.canonical',
              ['node' => $data['species_nid']]
            ),
          ],
          ['data' => $data['weight_length']],
          ['data' => $data['county']],
          ['data' => $data['rescue_date']],
          ['data' => $data['rescue_cause']],
          ['data' => $data['time_in_captivity']],
          ['data' => $data['medical_status']],
        ],
      ];
    }

    // -------------------------------------------------------
    // 5) RETURN RENDER ARRAY
    // -------------------------------------------------------
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['tracking-report-container']],
      'filters' => $form,
      'table' => [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => $this->t('No results found'),
        '#attributes' => ['class' => ['tracking-report-table']],
      ],
      '#attached' => [
        'library' => [
          'tracking_reports/tracking_reports',
        ],
      ],
    ];
  }

}
