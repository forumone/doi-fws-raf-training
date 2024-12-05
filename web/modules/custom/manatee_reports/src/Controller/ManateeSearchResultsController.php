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

  protected $searchManager;
  protected $entityTypeManager;
  protected $requestStack;

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
   *
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('manatee_reports.search_manager'),
      $container->get('entity_type.manager'),
      $container->get('request_stack')
    );
  }

  /**
   *
   */
  protected function getMlog($manatee) {
    return !$manatee->field_mlog->isEmpty() ? $manatee->field_mlog->value : 'N/A';
  }

  /**
   *
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
   *
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
   *
   */
  protected function getLatestEvent($manatee_id) {
    $event_types = [
      'manatee_birth' => 'field_birth_date',
      'manatee_rescue' => 'field_rescue_date',
      'transfer' => 'field_transfer_date',
      'manatee_release' => 'field_release_date',
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
          $events[] = [
            'type' => str_replace('manatee_', '', $type),
            'type_raw' => $type,
            'date' => $node->get($date_field)->value,
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
   *
   */
  public function content() {
    $query = $this->requestStack->getCurrentRequest()->query->all();
    $items_per_page = 20;

    $manatee_ids = $this->searchManager->searchManatees($query);
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
