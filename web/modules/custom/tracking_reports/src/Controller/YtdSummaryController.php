<?php

namespace Drupal\tracking_reports\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\tracking_reports\TrackingSearchManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for displaying YTD summary and records.
 */
class YtdSummaryController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * The tracking search manager.
   *
   * @var \Drupal\tracking_reports\TrackingSearchManager
   */
  protected $trackingSearchManager;

  /**
   * The pager manager service.
   *
   * @var \Drupal\Core\Pager\PagerManagerInterface
   */
  protected $pagerManager;

  /**
   * Maximum number of records to display.
   *
   * @var int
   */
  protected $maxRecords = 10;

  /**
   * Constructs a YtdSummaryController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter service.
   * @param \Drupal\tracking_reports\TrackingSearchManager $tracking_search_manager
   *   The tracking search manager service.
   * @param \Drupal\Core\Pager\PagerManagerInterface $pager_manager
   *   The pager manager service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    DateFormatterInterface $date_formatter,
    TrackingSearchManager $tracking_search_manager,
    PagerManagerInterface $pager_manager,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->dateFormatter = $date_formatter;
    $this->trackingSearchManager = $tracking_search_manager;
    $this->pagerManager = $pager_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
      $container->get('tracking_reports.search_manager'),
      $container->get('pager.manager')
    );
  }

  /**
   * Returns the page content.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return array
   *   A render array for the page.
   */
  public function content(Request $request) {
    // Get current year.
    $current_year = date('Y');
    $start_date = $current_year . '-01-01';
    $end_date = $current_year . '-12-31';

    // Get count of manatee rescues for current year.
    $rescue_count = $this->getEventCount('species_rescue', $start_date, $end_date);

    // Get count of manatee releases for current year.
    $release_count = $this->getEventCount('species_release', $start_date, $end_date);

    // Get count of manatees currently in rehabilitation.
    $rehab_count = $this->getManateesInRehab();

    // Get recent records.
    $records = $this->getRecentRecords($request);

    // Build the render array using the theme.
    $build = [
      '#theme' => 'tracking-reports-ytd-summary',
      '#rescue_count' => $rescue_count,
      '#release_count' => $release_count,
      '#rehab_count' => $rehab_count,
      '#records' => $records,
      '#current_year' => $current_year,
      '#attached' => [
        'library' => [
          'tracking_reports/tracking_reports',
        ],
      ],
    ];

    return $build;
  }

  /**
   * Gets the count of events of a specific type within a date range.
   *
   * @param string $event_type
   *   The event type (species_rescue, species_release, etc.).
   * @param string $start_date
   *   The start date in Y-m-d format.
   * @param string $end_date
   *   The end date in Y-m-d format.
   *
   * @return int
   *   The count of events.
   */
  protected function getEventCount($event_type, $start_date, $end_date) {
    $date_field = '';
    switch ($event_type) {
      case 'species_rescue':
        $date_field = 'field_rescue_date';
        break;

      case 'species_release':
        $date_field = 'field_release_date';
        break;

      default:
        return 0;
    }

    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', $event_type)
      ->condition($date_field, [$start_date, $end_date], 'BETWEEN')
      ->accessCheck(FALSE)
      ->count();

    return $query->execute();
  }

  /**
   * Gets the count of manatees currently in rehabilitation.
   *
   * @return int
   *   The count of manatees in rehabilitation.
   */
  protected function getManateesInRehab() {
    // Get species that have been rescued but not released or died.
    $species_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'species')
      ->accessCheck(FALSE);

    // Subquery to find species with rescue events.
    $rescue_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'species_rescue')
      ->condition('field_species_ref', $species_query, 'IN')
      ->accessCheck(FALSE);
    $rescued_species = $rescue_query->execute();

    if (empty($rescued_species)) {
      return 0;
    }

    // Subquery to find species with release events.
    $release_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'species_release')
      ->condition('field_species_ref', array_keys($rescued_species), 'IN')
      ->accessCheck(FALSE);
    $released_species = $release_query->execute();

    // Subquery to find species with death events.
    $death_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'species_death')
      ->condition('field_species_ref', array_keys($rescued_species), 'IN')
      ->accessCheck(FALSE);
    $deceased_species = $death_query->execute();

    // Combine released and deceased species.
    $excluded_species = array_merge(
      array_keys($released_species),
      array_keys($deceased_species)
    );

    // Count species that have been rescued but not released or died.
    $in_rehab_count = count(array_diff_key($rescued_species, array_flip($excluded_species)));

    return $in_rehab_count;
  }

  /**
   * Gets recent records for the table display.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return array
   *   A render array for the records table.
   */
  protected function getRecentRecords(Request $request) {
    // Query for all node types.
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->sort('created', 'DESC')
      ->pager($this->maxRecords);

    $nids = $query->execute();

    if (empty($nids)) {
      return [
        '#type' => 'markup',
        '#markup' => $this->t('No records found.'),
      ];
    }

    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);

    // Build table headers.
    $header = [
      $this->t('Title'),
      $this->t('Type'),
      $this->t('Author'),
      $this->t('Created'),
      $this->t('Operations'),
    ];

    // Build table rows.
    $rows = [];
    foreach ($nodes as $node) {
      $row = [];

      // Title with link - use Link::fromTextAndUrl() and render it properly.
      $title_link = Link::fromTextAndUrl(
        $node->getTitle(),
        Url::fromRoute('entity.node.canonical', ['node' => $node->id()])
      );
      $row[] = [
        'data' => [
          '#type' => 'link',
          '#title' => $node->getTitle(),
          '#url' => Url::fromRoute('entity.node.canonical', ['node' => $node->id()]),
        ],
      ];

      // Node type.
      $node_type = $node->getType();
      $row[] = $this->entityTypeManager->getStorage('node_type')->load($node_type)->label();

      // Author.
      $author = $node->getOwner();
      $author_name = $author ? $author->getDisplayName() : $this->t('Unknown');
      $row[] = $author_name;

      // Created date.
      $created = $this->dateFormatter->format($node->getCreatedTime(), 'custom', 'm/d/Y');
      $row[] = $created;

      // Operations - ensure URLs are properly rendered.
      if ($node->access('update')) {
        $row[] = [
          'data' => [
            '#type' => 'link',
            '#title' => $this->t('Edit'),
            '#url' => Url::fromRoute('entity.node.edit_form', ['node' => $node->id()]),
          ],
        ];
      }
      else {
        $row[] = '';
      }

      $rows[] = $row;
    }

    // Build the table.
    $build = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No records found.'),
      '#attributes' => [
        'class' => ['ytd-records-table'],
      ],
    ];

    // Add the pager.
    $build['pager'] = [
      '#type' => 'pager',
    ];

    return $build;
  }

}
