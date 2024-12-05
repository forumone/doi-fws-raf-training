<?php

namespace Drupal\manatee_reports\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\manatee_reports\ManateeSearchManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Controller for displaying manatee search results.
 */
class ManateeSearchResultsController extends ControllerBase {

  /**
   * The manatee search manager.
   *
   * @var \Drupal\manatee_reports\ManateeSearchManager
   */
  protected $searchManager;

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
   * Constructs a ManateeSearchResultsController object.
   *
   * @param \Drupal\manatee_reports\ManateeSearchManager $search_manager
   *   The manatee search manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   */
  public function __construct(
    ManateeSearchManager $search_manager,
    EntityTypeManagerInterface $entity_type_manager,
    RequestStack $request_stack,
  ) {
    $this->searchManager = $search_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('manatee_reports.search_manager'),
      $container->get('entity_type.manager'),
      $container->get('request_stack')
    );
  }

  /**
   * Get primary name for a manatee.
   */
  protected function getPrimaryName($manatee_id) {
    $name_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'manatee_name')
      ->condition('field_animal', $manatee_id)
      ->condition('field_primary', 1)
      ->accessCheck(FALSE)
      ->execute();

    if (!empty($name_query)) {
      $name_node = $this->entityTypeManager->getStorage('node')->load(reset($name_query));
      if ($name_node && $name_node->hasField('field_name') && !$name_node->field_name->isEmpty()) {
        return $name_node->field_name->value;
      }
    }
    return 'N/A';
  }

  /**
   * Get animal ID for a manatee.
   */
  protected function getAnimalId($manatee_id) {
    $id_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'manatee_animal_id')
      ->condition('field_animal', $manatee_id)
      ->accessCheck(FALSE)
      ->execute();

    if (!empty($id_query)) {
      $id_node = $this->entityTypeManager->getStorage('node')->load(reset($id_query));
      if ($id_node && $id_node->hasField('field_animal_id') && !$id_node->field_animal_id->isEmpty()) {
        return $id_node->field_animal_id->value;
      }
    }
    return 'N/A';
  }

  /**
   * Get most recent status for a manatee.
   */
  protected function getManateeStatus($manatee_id) {
    $status_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'status_report')
      ->condition('field_animal', $manatee_id)
      ->sort('created', 'DESC')
      ->range(0, 1)
      ->accessCheck(FALSE)
      ->execute();

    if (!empty($status_query)) {
      $status_node = $this->entityTypeManager->getStorage('node')->load(reset($status_query));
      if ($status_node && $status_node->hasField('field_health') && !$status_node->field_health->isEmpty()) {
        return $status_node->field_health->entity->getName();
      }
    }
    return 'N/A';
  }

  /**
   * Get rescue information for a manatee.
   */
  protected function getRescueInfo($manatee_id) {
    $rescue_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'manatee_rescue')
      ->condition('field_animal', $manatee_id)
      ->sort('field_rescue_date', 'DESC')
      ->range(0, 1)
      ->accessCheck(FALSE)
      ->execute();

    $info = [
      'date' => 'N/A',
      'cause' => 'N/A',
      'county' => 'N/A',
      'type' => 'N/A',
    ];

    if (!empty($rescue_query)) {
      $rescue_node = $this->entityTypeManager->getStorage('node')->load(reset($rescue_query));
      if ($rescue_node) {
        if ($rescue_node->hasField('field_rescue_date') && !$rescue_node->field_rescue_date->isEmpty()) {
          $info['date'] = $rescue_node->field_rescue_date->value;
        }
        if ($rescue_node->hasField('field_primary_cause') && !$rescue_node->field_primary_cause->isEmpty()) {
          $info['cause'] = $rescue_node->field_primary_cause->entity->getName();
        }
        if ($rescue_node->hasField('field_county') && !$rescue_node->field_county->isEmpty()) {
          $info['county'] = $rescue_node->field_county->entity->getName();
        }
        if ($rescue_node->hasField('field_rescue_type') && !$rescue_node->field_rescue_type->isEmpty()) {
          $info['type'] = $rescue_node->field_rescue_type->entity->getName();
        }
      }
    }

    return $info;
  }

  /**
   * Get measurements for a manatee.
   */
  protected function getLatestMeasurements($manatee_id) {
    $measurements = [
      'weight' => 'N/A',
      'length' => 'N/A',
    ];

    // Define event types to check for measurements.
    $event_types = ['manatee_rescue', 'manatee_birth', 'transfer'];

    foreach ($event_types as $type) {
      $date_field = $type === 'transfer' ? 'field_transfer_date' : 'field_rescue_date';
      if ($type === 'manatee_birth') {
        $date_field = 'field_birth_date';
      }

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
        if ($node) {
          if ($node->hasField('field_weight') && !$node->field_weight->isEmpty()) {
            $measurements['weight'] = $node->field_weight->value . ' kg';
          }
          if ($node->hasField('field_length') && !$node->field_length->isEmpty()) {
            $measurements['length'] = $node->field_length->value . ' cm';
          }
          // If we found measurements, break out of the loop.
          if ($measurements['weight'] !== 'N/A' || $measurements['length'] !== 'N/A') {
            break;
          }
        }
      }
    }

    return $measurements;
  }

  /**
   * Get current facility for a manatee.
   */
  protected function getCurrentFacility($manatee_id) {
    $event_types = ['transfer', 'manatee_rescue', 'manatee_birth'];
    $facility = 'N/A';

    foreach ($event_types as $type) {
      $query = $this->entityTypeManager->getStorage('node')->getQuery()
        ->condition('type', $type)
        ->condition('field_animal', $manatee_id)
        ->sort('field_' . ($type === 'transfer' ? 'transfer' : 'rescue') . '_date', 'DESC')
        ->range(0, 1)
        ->accessCheck(FALSE)
        ->execute();

      if (!empty($query)) {
        $node = $this->entityTypeManager->getStorage('node')->load(reset($query));
        if ($node) {
          $facility_field = $type === 'transfer' ? 'field_to_facility' : 'field_org';
          if ($node->hasField($facility_field) && !$node->get($facility_field)->isEmpty()) {
            $facility_term = $node->get($facility_field)->entity;
            if ($facility_term && $facility_term->hasField('field_organization')) {
              $facility = $facility_term->field_organization->value;
              break;
            }
          }
        }
      }
    }

    return $facility;
  }

  /**
   * Calculate time in captivity.
   */
  protected function calculateTimeInCaptivity($manatee_id) {
    $rescue_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'manatee_rescue')
      ->condition('field_animal', $manatee_id)
      ->condition('field_rescue_type', 'B')
      ->sort('field_rescue_date', 'ASC')
      ->range(0, 1)
      ->accessCheck(FALSE)
      ->execute();

    if (!empty($rescue_query)) {
      $rescue_node = $this->entityTypeManager->getStorage('node')->load(reset($rescue_query));
      if ($rescue_node && !$rescue_node->field_rescue_date->isEmpty()) {
        $rescue_date = new DrupalDateTime($rescue_node->field_rescue_date->value);
        $current_date = new DrupalDateTime();
        $interval = $current_date->diff($rescue_date);

        if ($interval->y > 0) {
          return $interval->format('%y yr, %m mo');
        }
        return $interval->format('%m mo');
      }
    }

    return 'N/A';
  }

  /**
   * Returns the search results page content.
   *
   * @return array
   *   Render array for the page.
   */
  public function content() {
    $query = $this->requestStack->getCurrentRequest()->query->all();

    // Set pagination parameters.
    $items_per_page = 20;

    // Get total results.
    $manatee_ids = $this->searchManager->searchManatees($query);
    $total_items = count($manatee_ids);

    // Initialize the pager.
    $pager = \Drupal::service('pager.manager')->createPager($total_items, $items_per_page);
    $current_page = $pager->getCurrentPage();

    // Slice results for current page.
    $page_manatee_ids = array_slice($manatee_ids, $current_page * $items_per_page, $items_per_page);

    if (empty($page_manatee_ids)) {
      return [
        '#markup' => $this->t('No manatees found matching your search criteria.'),
      ];
    }

    $manatees = $this->entityTypeManager->getStorage('node')->loadMultiple($page_manatee_ids);

    // Build rows.
    $rows = [];
    foreach ($manatees as $manatee) {
      $manatee_id = $manatee->id();

      // Get MLOG with link.
      $mlog = $manatee->hasField('field_mlog') && !$manatee->field_mlog->isEmpty()
      ? $manatee->field_mlog->value
      : 'N/A';

      $mlog_link = Link::createFromRoute(
      $mlog,
      'entity.node.canonical',
      ['node' => $manatee_id]
      );

      // Get rescue information.
      $rescue_info = $this->getRescueInfo($manatee_id);

      // Get measurements.
      $measurements = $this->getLatestMeasurements($manatee_id);

      // Format measurements string.
      $measurements_str = 'N/A';
      if ($measurements['weight'] !== 'N/A' || $measurements['length'] !== 'N/A') {
        $measurements_str = implode(', ', array_filter($measurements, function ($v) {
          return $v !== 'N/A';
        }));
      }

      $rows[] = [
        'data' => [
        ['data' => $this->getCurrentFacility($manatee_id)],
        ['data' => $this->getPrimaryName($manatee_id)],
        ['data' => $this->getAnimalId($manatee_id)],
        ['data' => $mlog_link],
        ['data' => $measurements_str],
        ['data' => $rescue_info['county']],
        ['data' => $rescue_info['date']],
        ['data' => $rescue_info['cause']],
        ['data' => $this->calculateTimeInCaptivity($manatee_id)],
        ['data' => $this->getManateeStatus($manatee_id)],
        ],
      ];
    }

    // Sort rows by facility and then by name.
    usort($rows, function ($a, $b) {
      $facility_compare = strcmp($a['data'][0]['data'], $b['data'][0]['data']);
      if ($facility_compare === 0) {
        return strcmp($a['data'][1]['data'], $b['data'][1]['data']);
      }
      return $facility_compare;
    });

    $header = [
      $this->t('Facility'),
      $this->t('Name'),
      $this->t('Manatee ID'),
      $this->t('Manatee Number'),
      $this->t('Weight, Length'),
      $this->t('County'),
      $this->t('Rescue Date'),
      $this->t('Cause of Rescue'),
      $this->t('Time in Captivity'),
      $this->t('Medical Status'),
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
      '#attached' => [
        'library' => [
          'manatee_reports/manatee_reports',
        ],
      ],
    ];
  }

}
