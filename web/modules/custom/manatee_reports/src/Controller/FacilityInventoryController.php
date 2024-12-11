<?php

namespace Drupal\manatee_reports\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Controller for displaying current captive manatees by facility.
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
    $form = [
      '#type' => 'container',
      '#attributes' => ['class' => ['manatee-report-filters']],
      'year_filter' => [
        '#type' => 'select',
        '#title' => $this->t('Select Year:'),
        '#options' => $year_options,
        '#default_value' => $year,
        '#attributes' => [
          'onChange' => 'window.location.href = "' . Url::fromRoute('<current>')->toString() . '?year=" + this.value',
        ],
      ],
    ];

    // Get status reports for the manatees.
    $status_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'status_report')
      ->condition('field_animal', NULL, 'IS NOT NULL')
      ->accessCheck(FALSE)
      ->execute();

    $manatee_statuses = [];
    if (!empty($status_query)) {
      $status_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($status_query);
      foreach ($status_nodes as $status_node) {
        if ($status_node->hasField('field_animal') && !$status_node->field_animal->isEmpty()) {
          $animal_id = $status_node->field_animal->target_id;
          if ($status_node->hasField('field_health') && !$status_node->field_health->isEmpty()) {
            $health_term = $status_node->field_health->entity;
            if ($health_term && $health_term->hasField('field_health_status')) {
              $manatee_statuses[$animal_id] = $health_term->field_health_status->value;
            }
          }
        }
      }
    }

    // Get deceased manatee IDs within the specified year.
    $death_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'manatee_death')
      ->condition('field_animal', NULL, 'IS NOT NULL')
      ->condition('field_death_date', $year_start, '>=')
      ->condition('field_death_date', $year_end, '<=')
      ->accessCheck(FALSE)
      ->execute();

    $deceased_manatee_ids = [];
    if (!empty($death_query)) {
      $death_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($death_query);
      foreach ($death_nodes as $death_node) {
        if ($death_node->hasField('field_animal') && !$death_node->field_animal->isEmpty()) {
          $deceased_manatee_ids[] = $death_node->field_animal->target_id;
        }
      }
    }

    // Get release events within the specified year.
    $release_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'manatee_release')
      ->condition('field_animal', NULL, 'IS NOT NULL')
      ->condition('field_release_date', $year_start, '>=')
      ->condition('field_release_date', $year_end, '<=')
      ->accessCheck(FALSE)
      ->execute();

    $released_manatee_ids = [];
    if (!empty($release_query)) {
      $release_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($release_query);
      foreach ($release_nodes as $release_node) {
        if ($release_node->hasField('field_animal') && !$release_node->field_animal->isEmpty()) {
          $released_manatee_ids[] = $release_node->field_animal->target_id;
        }
      }
    }

    // Get primary names.
    $name_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'manatee_name')
      ->condition('field_animal', NULL, 'IS NOT NULL')
      ->condition('field_primary', 1)
      ->accessCheck(FALSE)
      ->execute();

    $primary_names = [];
    if (!empty($name_query)) {
      $name_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($name_query);
      foreach ($name_nodes as $name_node) {
        if ($name_node->hasField('field_animal') && !$name_node->field_animal->isEmpty()) {
          $animal_id = $name_node->field_animal->target_id;
          if ($name_node->hasField('field_name') && !$name_node->field_name->isEmpty()) {
            $primary_names[$animal_id] = $name_node->field_name->value;
          }
        }
      }
    }

    // Get animal IDs.
    $animal_id_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'manatee_animal_id')
      ->condition('field_animal', NULL, 'IS NOT NULL')
      ->accessCheck(FALSE)
      ->execute();

    $animal_ids = [];
    if (!empty($animal_id_query)) {
      $animal_id_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($animal_id_query);
      foreach ($animal_id_nodes as $animal_id_node) {
        if ($animal_id_node->hasField('field_animal') && !$animal_id_node->field_animal->isEmpty()) {
          $animal_id = $animal_id_node->field_animal->target_id;
          if ($animal_id_node->hasField('field_animal_id') && !$animal_id_node->field_animal_id->isEmpty()) {
            $animal_ids[$animal_id] = $animal_id_node->field_animal_id->value;
          }
        }
      }
    }

    // Get rescue events.
    $rescue_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'manatee_rescue')
      ->condition('field_animal', NULL, 'IS NOT NULL')
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
      $animal_rescues = [];

      foreach ($rescue_nodes as $rescue_node) {
        if ($rescue_node->hasField('field_animal') && !$rescue_node->field_animal->isEmpty()) {
          $animal_id = $rescue_node->field_animal->target_id;
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

          $animal_rescues[$animal_id][] = [
            'date' => $date,
            'type' => $rescue_type,
            'cause_detail' => $rescue_cause_detail,
            'county' => $county,
          ];

          // Update latest rescue date if this is more recent and store county.
          if (!isset($latest_rescue_dates[$animal_id]) || $date > $latest_rescue_dates[$animal_id]) {
            $latest_rescue_dates[$animal_id] = $date;
            $rescue_counties[$animal_id] = $county;
            $rescue_cause_details[$animal_id] = $rescue_cause_detail;
          }

          if ($rescue_type === 'B') {
            if (!isset($type_b_rescue_dates[$animal_id]) || $date > $type_b_rescue_dates[$animal_id]) {
              $type_b_rescue_dates[$animal_id] = $date;
            }
          }
        }
      }

      foreach ($animal_rescues as $animal_id => $rescues) {
        usort($rescues, function ($a, $b) {
          return strcmp($b['date'], $a['date']);
        });
        $rescue_types[$animal_id] = $rescues[0]['type'];
      }
    }

    // Get birth dates within the specified year.
    $birth_dates = [];
    $birth_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'manatee_birth')
      ->condition('field_animal', NULL, 'IS NOT NULL')
      ->condition('field_birth_date', NULL, 'IS NOT NULL')
      ->condition('field_birth_date', $year_start, '>=')
      ->condition('field_birth_date', $year_end, '<=')
      ->accessCheck(FALSE)
      ->execute();

    if (!empty($birth_query)) {
      $birth_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($birth_query);
      foreach ($birth_nodes as $birth_node) {
        if ($birth_node->hasField('field_animal') && !$birth_node->field_animal->isEmpty()) {
          $animal_id = $birth_node->field_animal->target_id;
          $birth_dates[$animal_id] = $birth_node->field_birth_date->value;
        }
      }
    }

    // Define event types.
    $event_types = [
      'manatee_birth' => 'field_birth_date',
      'manatee_rescue' => 'field_rescue_date',
      'transfer' => 'field_transfer_date',
      'manatee_release' => 'field_release_date',
    ];

    // Get all manatees with MLOGs that were active in the specified year.
    $manatee_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'manatee')
      ->condition('field_mlog', NULL, 'IS NOT NULL');

    // Exclude manatees that died or were released before the specified year.
    if (!empty($deceased_manatee_ids)) {
      $manatee_query->condition('nid', $deceased_manatee_ids, 'NOT IN');
    }
    if (!empty($released_manatee_ids)) {
      $manatee_query->condition('nid', $released_manatee_ids, 'NOT IN');
    }

    $manatee_ids = $manatee_query->accessCheck(FALSE)->execute();

    // Get all events.
    $event_nodes = [];
    foreach ($event_types as $type => $date_field) {
      $query = $this->entityTypeManager->getStorage('node')->getQuery()
        ->condition('type', $type)
        ->condition('field_animal', $manatee_ids, 'IN')
        ->condition('field_animal', NULL, 'IS NOT NULL')
        ->condition($date_field, NULL, 'IS NOT NULL')
        ->condition($date_field, $year_start, '>=')
        ->condition($date_field, $year_end, '<=')
        ->accessCheck(FALSE);

      $results = $query->execute();

      if (!empty($results)) {
        $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($results);
        foreach ($nodes as $node) {
          if ($node->hasField('field_animal') && !$node->field_animal->isEmpty()) {
            $animal_id = $node->field_animal->target_id;
            $date_value = $node->get($date_field)->value;

            $facility_term = NULL;
            if ($type === 'transfer' && $node->hasField('field_to_facility') && !$node->field_to_facility->isEmpty()) {
              $facility_term = $node->field_to_facility->entity;
            }
            elseif ($node->hasField('field_org') && !$node->field_org->isEmpty()) {
              $facility_term = $node->field_org->entity;
            }

            $weight = 'N/A';
            $length = 'N/A';

            if ($node->hasField('field_weight') && !$node->field_weight->isEmpty()) {
              $weight = $node->field_weight->value;
            }
            if ($node->hasField('field_length') && !$node->field_length->isEmpty()) {
              $length = $node->field_length->value;
            }

            $event_nodes[$animal_id][] = [
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

    // Process events.
    $most_recent_events = [];
    foreach ($manatee_ids as $animal_id) {
      if (isset($event_nodes[$animal_id])) {
        usort($event_nodes[$animal_id], function ($a, $b) {
          return strcmp($b['date'], $a['date']);
        });

        if ($event_nodes[$animal_id][0]['type'] !== 'manatee_release') {
          $most_recent_events[$animal_id] = $event_nodes[$animal_id][0];
        }
      }
    }

    // Load manatees and prepare sortable data.
    $manatees = $this->entityTypeManager->getStorage('node')->loadMultiple($manatee_ids);
    $sortable_data = [];

    foreach ($manatees as $manatee) {
      if (!isset($most_recent_events[$manatee->id()])) {
        continue;
      }

      $event = $most_recent_events[$manatee->id()];

      $mlog = "N/A";
      if ($manatee->hasField('field_mlog') && !$manatee->field_mlog->isEmpty()) {
        $mlog_value = $manatee->get('field_mlog')->getValue();
        $mlog = $mlog_value[0]['value'] ?? "N/A";
      }

      $rescue_date = isset($latest_rescue_dates[$manatee->id()])
        ? (new DrupalDateTime($latest_rescue_dates[$manatee->id()]))->format('Y-m-d')
        : 'N/A';

      $name = $primary_names[$manatee->id()] ?? '';
      $animal_id = $animal_ids[$manatee->id()] ?? '';
      $rescue_type = $rescue_types[$manatee->id()] ?? 'none';
      $rescue_cause_detail = $rescue_cause_details[$manatee->id()] ?? 'N/A';
      $county = $rescue_counties[$manatee->id()] ?? 'N/A';

      $captivity_date = NULL;
      if (isset($type_b_rescue_dates[$manatee->id()])) {
        $captivity_date = new DrupalDateTime($type_b_rescue_dates[$manatee->id()]);
      }
      elseif (isset($birth_dates[$manatee->id()])) {
        $captivity_date = new DrupalDateTime($birth_dates[$manatee->id()]);
      }

      $time_in_captivity = 'N/A';
      if ($captivity_date) {
        $time_in_captivity = $this->calculateTimeInCaptivity($captivity_date, $year);
      }

      $weight_length = 'N/A';
      if ($event['weight'] !== 'N/A' || $event['length'] !== 'N/A') {
        $weight_length = '';
        if ($event['weight'] !== 'N/A') {
          $weight_length .= $event['weight'] . ' kg';
        }
        if ($event['length'] !== 'N/A') {
          if ($weight_length !== '') {
            $weight_length .= ', ';
          }
          $weight_length .= $event['length'] . ' cm';
        }
      }

      $facility_name = 'N/A';
      if ($event['facility_term'] && $event['facility_term']->hasField('name')) {
        $facility_name = $event['facility_term']->getName();
      }

      $medical_status = $manatee_statuses[$manatee->id()] ?? 'N/A';

      if ($rescue_type === 'B' || $rescue_type === 'none') {
        $sortable_data[] = [
          'facility_name' => $facility_name,
          'name' => $name,
          'animal_id' => $animal_id,
          'mlog' => $mlog,
          'weight_length' => $weight_length,
          'county' => $county,
          'rescue_date' => $rescue_date,
          'rescue_cause' => $rescue_cause_detail,
          'time_in_captivity' => $time_in_captivity,
          'medical_status' => $medical_status,
          'manatee_nid' => $manatee->id(),
        ];
      }
    }

    // Get current sort field and direction.
    $order_by = \Drupal::request()->query->get('order', 'facility_name');
    $sort = \Drupal::request()->query->get('sort', 'asc');

    // Define valid sort fields.
    $valid_sort_fields = [
      'facility_name',
      'name',
      'animal_id',
      'mlog',
      'county',
      'rescue_date',
      'rescue_cause',
      'time_in_captivity',
      'medical_status',
    ];

    // If order_by is not in valid fields, default to facility_name.
    if (!in_array($order_by, $valid_sort_fields)) {
      $order_by = 'facility_name';
    }

    // Sort the data.
    usort($sortable_data, function ($a, $b) use ($order_by, $sort) {
      // Ensure both arrays have the required key.
      if (!isset($a[$order_by]) || !isset($b[$order_by])) {
        return 0;
      }

      // Special handling for rescue_date.
      if ($order_by === 'rescue_date') {
        $date_a = $a[$order_by] === 'N/A' ? '0000-00-00' : $a[$order_by];
        $date_b = $b[$order_by] === 'N/A' ? '0000-00-00' : $b[$order_by];
        $result = strcmp($date_a, $date_b);
      }
      // Special handling for time_in_captivity.
      elseif ($order_by === 'time_in_captivity') {
        // Convert time strings to comparable values.
        $getValue = function ($str) {
          if ($str === 'N/A') {
            return 0;
          }
          $months = 0;
          if (preg_match('/(\d+)\s*yr/', $str, $matches)) {
            $months += $matches[1] * 12;
          }
          if (preg_match('/(\d+)\s*mo/', $str, $matches)) {
            $months += $matches[1];
          }
          return $months;
        };

        $val_a = $getValue($a[$order_by]);
        $val_b = $getValue($b[$order_by]);
        $result = $val_a - $val_b;
      }
      else {
        $result = strnatcasecmp($a[$order_by], $b[$order_by]);
      }

      return $sort === 'asc' ? $result : -$result;
    });

    // Prepare table headers with sorting.
    $header = [
      [
        'data' => $this->t('Facility'),
        'field' => 'facility_name',
        'sort' => 'asc',
      ],
      [
        'data' => $this->t('Name'),
        'field' => 'name',
        'sort' => 'asc',
      ],
      [
        'data' => $this->t('Manatee ID'),
        'field' => 'animal_id',
        'sort' => 'asc',
      ],
      [
        'data' => $this->t('Manatee Number'),
        'field' => 'mlog',
        'sort' => 'asc',
      ],
      // Weight, Length column without sorting.
      ['data' => $this->t('Weight, Length')],
      [
        'data' => $this->t('County'),
        'field' => 'county',
        'sort' => 'asc',
      ],
      [
        'data' => $this->t('Rescue Date'),
        'field' => 'rescue_date',
        'sort' => 'desc',
      ],
      [
        'data' => $this->t('Cause of Rescue'),
        'field' => 'rescue_cause',
        'sort' => 'asc',
      ],
      [
        'data' => $this->t('Time in Captivity'),
        'field' => 'time_in_captivity',
        'sort' => 'desc',
      ],
      [
        'data' => $this->t('Medical Status'),
        'field' => 'medical_status',
        'sort' => 'asc',
      ],
    ];

    // Build rows from sorted data.
    $rows = [];
    foreach ($sortable_data as $data) {
      $rows[] = [
        'data' => [
          ['data' => $data['facility_name']],
          ['data' => $data['name']],
          ['data' => $data['animal_id']],
          [
            'data' => Link::createFromRoute(
            $data['mlog'],
            'entity.node.canonical',
            ['node' => $data['manatee_nid']]
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

    // Build table.
    $table = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No manatees found'),
      '#attributes' => ['class' => ['manatee-report-table']],
      '#sticky' => TRUE,
    ];

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['manatee-report-container']],
      'filters' => $form,
      'table' => $table,
      '#attached' => [
        'library' => [
          'manatee_reports/manatee_reports',
        ],
      ],
    ];
  }

}
