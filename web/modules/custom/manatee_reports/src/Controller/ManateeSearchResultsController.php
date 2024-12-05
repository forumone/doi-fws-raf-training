<?php

namespace Drupal\manatee_reports\Controller;

use Drupal\Core\Controller\ControllerBase;
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
   * Constructs a new ManateeSearchResultsController.
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
   * Process search parameters from URL or form submission.
   *
   * @param array $params
   *   The search parameters.
   *
   * @return array
   *   Array of query conditions.
   */
  protected function processSearchParameters(array $params) {
    $conditions = [];

    // Process URL parameters for individual search.
    $individual_params = [
      'mlog' => ['field' => 'field_mlog'],
      'animal_id' => ['type' => 'manatee_animal_id', 'field' => 'field_animal_id', 'operator' => 'CONTAINS'],
      'manatee_name' => ['type' => 'manatee_name', 'field' => 'field_name', 'operator' => 'CONTAINS'],
      'tag_id' => ['type' => 'manatee_tag', 'field' => 'field_tag_id', 'operator' => 'CONTAINS'],
      'tag_type' => ['type' => 'manatee_tag', 'field' => 'field_tag_type'],
    ];

    foreach ($individual_params as $param => $config) {
      if (!empty($params[$param]) && $params[$param] !== 'All') {
        $condition = [
          'field' => $config['field'],
          'value' => $params[$param],
        ];
        if (isset($config['type'])) {
          $condition['type'] = $config['type'];
        }
        if (isset($config['operator'])) {
          $condition['operator'] = $config['operator'];
        }
        $conditions[] = $condition;
      }
    }

    // Process location parameters.
    $location_params = [
      'waterway' => ['field' => 'field_waterway', 'operator' => 'CONTAINS'],
      'state' => ['field' => 'field_state', 'operator' => '='],
    ];

    foreach ($location_params as $param => $config) {
      if (!empty($params[$param]) && $params[$param] !== 'All') {
        $condition = is_array($config) ?
        ['field' => $config['field'], 'value' => $params[$param], 'operator' => $config['operator']] :
        ['field' => $config, 'value' => $params[$param]];
        $conditions[] = $condition;
      }
    }

    // Handle county parameter specifically for manatee_rescue nodes.
    if (!empty($params['county']) && $params['county'] !== 'All') {
      $rescue_query = $this->entityTypeManager->getStorage('node')->getQuery()
        ->condition('type', 'manatee_rescue')
        ->condition('field_county', $params['county'])
        ->accessCheck(FALSE)
        ->execute();

      if (!empty($rescue_query)) {
        $manatee_ids = [];
        $rescues = $this->entityTypeManager->getStorage('node')->loadMultiple($rescue_query);
        foreach ($rescues as $rescue) {
          if (!$rescue->field_animal->isEmpty()) {
            $manatee_ids[] = $rescue->field_animal->target_id;
          }
        }

        if (!empty($manatee_ids)) {
          $conditions[] = [
            'field' => 'nid',
            'value' => $manatee_ids,
            'operator' => 'IN',
          ];
        }
        else {
          // Force no results if no manatees found with rescues in the specified county.
          $conditions[] = [
            'field' => 'nid',
            'value' => 0,
          ];
        }
      }
    }

    // Process event parameters.
    if (!empty($params['event_type']) && $params['event_type'] !== 'All') {
      $conditions[] = [
        'field' => 'type',
        'value' => $params['event_type'],
      ];
    }

    if (!empty($params['from'])) {
      $conditions[] = [
        'field' => 'field_event_date',
        'value' => date('Y-m-d', strtotime($params['from'])),
        'operator' => '>=',
      ];
    }

    if (!empty($params['to'])) {
      $conditions[] = [
        'field' => 'field_event_date',
        'value' => date('Y-m-d', strtotime($params['to'])),
        'operator' => '<=',
      ];
    }

    // Process event detail parameters.
    $detail_params = [
      'rescue_type' => 'field_rescue_type',
      'rescue_cause' => 'field_rescue_cause',
      'organization' => 'field_organization',
      'cause_of_death' => 'field_cause_of_death',
    ];

    foreach ($detail_params as $param => $field) {
      if (!empty($params[$param]) && $params[$param] !== 'All') {
        $conditions[] = [
          'field' => $field,
          'value' => $params[$param],
        ];
      }
    }

    return $conditions;
  }

  /**
   * Get MLog value for a manatee.
   *
   * @param object $manatee
   *   The manatee node.
   *
   * @return string
   *   The MLog value or 'N/A'.
   */
  protected function getMlog($manatee) {
    return !$manatee->field_mlog->isEmpty() ? $manatee->field_mlog->value : 'N/A';
  }

  /**
   * Get primary name for a manatee.
   *
   * @param int $manatee_id
   *   The manatee node ID.
   *
   * @return string
   *   The primary name or 'N/A'.
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
      if ($name_node && !$name_node->field_name->isEmpty()) {
        return $name_node->field_name->value;
      }
    }
    return 'N/A';
  }

  /**
   * Get animal ID for a manatee.
   *
   * @param int $manatee_id
   *   The manatee node ID.
   *
   * @return string
   *   The animal ID or 'N/A'.
   */
  protected function getAnimalId($manatee_id) {
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
   * Get latest event for a manatee.
   *
   * @param int $manatee_id
   *   The manatee node ID.
   *
   * @return array
   *   Array containing event type and date.
   */
  protected function getLatestEvent($manatee_id) {
    $event_types = [
      'manatee_birth' => 'field_birth_date',
      'manatee_rescue' => 'field_rescue_date',
      'transfer' => 'field_transfer_date',
      'manatee_release' => 'field_release_date',
      'manatee_death' => 'field_death_date',
    ];

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
   * Main page callback for search results.
   *
   * @return array
   *   Render array for search results page.
   */
  public function content() {
    $request = $this->requestStack->getCurrentRequest();
    $query = array_merge(
      $request->query->all(),
      $request->request->all()
    );

    // Remove Drupal form specific parameters.
    unset($query['form_build_id'], $query['form_id'], $query['op']);

    $conditions = $this->processSearchParameters($query);

    $items_per_page = 20;
    $manatee_ids = $this->searchManager->searchManatees($conditions);
    $total_items = count($manatee_ids);

    $pager = \Drupal::service('pager.manager')->createPager($total_items, $items_per_page);
    $current_page = $pager->getCurrentPage();

    $page_manatee_ids = array_slice($manatee_ids, $current_page * $items_per_page, $items_per_page);

    if (empty($page_manatee_ids)) {
      return [
        '#markup' => $this->t('No manatees found matching your search criteria.'),
      ];
    }

    $manatees = $this->entityTypeManager->getStorage('node')->loadMultiple($page_manatee_ids);

    $rows = [];
    foreach ($manatees as $manatee) {
      $manatee_id = $manatee->id();
      $latest_event = $this->getLatestEvent($manatee_id);

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
      '#attached' => [
        'library' => [
          'manatee_reports/manatee_reports',
        ],
      ],
    ];
  }

}
