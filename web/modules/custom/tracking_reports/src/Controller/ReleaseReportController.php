<?php

namespace Drupal\tracking_reports\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\node\NodeInterface;
use Drupal\tracking_reports\TrackingSearchManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Url;

/**
 * Controller for the species release report.
 */
class ReleaseReportController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The date formatter.
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
   * The pager manager.
   *
   * @var \Drupal\Core\Pager\PagerManagerInterface
   */
  protected $pagerManager;

  /**
   * Items to show per page.
   *
   * @var int
   */
  protected $itemsPerPage = 25;

  /**
   * Maximum records to process to prevent performance issues.
   *
   * @var int
   */
  protected $maxRecords = 1000;

  /**
   * Constructs a ReleaseReportController object.
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
   * Gets a sortable value from a table cell.
   *
   * @param mixed $cell_data
   *   The cell data, which can be a Link object or a render array.
   *
   * @return string
   *   The sortable string value.
   */
  protected function getSortableValue($cell_data) {
    // Handle Link objects.
    if ($cell_data instanceof Link) {
      return $cell_data->getText();
    }

    // Handle arrays with Link objects or plain data.
    if (is_array($cell_data)) {
      if (isset($cell_data['data'])) {
        if ($cell_data['data'] instanceof Link) {
          return $cell_data['data']->getText();
        }
        return (string) $cell_data['data'];
      }
    }

    // Handle direct string values.
    return (string) $cell_data;
  }

  /**
   * Sorts the rows array by the specified column.
   *
   * @param array $rows
   *   The array of table rows to sort.
   * @param string $sort
   *   The column key to sort by.
   * @param string $direction
   *   The sort direction, either 'asc' or 'desc'.
   *
   * @return array
   *   The sorted array of table rows.
   */
  protected function sortRows(array $rows, $sort, $direction) {
    $column_map = [
      'name' => 0,
      'sex' => 1,
      'species_id' => 2,
      'rescue_date' => 4,
      'release_date' => 5,
      'rescue_cause' => 6,
      'rescue_county' => 9,
      'release_county' => 10,
    ];

    // If the sort column isn't recognized, just return rows unsorted.
    if (!isset($column_map[$sort])) {
      return $rows;
    }

    $column = $column_map[$sort];

    usort($rows, function ($a, $b) use ($column, $direction, $sort) {
      // Get sortable values, handling both Link objects and arrays with 'data' key.
      $a_value = $this->getSortableValue($a['data'][$column]);
      $b_value = $this->getSortableValue($b['data'][$column]);

      // Special handling for N/A values - always sort them last.
      if ($a_value === 'N/A' && $b_value === 'N/A') {
        return 0;
      }
      if ($a_value === 'N/A') {
        return ($direction === 'asc') ? 1 : -1;
      }
      if ($b_value === 'N/A') {
        return ($direction === 'asc') ? -1 : 1;
      }

      // Date column handling (rescue_date = 4, release_date = 5).
      if (in_array($sort, ['rescue_date', 'release_date'])) {
        $a_timestamp = strtotime($a_value);
        $b_timestamp = strtotime($b_value);

        if ($a_timestamp === $b_timestamp) {
          return 0;
        }
        return ($direction === 'asc')
          ? ($a_timestamp <=> $b_timestamp)
          : ($b_timestamp <=> $a_timestamp);
      }

      // Default string comparison.
      $comparison = strcasecmp($a_value, $b_value);
      return ($direction === 'asc') ? $comparison : -$comparison;
    });

    return $rows;
  }

  /**
   * Gets the most recent rescue event for a species.
   *
   * @param int $species_id
   *   The species ID.
   *
   * @return \Drupal\node\NodeInterface|null
   *   The most recent rescue node or NULL if none found.
   */
  protected function getMostRecentRescue($species_id) {
    $rescue_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'species_rescue')
      ->condition('field_species_ref', $species_id)
      ->condition('field_rescue_date', NULL, 'IS NOT NULL')
      ->sort('field_rescue_date', 'DESC')
      ->range(0, 1)
      ->accessCheck(FALSE);

    $results = $rescue_query->execute();
    if (!empty($results)) {
      return $this->entityTypeManager->getStorage('node')->load(reset($results));
    }
    return NULL;
  }

  /**
   * Gets rescue cause detail.
   *
   * @param \Drupal\node\NodeInterface $rescue_node
   *   The rescue node.
   *
   * @return string
   *   The rescue cause detail or 'N/A'.
   */
  protected function getRescueCauseDetail(NodeInterface $rescue_node) {
    if (!$rescue_node->field_primary_cause->isEmpty()) {
      $cause_term = $rescue_node->field_primary_cause->entity;
      if ($cause_term && !$cause_term->field_rescue_cause_detail->isEmpty()) {
        return $cause_term->field_rescue_cause_detail->value;
      }
    }
    return 'N/A';
  }

  /**
   * Gets formatted metrics string for weight/length.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface|null $weight
   *   The weight field.
   * @param \Drupal\Core\Field\FieldItemListInterface|null $length
   *   The length field.
   *
   * @return string
   *   The formatted metrics string.
   */
  protected function getMetricsString($weight, $length) {
    $weight_val = ($weight && !$weight->isEmpty()) ? $weight->value : 'N/A';
    $length_val = ($length && !$length->isEmpty()) ? $length->value : 'N/A';

    if ($weight_val === 'N/A' && $length_val === 'N/A') {
      return 'N/A';
    }
    return $weight_val . ' kg, ' . $length_val . ' cm';
  }

  /**
   * Builds the report page.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return array
   *   A render array representing the report page.
   */
  public function content(Request $request) {
    $build = [];

    // Add filter form.
    $form_object = \Drupal::formBuilder()->getForm('Drupal\tracking_reports\Form\ReleaseFilterForm');
    $build['filters'] = $form_object;

    // Get and apply filters.
    $filters = $request->query->all();

    // Determine the prior year.
    $current_year = (int) date('Y');
    $prior_year = $current_year - 1;

    // Set default date filters to prior year if not provided.
    $release_date_from = isset($filters['release_date_from']) && !empty($filters['release_date_from']) ? $filters['release_date_from'] : "$prior_year-01-01";
    $release_date_to = isset($filters['release_date_to']) && !empty($filters['release_date_to']) ? $filters['release_date_to'] : "$prior_year-12-31";

    // Force default sort params if not specified.
    $sort = isset($filters['sort']) && !empty($filters['sort']) ? $filters['sort'] : 'release_date';
    $direction = isset($filters['direction']) && !empty($filters['direction']) ? $filters['direction'] : 'desc';

    // Build base query.
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'species_release')
      ->condition('field_species_ref', NULL, 'IS NOT NULL')
      ->accessCheck(FALSE);

    // Apply search filters (keeping existing search logic).
    if (!empty($filters['search'])) {
      $search_term = $filters['search'];
      $or_group = $query->orConditionGroup();

      // Add existing search conditions.
      $matching_species_id_nids = $this->getMatchingSpeciesIdNodeIds($search_term);
      if (!empty($matching_species_id_nids)) {
        $species_ids = $this->getReferencedSpeciesIds($matching_species_id_nids);
        if (!empty($species_ids)) {
          $or_group->condition('field_species_ref', $species_ids, 'IN');
        }
      }

      $matching_species_nids = $this->getMatchingSpeciesNodeIds($search_term);
      if (!empty($matching_species_nids)) {
        $or_group->condition('field_species_ref', $matching_species_nids, 'IN');
      }

      $county_ids = $this->getMatchingCountyIds($search_term);
      if (!empty($county_ids)) {
        $or_group->condition('field_county', $county_ids, 'IN');
      }

      $cause_ids = $this->getMatchingCauseIds($search_term);
      if (!empty($cause_ids)) {
        $or_group->condition('field_primary_cause', $cause_ids, 'IN');
      }

      if ($or_group->count() > 0) {
        $query->condition($or_group);
      }
      else {
        // If no conditions matched, ensure no results are returned.
        $query->condition('nid', 0);
      }
    }

    // Apply date range filters (now required).
    $query->condition('field_release_date', $release_date_from, '>=');
    $query->condition('field_release_date', $release_date_to, '<=');

    // Execute query and load nodes.
    $release_ids = $query->execute();

    // Check for maximum records to prevent performance issues.
    if (count($release_ids) > $this->maxRecords) {
      $build['table'] = [
        '#markup' => $this->t('Too many records found (@count). Please refine your filters.', ['@count' => count($release_ids)]),
      ];
      return $build;
    }

    if (empty($release_ids)) {
      $build['table'] = [
        '#markup' => $this->t('No release records found.'),
      ];
      return $build;
    }

    $release_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($release_ids);
    $rows = [];

    // Build rows array.
    foreach ($release_nodes as $release) {
      $species_entity = $release->field_species_ref->entity;
      if (!$species_entity) {
        continue;
      }

      $rescue = $this->getMostRecentRescue($species_entity->id());
      if (!$rescue) {
        continue;
      }

      // Get row data.
      $row_data = $this->buildRowData($species_entity, $rescue, $release);
      $rows[] = ['data' => $row_data];
    }

    // Handle sorting for all columns using sortRows().
    $rows = $this->sortRows($rows, $sort, $direction);

    // Implement pagination manually after sorting.
    $total_records = count($rows);
    $pager = $this->pagerManager->createPager($total_records, $this->itemsPerPage);
    $current_page = $pager->getCurrentPage();
    $offset = $current_page * $this->itemsPerPage;
    $paged_rows = array_slice($rows, $offset, $this->itemsPerPage);

    // Build table header with sortable links.
    $headers = [
      ['data' => $this->buildSortLink('Name', 'name')],
      ['data' => $this->buildSortLink('Sex', 'sex')],
      ['data' => $this->buildSortLink('Species ID', 'species_id')],
      ['data' => 'Number'],
      ['data' => $this->buildSortLink('Rescue Date', 'rescue_date')],
      ['data' => $this->buildSortLink('Release Date', 'release_date')],
      ['data' => $this->buildSortLink('Cause of Rescue', 'rescue_cause')],
      ['data' => 'Rescue Weight, Length'],
      ['data' => 'Release Weight, Length'],
      ['data' => $this->buildSortLink('Rescue County', 'rescue_county')],
      ['data' => $this->buildSortLink('Release County', 'release_county')],
    ];

    // Build table.
    $build['table'] = [
      '#type' => 'table',
      '#header' => $headers,
      '#rows' => $paged_rows,
      '#empty' => $this->t('No release records found.'),
      '#attributes' => ['class' => ['tracking-release-report']],
      '#cache' => [
        'max-age' => 0,
        'contexts' => ['url.query_args', 'user.permissions'],
        'tags' => ['node_list:species_release'],
      ],
    ];

    // Add pager.
    $build['pager'] = [
      '#type' => 'pager',
      '#quantity' => 5,
    ];

    return $build;
  }

  /**
   * Builds a sortable link for table headers.
   *
   * @param string $label
   *   The label for the header.
   * @param string $field
   *   The field key used for sorting.
   *
   * @return array
   *   A render array representing the sortable link.
   */
  protected function buildSortLink($label, $field) {
    $current_route = \Drupal::routeMatch()->getRouteName();
    $request = \Drupal::request();
    $filters = $request->query->all();

    $current_sort = $filters['sort'] ?? '';
    $current_direction = $filters['direction'] ?? 'asc';

    if ($current_sort === $field) {
      // Toggle direction.
      $new_direction = $current_direction === 'asc' ? 'desc' : 'asc';
      $sort_indicator = $current_direction === 'asc' ? '↑' : '↓';
    }
    else {
      // Default direction is 'asc'.
      $new_direction = 'asc';
      $sort_indicator = '';
    }

    // Build new query params, preserving existing filters except sort and direction.
    $new_query = $filters;
    $new_query['sort'] = $field;
    $new_query['direction'] = $new_direction;

    // Build the link.
    $url = Url::fromRoute($current_route, [], ['query' => $new_query]);

    // Return a render array for the link.
    return [
      '#type' => 'link',
      '#title' => $this->t('@label @indicator', [
        '@label' => $label,
        '@indicator' => $sort_indicator,
      ]),
      '#url' => $url,
    ];
  }

  /**
   * Helper method: buildRowData().
   *
   * @param \Drupal\node\NodeInterface $species_entity
   *   The species node entity.
   * @param \Drupal\node\NodeInterface $rescue
   *   The rescue node entity.
   * @param \Drupal\node\NodeInterface $release
   *   The release node entity.
   *
   * @return array
   *   An array representing the table row data.
   */
  protected function buildRowData(NodeInterface $species_entity, NodeInterface $rescue, NodeInterface $release) {
    // Get the species name.
    $name = $species_entity->getTitle();

    // Build the rest of the row data.
    return [
      ['data' => $name],
      ['data' => !$species_entity->field_sex->isEmpty() ? $species_entity->field_sex->entity->label() : 'N/A'],
      ['data' => $this->trackingSearchManager->getPrimarySpeciesId($species_entity->id()) ?? 'N/A'],
      [
        'data' => [
          '#type' => 'link',
          '#title' => !$species_entity->field_number->isEmpty() ? $species_entity->field_number->value : 'N/A',
          '#url' => Url::fromRoute('entity.node.canonical', ['node' => $species_entity->id()]),
        ],
      ],
      [
        'data' => !$rescue->field_rescue_date->isEmpty()
          ? $this->dateFormatter->format(strtotime($rescue->field_rescue_date->value), 'custom', 'm/d/Y')
          : 'N/A',
      ],
      [
        'data' => !$release->field_release_date->isEmpty()
          ? [
            '#type' => 'link',
            '#title' => $this->dateFormatter->format(strtotime($release->field_release_date->value), 'custom', 'm/d/Y'),
            '#url' => Url::fromRoute('entity.node.canonical', ['node' => $release->id()]),
          ]
          : 'N/A',
      ],
      ['data' => $this->getRescueCauseDetail($rescue)],
      ['data' => $this->getMetricsString($rescue->field_weight ?? NULL, $rescue->field_length ?? NULL)],
      ['data' => $this->getPreReleaseMetrics($species_entity->id())],
      ['data' => !$rescue->field_county->isEmpty() ? $rescue->field_county->entity->label() : 'N/A'],
      ['data' => !$release->field_county->isEmpty() ? $release->field_county->entity->label() : 'N/A'],
    ];
  }

  /**
   * Gets the pre-release metrics if available.
   *
   * @param int $species_id
   *   The species ID.
   *
   * @return string
   *   The formatted pre-release metrics or 'N/A'.
   */
  protected function getPreReleaseMetrics($species_id) {
    $prerelease_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'species_prerelease')
      ->condition('field_species_ref', $species_id)
      ->sort('created', 'DESC')
      ->range(0, 1)
      ->accessCheck(FALSE);

    $results = $prerelease_query->execute();
    if (!empty($results)) {
      $prerelease = $this->entityTypeManager->getStorage('node')->load(reset($results));
      return $this->getMetricsString($prerelease->field_weight ?? NULL, $prerelease->field_length ?? NULL);
    }
    return 'N/A';
  }

  /**
   * Helper method: getMatchingSpeciesIdNodeIds().
   *
   * Searches the 'species_id' node type by its field_species_id text field.
   * Returns an array of node IDs for matching 'species_id' nodes.
   *
   * @param string $search_term
   *   The search term.
   *
   * @return array
   *   An array of species_id node IDs.
   */
  protected function getMatchingSpeciesIdNodeIds($search_term) {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'species_id')
      ->condition('field_species_id', $search_term, 'CONTAINS')
      ->accessCheck(FALSE);

    return $query->execute();
  }

  /**
   * Helper method: getReferencedSpeciesIds().
   *
   * Extracts species IDs from the given species_id node IDs.
   *
   * @param array $species_id_nids
   *   An array of species_id node IDs.
   *
   * @return array
   *   An array of species IDs.
   */
  protected function getReferencedSpeciesIds(array $species_id_nids) {
    $species_id_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($species_id_nids);
    $species_ids = [];

    foreach ($species_id_nodes as $node) {
      if (!$node->field_species_id->isEmpty()) {
        $species_ids[] = $node->field_species_id->value;
      }
    }

    return $species_ids;
  }

  /**
   * Helper method: getMatchingSpeciesNodeIds().
   *
   * Searches the 'species' node type by:
   *   - field_number on the node (CONTAINS $search_term)
   *   - field_name on any referenced 'species_name' paragraphs (CONTAINS $search_term)
   * Returns an array of node IDs for matching 'species' nodes.
   *
   * @param string $search_term
   *   The search term.
   *
   * @return array
   *   An array of species node IDs.
   */
  protected function getMatchingSpeciesNodeIds($search_term) {
    // First, find all paragraphs of type 'species_name' that contain the $search_term in field_name.
    $paragraph_ids = $this->entityTypeManager->getStorage('paragraph')->getQuery()
      ->condition('type', 'species_name')
      ->condition('field_name', $search_term, 'CONTAINS')
      ->accessCheck(FALSE)
      ->execute();

    // Now build a query for species nodes.
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'species')
      ->accessCheck(FALSE);

    // Build an OR group for:
    // 1) field_number matches $search_term
    // 2) field_names references any paragraph whose ID is in $paragraph_ids.
    $or_group = $query->orConditionGroup()
      ->condition('field_number', $search_term, 'CONTAINS');

    if (!empty($paragraph_ids)) {
      $or_group->condition('field_names', $paragraph_ids, 'IN');
    }

    $query->condition($or_group);

    return $query->execute();
  }

  /**
   * Returns taxonomy term IDs for counties whose name matches $search_term.
   *
   * @param string $search_term
   *   The search term.
   *
   * @return array
   *   An array of taxonomy term IDs.
   */
  protected function getMatchingCountyIds($search_term) {
    return $this->entityTypeManager->getStorage('taxonomy_term')->getQuery()
      ->condition('vid', 'counties')
      ->condition('name', $search_term, 'CONTAINS')
      ->accessCheck(FALSE)
      ->execute();
  }

  /**
   * Returns taxonomy term IDs for rescue causes whose detail matches $search_term.
   *
   * @param string $search_term
   *   The search term.
   *
   * @return array
   *   An array of taxonomy term IDs.
   */
  protected function getMatchingCauseIds($search_term) {
    return $this->entityTypeManager->getStorage('taxonomy_term')->getQuery()
      ->condition('vid', 'rescue_causes')
      ->condition('field_rescue_cause_detail', $search_term, 'CONTAINS')
      ->accessCheck(FALSE)
      ->execute();
  }

}
