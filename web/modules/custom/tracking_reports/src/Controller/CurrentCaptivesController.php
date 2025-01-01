<?php

namespace Drupal\tracking_reports\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Utility\TableSort;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\tracking_reports\Form\FacilityFilterForm;

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
   *   A render array for the page.
   */
  public function content() {
    // 1) Get the currently selected facility from the URL (?facility=___).
    $request = $this->requestStack->getCurrentRequest();
    $selected_facility = $request->query->get('facility', 'all');

    // 2) Build our facility filter form (the real form), which uses GET
    // submission. The form's submit handler will set ?facility=___ and redirect
    // to the same path.
    $facility_filter_form = \Drupal::formBuilder()->getForm(FacilityFilterForm::class);

    // 3) Gather data about deceased species, species IDs, rescue info, births,
    // etc. This is basically the same logic from your original code, with
    // slight modifications to skip the old "manual" filter container.
    // Get all species_death nodes to exclude deceased species.
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

    // Get species_id nodes.
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
          $species_ref_id = $species_id_node->field_species_ref->target_id;
          if ($species_id_node->hasField('field_species_id') && !$species_id_node->field_species_id->isEmpty()) {
            $species_ids[$species_ref_id] = $species_id_node->field_species_id->value;
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
          $species_ref_id = $rescue_node->field_species_ref->target_id;
          $date = $rescue_node->field_rescue_date->value;
          $rescue_type = '';
          if ($rescue_node->hasField('field_rescue_type') && !$rescue_node->field_rescue_type->isEmpty()) {
            $rescue_type = $rescue_node->field_rescue_type->entity->getName();
          }

          $species_rescues[$species_ref_id][] = [
            'date' => $date,
            'type' => $rescue_type,
          ];

          if ($rescue_type === 'B') {
            if (!isset($type_b_rescue_dates[$species_ref_id]) || $date > $type_b_rescue_dates[$species_ref_id]) {
              $type_b_rescue_dates[$species_ref_id] = $date;
            }
          }
        }
      }

      // Most recent rescue type for each species.
      foreach ($species_rescues as $species_ref_id => $rescues) {
        usort($rescues, function ($a, $b) {
          return strcmp($b['date'], $a['date']);
        });
        $rescue_types[$species_ref_id] = $rescues[0]['type'];
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
          $species_ref_id = $birth_node->field_species_ref->target_id;
          $birth_dates[$species_ref_id] = $birth_node->field_birth_date->value;
        }
      }
    }

    // Define event types to gather.
    $event_types = [
      'species_birth' => 'field_birth_date',
      'species_rescue' => 'field_rescue_date',
      'transfer' => 'field_transfer_date',
      'species_release' => 'field_release_date',
    ];

    // Query for all species that aren't deceased.
    $species_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'species')
      ->condition('field_number', NULL, 'IS NOT NULL');

    if (!empty($deceased_ids)) {
      $species_query->condition('nid', $deceased_ids, 'NOT IN');
    }

    $species_entity_ids = $species_query->accessCheck(FALSE)->execute();

    // Gather each species's primary name.
    $primary_names = [];
    if (!empty($species_entity_ids)) {
      $species_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($species_entity_ids);
      foreach ($species_nodes as $s_id => $species_node) {
        if ($species_node->hasField('field_names') && !$species_node->field_names->isEmpty()) {
          $names_paragraphs = $species_node->field_names->referencedEntities();
          foreach ($names_paragraphs as $paragraph) {
            if ($paragraph->hasField('field_primary') &&
                !$paragraph->field_primary->isEmpty() &&
                $paragraph->field_primary->value == 1 &&
                $paragraph->hasField('field_name') &&
                !$paragraph->field_name->isEmpty()) {
              $primary_names[$s_id] = $paragraph->field_name->value;
              break;
            }
          }
        }
      }
    }

    // Collect events for each species.
    $event_nodes = [];
    foreach ($event_types as $type => $date_field) {
      $query = $this->entityTypeManager->getStorage('node')->getQuery()
        ->condition('type', $type)
        ->condition('field_species_ref', $species_entity_ids, 'IN')
        ->condition($date_field, NULL, 'IS NOT NULL')
        ->accessCheck(FALSE);

      $results = $query->execute();
      if (!empty($results)) {
        $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($results);
        foreach ($nodes as $node) {
          if ($node->hasField('field_species_ref') && !$node->field_species_ref->isEmpty()) {
            $species_ref_id = $node->field_species_ref->target_id;
            $date_value = $node->get($date_field)->value;

            $organization = '';
            // Transfers store facility in field_to_facility, others in field_org.
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

            $event_nodes[$species_ref_id][] = [
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

    // Figure out the most recent event for each species (if not a
    // species_release).
    $most_recent_events = [];
    foreach ($species_entity_ids as $species_id) {
      if (isset($event_nodes[$species_id])) {
        usort($event_nodes[$species_id], function ($a, $b) {
          return strcmp($b['date'], $a['date']);
        });
        if ($event_nodes[$species_id][0]['type'] !== 'species_release') {
          $most_recent_events[$species_id] = $event_nodes[$species_id][0];
        }
      }
    }

    // Load all species nodes fully.
    $species_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($species_entity_ids);
    $current_date = new DrupalDateTime();

    // Build table rows.
    $rows = [];
    foreach ($species_nodes as $species_node) {
      $sid = $species_node->id();
      if (!isset($most_recent_events[$sid])) {
        // Probably means the most recent event is species_release or none
        // found.
        continue;
      }

      $event = $most_recent_events[$sid];

      // Filter by facility if needed.
      if ($selected_facility !== 'all' && $event['organization'] !== $selected_facility) {
        continue;
      }

      // Tracking number # (field_number).
      $number_str = 'N/A';
      $number_num = PHP_INT_MAX;
      if ($species_node->hasField('field_number') && !$species_node->field_number->isEmpty()) {
        $number_str = $species_node->field_number->value;
        if (preg_match('/(\d+)/', $number_str, $matches)) {
          $number_num = intval($matches[0]);
        }
      }

      // Create a link to the species node.
      $number_link = Link::createFromRoute(
        $number_str,
        'entity.node.canonical',
        ['node' => $sid]
      );

      // Format the event type + date.
      $event_type = str_replace('species_', '', $event['type']);
      $event_type = str_replace('_', ' ', $event_type);
      $event_type = ucfirst($event_type);

      $date = new DrupalDateTime($event['date']);
      $formatted_date = $date->format('Y-m-d');

      $name = $primary_names[$sid] ?? '';
      $species_id_val = $species_ids[$sid] ?? '';
      $rescue_type = $rescue_types[$sid] ?? 'none';

      // Figure out # of days in captivity if rescue type is B or none.
      $captivity_date = NULL;
      if (isset($type_b_rescue_dates[$sid])) {
        $captivity_date = new DrupalDateTime($type_b_rescue_dates[$sid]);
      }
      elseif (isset($birth_dates[$sid])) {
        $captivity_date = new DrupalDateTime($birth_dates[$sid]);
      }

      $days_in_captivity = 'N/A';
      if ($captivity_date) {
        $interval = $current_date->diff($captivity_date);
        $days_in_captivity = $interval->days;
      }

      // Only show rows if rescue_type is B or 'none' (from your original
      // logic).
      if ($rescue_type === 'B' || $rescue_type === 'none') {
        $rows[] = [
          'data' => [
            ['data' => $number_link],
            ['data' => $name],
            ['data' => $species_id_val],
            ['data' => $event_type],
            ['data' => $formatted_date],
            ['data' => $days_in_captivity],
            ['data' => $event['organization']],
          ],
          'data-facility' => $event['organization'],
          'number_num' => $number_num, // used for sorting by 'Tracking Number'.
        ];
      }
    }

    // Table headers (including TableSort).
    $header = [
      'number' => [
        'data' => $this->t('Tracking Number'),
        'field' => 'number',
        'sort' => 'asc',
      ],
      'name' => [
        'data' => $this->t('Name'),
        'field' => 'name',
      ],
      'species_id' => [
        'data' => $this->t('Species ID'),
        'field' => 'species_id',
      ],
      'event' => [
        'data' => $this->t('Event'),
        'field' => 'event',
      ],
      'event_date' => [
        'data' => $this->t('Event Date'),
        'field' => 'event_date',
      ],
      'days_captive' => [
        'data' => $this->t('# Days in Captivity'),
        'field' => 'days_captive',
      ],
      'facility' => [
        'data' => $this->t('Facility'),
        'field' => 'facility',
      ],
    ];

    // Use TableSort to determine which column is sorted and how.
    $order = TableSort::getOrder($header, $request);
    $sort = TableSort::getSort($header, $request);
    $dir = ($sort == 'desc') ? SORT_DESC : SORT_ASC;

    // Perform a manual sort on the $rows array.
    if (isset($order['sql'])) {
      $field = $order['sql'];
      $compare = function ($a, $b) use ($field, $dir) {
        $a_val = '';
        $b_val = '';

        switch ($field) {
          case 'number':
            // We sort by the numeric portion of the MLOG (number_num).
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
        return ($dir === SORT_ASC ? 1 : -1) * (($a_val < $b_val) ? -1 : 1);
      };
      usort($rows, $compare);
    }

    // Pager setup. We have array data, so chunk manually.
    $limit = 25; // Items per page
    $total = count($rows);
    $pager_manager = \Drupal::service('pager.manager');
    $pager = $pager_manager->createPager($total, $limit);
    $current_page = $pager->getCurrentPage();

    // Chunk the rows by $limit.
    $chunks = array_chunk($rows, $limit);
    $rows_for_current_page = isset($chunks[$current_page]) ? $chunks[$current_page] : [];

    // Build the table.
    $table = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => array_map(function ($row) {
        // We only need 'data' for the table; no need to keep the extra keys.
        return [
          'data' => $row['data'],
          'data-facility' => $row['data-facility'],
        ];
      }, $rows_for_current_page),
      '#empty' => $this->t('No results found'),
      '#attributes' => [
        'class' => ['tracking-report-table'],
      ],
    ];

    // 4) Return a render array: the facility filter form, table, and pager.
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['tracking-report-container']],
      'facility_filter_form' => $facility_filter_form,
      'table' => $table,
      'pager' => ['#type' => 'pager'],
      '#attached' => [
        'library' => [
          'tracking_reports/tracking_reports',
        ],
      ],
    ];
  }

}
