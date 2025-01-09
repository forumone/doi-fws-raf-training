<?php

namespace Drupal\tracking_reports\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\tracking_reports\TrackingSearchManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

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
   * Items to show per page.
   *
   * @var int
   */
  protected $itemsPerPage = 25;

  /**
   * Constructs a ReleaseReportController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter service.
   * @param \Drupal\tracking_reports\TrackingSearchManager $tracking_search_manager
   *   The tracking search manager service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    DateFormatterInterface $date_formatter,
    TrackingSearchManager $tracking_search_manager
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->dateFormatter = $date_formatter;
    $this->trackingSearchManager = $tracking_search_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
      $container->get('tracking_reports.search_manager')
    );
  }

  /**
   * Gets a sortable value from a table cell.
   */
  protected function getSortableValue($cell_data) {
    if ($cell_data instanceof Link) {
      return $cell_data->getText();
    }
    if (is_array($cell_data) && isset($cell_data['data'])) {
      if ($cell_data['data'] instanceof Link) {
        return $cell_data['data']->getText();
      }
      return (string) $cell_data['data'];
    }
    return (string) $cell_data;
  }

  /**
   * Sorts the rows array by the specified column.
   */
  protected function sortRows(array $rows, $sort, $direction) {
    $column_map = [
      'name' => 0,
      'sex' => 1,
      'species_id' => 2,
      'number' => 3,
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

    usort($rows, function ($a, $b) use ($column, $direction) {
      $a_value = $this->getSortableValue($a['data'][$column]);
      $b_value = $this->getSortableValue($b['data'][$column]);

      // If it's a date column (rescue_date = 4, release_date = 5)
      // and neither is 'N/A', compare them as timestamps.
      if (($column === 4 || $column === 5) && $a_value !== 'N/A' && $b_value !== 'N/A') {
        $a_value = strtotime($a_value);
        $b_value = strtotime($b_value);
        return ($direction === 'asc')
          ? ($a_value - $b_value)
          : ($b_value - $a_value);
      }

      // Otherwise, compare as strings.
      return ($direction === 'asc')
        ? strcasecmp((string) $a_value, (string) $b_value)
        : strcasecmp((string) $b_value, (string) $a_value);
    });

    return $rows;
  }

  /**
   * Gets the most recent rescue event for a species.
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
   */
  protected function getRescueCauseDetail($rescue_node) {
    if (!$rescue_node->field_primary_cause->isEmpty()) {
      $cause_term = $rescue_node->field_primary_cause->entity;
      if ($cause_term && !$cause_term->field_rescue_cause_detail->isEmpty()) {
        return $cause_term->field_rescue_cause_detail->value;
      }
    }
    return 'N/A';
  }

  /**
   * Gets formatted metrics string.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface|null $weight
   *   The weight field.
   * @param \Drupal\Core\Field\FieldItemListInterface|null $length
   *   The length field.
   *
   * @return string
   *   Formatted metrics string.
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
    
    // Add filter form
    $form_object = \Drupal::formBuilder()->getForm('Drupal\tracking_reports\Form\ReleaseFilterForm');
    $build['filters'] = $form_object;
    
    // Get and apply filters
    $filters = $request->query->all();
    
    // Force default sort params if not specified
    if (!$request->query->has('sort')) {
      $request->query->set('sort', 'name');
      $request->query->set('direction', 'asc');
    }
    
    $sort = $request->query->get('sort', 'release_date');
    $direction = $request->query->get('direction', 'desc');
    
    // Build base query with filters
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'species_release')
      ->condition('field_species_ref', NULL, 'IS NOT NULL')
      ->accessCheck(FALSE);

    if (!empty($filters['search'])) {
      $search_term = $filters['search'];
      $or_group = $query->orConditionGroup();
      
      // Get releases for matching species
      $species_ids = $this->getMatchingSpeciesIds($search_term);
      if (!empty($species_ids)) {
        $or_group->condition('field_species_ref', $species_ids, 'IN');
      }
      
      // Get releases with matching counties
      $county_ids = $this->getMatchingCountyIds($search_term); 
      if (!empty($county_ids)) {
        $or_group->condition('field_county', $county_ids, 'IN');
      }
      
      // Get releases with matching causes
      $cause_ids = $this->getMatchingCauseIds($search_term);
      if (!empty($cause_ids)) {
        $or_group->condition('field_primary_cause', $cause_ids, 'IN');
      }
      
      // Add the OR conditions group if we have any matches
      if (!$or_group->count()) {
        // Only restrict results if no valid search criteria
        $query->condition('nid', 0);
      }
      else {
        $query->condition($or_group);
      }
    }

    // Add release date range filters
    if (!empty($filters['release_date_from'])) {
      $query->condition('field_release_date', $filters['release_date_from'], '>=');
    }
    if (!empty($filters['release_date_to'])) {
      $query->condition('field_release_date', $filters['release_date_to'], '<=');
    }

    $query->pager($this->itemsPerPage);
    $release_ids = $query->execute();

    if (empty($release_ids)) {
      $build['table'] = [
        '#markup' => $this->t('No release records found.'),
      ];
      return $build;
    }

    $release_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($release_ids);
    $rows = [];

    foreach ($release_nodes as $release) {
      $species_entity = $release->field_species_ref->entity;
      if (!$species_entity) {
        continue;
      }

      $rescue = $this->getMostRecentRescue($species_entity->id());
      if (!$rescue) {
        continue;
      }

      $name = $this->trackingSearchManager->getPrimaryName($species_entity->id());
      $sex = !$species_entity->field_sex->isEmpty() ? $species_entity->field_sex->entity->label() : 'N/A';
      $species_id = $this->trackingSearchManager->getPrimarySpeciesId($species_entity->id());
      $number = !$species_entity->field_number->isEmpty() ? $species_entity->field_number->value : 'N/A';
      $number_link = Link::createFromRoute($number, 'entity.node.canonical', ['node' => $species_entity->id()]);
      $rescue_date = !$rescue->field_rescue_date->isEmpty() ? $this->dateFormatter->format(strtotime($rescue->field_rescue_date->value), 'custom', 'm/d/Y') : 'N/A';
      $release_date = !$release->field_release_date->isEmpty() ? $this->dateFormatter->format(strtotime($release->field_release_date->value), 'custom', 'm/d/Y') : 'N/A';
      $rescue_cause = $this->getRescueCauseDetail($rescue);
      $rescue_metrics = ($rescue->hasField('field_weight') && $rescue->hasField('field_length')) ? $this->getMetricsString($rescue->field_weight, $rescue->field_length) : 'N/A';
      $release_metrics = $this->getPreReleaseMetrics($species_entity->id());
      $rescue_county = !$rescue->field_county->isEmpty() ? $rescue->field_county->entity->label() : 'N/A';
      $release_county = !$release->field_county->isEmpty() ? $release->field_county->entity->label() : 'N/A';

      $rows[] = [
        'data' => [
          ['data' => $name],
          ['data' => $sex],
          ['data' => $species_id ?? 'N/A'],
          ['data' => $number_link],
          ['data' => $rescue_date],
          ['data' => $release_date],
          ['data' => $rescue_cause],
          ['data' => $rescue_metrics],
          ['data' => $release_metrics],
          ['data' => $rescue_county],
          ['data' => $release_county],
        ],
      ];
    }

    $rows = $this->sortRows($rows, $sort, $direction);

    $headers = [
      ['data' => $this->t('Name'), 'field' => 'name'],
      ['data' => $this->t('Sex'), 'field' => 'sex'],
      ['data' => $this->t('Species ID'), 'field' => 'species_id'],
      ['data' => $this->t('Number'), 'field' => 'number'],
      ['data' => $this->t('Rescue Date'), 'field' => 'rescue_date'],
      ['data' => $this->t('Release Date'), 'field' => 'release_date', 'sort' => 'asc', 'sorted' => TRUE],
      ['data' => $this->t('Cause of Rescue'), 'field' => 'rescue_cause'],
      ['data' => $this->t('Rescue Weight, Length')],
      ['data' => $this->t('Release Weight, Length')],
      ['data' => $this->t('Rescue County'), 'field' => 'rescue_county'],
      ['data' => $this->t('Release County'), 'field' => 'release_county'],
    ];

    $build['table'] = [
      '#type' => 'table',
      '#header' => $headers,
      '#rows' => $rows,
      '#empty' => $this->t('No release records found.'),
      '#attributes' => ['class' => ['tracking-release-report']],
      '#attached' => ['library' => ['core/drupal.tablesort']],
      '#cache' => [
        'max-age' => 0,
        'contexts' => ['url.query_args', 'user.permissions'],
        'tags' => ['node_list:species_release'],
      ],
    ];

    $build['pager'] = [
      '#type' => 'pager',
    ];

    foreach ($build['table']['#header'] as &$header) {
      if (!empty($header['field']) && $header['field'] === $sort) {
        $header['sorted'] = TRUE;
        $header['sort'] = ($direction === 'asc') ? 'desc' : 'asc';
      }
      else {
        $header['sort'] = 'asc';
      }
    }

    return $build;
  }

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
   * Helper method: getMatchingSpeciesIds().
   *
   * Returns an array of node IDs (species) that match the search term.
   */
  protected function getMatchingSpeciesIds($search_term) {
    // 1) Gather any species_id nodes matching the user’s text input:
    $species_id_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'species_id')
      ->condition('field_species_id', $search_term, 'CONTAINS')
      ->accessCheck(FALSE);
    $matching_species_ids = $species_id_query->execute();

    // Convert these species_id nodes to an array of IDs so we can use them later.
    // (If you really do store a reference to these nodes somewhere else.)
    $species_node_ids = [];
    if (!empty($matching_species_ids)) {
      $species_id_nodes = $this->entityTypeManager->getStorage('node')
        ->loadMultiple($matching_species_ids);
      $species_node_ids = array_map(function($node) {
        return $node->id();
      }, $species_id_nodes);
    }

    // 2) Build the base species node query.
    $species_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'species')
      ->condition('status', 1)
      ->accessCheck(FALSE);

    // 3) Build an OR group matching `field_number` or `field_name`.
    $or_group = $species_query->orConditionGroup()
      ->condition('field_number', $search_term, 'CONTAINS')
      ->condition('field_name', $search_term, 'CONTAINS');

    // 4) If you have references to the `species_id` nodes, add them too:
    if (!empty($species_node_ids)) {
      // This assumes your species node has something like field_species_ref
      // referencing the species_id nodes. Adjust to your field name.
      $or_group->condition('field_species_ref', $species_node_ids, 'IN');
    }

    // 5) NEW: Query paragraphs that store a species name in `field_name`.
    //    Make sure to set the correct paragraph type (e.g. `my_species_paragraph`).
    $paragraph_ids = $this->entityTypeManager->getStorage('paragraph')->getQuery()
      ->condition('type', 'species_name')
      ->condition('field_name', $search_term, 'CONTAINS')
      ->accessCheck(FALSE)
      ->execute();

    // 6) If there are any matching paragraphs, find which species nodes reference them.
    if (!empty($paragraph_ids)) {
      // Query species nodes that reference these paragraphs.
      $species_nids_from_paragraphs = $this->entityTypeManager->getStorage('node')
        ->getQuery()
        ->condition('type', 'species')
        ->condition('field_names', $paragraph_ids, 'IN')
        ->accessCheck(FALSE)
        ->execute();

      // 7) Add those species node IDs to the OR group if found:
      if (!empty($species_nids_from_paragraphs)) {
        $or_group->condition('nid', $species_nids_from_paragraphs, 'IN');
      }
    }

    // 8) Attach the OR group to the main species query:
    $species_query->condition($or_group);

    // 9) Execute and return the final array of matching species node IDs.
    return $species_query->execute();
  }

  
  protected function getMatchingCountyIds($search_term) {
    return $this->entityTypeManager->getStorage('taxonomy_term')->getQuery()
      ->condition('vid', 'counties')
      ->condition('name', $search_term, 'CONTAINS')
      ->accessCheck(FALSE)
      ->execute();
  }
  
  protected function getMatchingCauseIds($search_term) {
    return $this->entityTypeManager->getStorage('taxonomy_term')->getQuery()
      ->condition('vid', 'rescue_causes')
      ->condition('field_rescue_cause_detail', $search_term, 'CONTAINS')
      ->accessCheck(FALSE)
      ->execute();
  }

}
