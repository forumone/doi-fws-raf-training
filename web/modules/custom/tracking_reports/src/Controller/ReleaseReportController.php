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
    TrackingSearchManager $tracking_search_manager,
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
   * Gets formatted metrics string for weight/length.
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

    // Force default sort params if not specified.
    if (!$request->query->has('sort')) {
      $request->query->set('sort', 'name');
      $request->query->set('direction', 'asc');
    }

    $sort = $request->query->get('sort', 'release_date');
    $direction = $request->query->get('direction', 'desc');

    // Build base query with filters, for species_release nodes.
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'species_release')
      ->condition('field_species_ref', NULL, 'IS NOT NULL')
      ->accessCheck(FALSE);

    // -- Apply SEARCH filter if present --
    if (!empty($filters['search'])) {
      $search_term = $filters['search'];
      $or_group = $query->orConditionGroup();

      // 1) 'species_id' nodes that match field_species_id (e.g. "B123")
      $matching_species_id_nids = $this->getMatchingSpeciesIdNodeIds($search_term);

      if (!empty($matching_species_id_nids)) {
        // Get the species nodes that these species_id nodes reference
        $species_ids = $this->entityTypeManager->getStorage('node')
          ->getQuery()
          ->condition('nid', $matching_species_id_nids, 'IN')
          ->condition('field_species_ref', NULL, 'IS NOT NULL')
          ->accessCheck(FALSE)
          ->execute();
      
        if (!empty($species_ids)) {
          $species_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($species_ids);
          $referenced_species_ids = [];
          foreach ($species_nodes as $node) {
            if (!$node->field_species_ref->isEmpty()) {
              $referenced_species_ids[] = $node->field_species_ref->target_id;
            }
          }
          if (!empty($referenced_species_ids)) {
            $or_group->condition('field_species_ref', $referenced_species_ids, 'IN');
          }
        }
      }

      // 2) 'species' nodes that match field_name or field_number.
      $matching_species_nids = $this->getMatchingSpeciesNodeIds($search_term);
      if (!empty($matching_species_nids)) {
        // The release node's field that references 'species' nodes:
        $or_group->condition('field_species_ref', $matching_species_nids, 'IN');
      }

      // 3) If you also want to match counties, etc., you could do that here.
      $county_ids = $this->getMatchingCountyIds($search_term);
      if (!empty($county_ids)) {
        $or_group->condition('field_county', $county_ids, 'IN');
      }

      $cause_ids = $this->getMatchingCauseIds($search_term);
      if (!empty($cause_ids)) {
        $or_group->condition('field_primary_cause', $cause_ids, 'IN');
      }

      // If we never added any conditions to $or_group, it has zero count.
      // Usually you'd want to restrict results if there's no match.
      if (!$or_group->count()) {
        // Return no results:
        $query->condition('nid', 0);
      }
      else {
        $query->condition($or_group);
      }
    }

    // Add release date range filters.
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

      // Most recent rescue for that "species" or "species_id" node:
      $rescue = $this->getMostRecentRescue($species_entity->id());
      if (!$rescue) {
        continue;
      }

      // Build table row data.
      $name = $this->trackingSearchManager->getPrimaryName($species_entity->id());
      $sex = !$species_entity->field_sex->isEmpty()
        ? $species_entity->field_sex->entity->label()
        : 'N/A';

      // This is the textual species ID from your search manager, if you store it that way:
      $species_id = $this->trackingSearchManager->getPrimarySpeciesId($species_entity->id());

      $number = !$species_entity->field_number->isEmpty()
        ? $species_entity->field_number->value
        : 'N/A';

      $number_link = Link::createFromRoute($number, 'entity.node.canonical', ['node' => $species_entity->id()]);

      $rescue_date = !$rescue->field_rescue_date->isEmpty()
        ? $this->dateFormatter->format(strtotime($rescue->field_rescue_date->value), 'custom', 'm/d/Y')
        : 'N/A';

      $release_date = !$release->field_release_date->isEmpty()
        ? Link::createFromRoute(
            $this->dateFormatter->format(strtotime($release->field_release_date->value), 'custom', 'm/d/Y'),
            'entity.node.canonical',
            ['node' => $release->id()]
          )
        : 'N/A';

      $rescue_cause = $this->getRescueCauseDetail($rescue);

      $rescue_metrics = ($rescue->hasField('field_weight') && $rescue->hasField('field_length'))
        ? $this->getMetricsString($rescue->field_weight, $rescue->field_length)
        : 'N/A';

      $release_metrics = $this->getPreReleaseMetrics($species_entity->id());

      $rescue_county = !$rescue->field_county->isEmpty()
        ? $rescue->field_county->entity->label()
        : 'N/A';

      $release_county = !$release->field_county->isEmpty()
        ? $release->field_county->entity->label()
        : 'N/A';

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

    // Sort the rows.
    $rows = $this->sortRows($rows, $sort, $direction);

    // Build table header.
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

    // Attach table.
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

    // Update the table header sort indicators.
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

  /**
   * Gets the pre-release metrics if available.
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
   */
  protected function getMatchingSpeciesIdNodeIds($search_term) {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'species_id')
      ->condition('field_species_id', $search_term, 'CONTAINS')
      ->accessCheck(FALSE);

    return $query->execute();
  }

  /**
   * Helper method: getMatchingSpeciesNodeIds().
   *
   * Searches the 'species' node type by field_name or field_number.
   * Returns an array of node IDs for matching 'species' nodes.
   */
  protected function getMatchingSpeciesNodeIds($search_term) {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'species')
      // If you only want published species, add ->condition('status', 1)
      ->accessCheck(FALSE);

    // OR group for field_name OR field_number.
    $or_group = $query->orConditionGroup()
      ->condition('field_name', $search_term, 'CONTAINS')
      ->condition('field_number', $search_term, 'CONTAINS');

    $query->condition($or_group);

    return $query->execute();
  }

  /**
   * Returns taxonomy term IDs for counties whose name matches $search_term.
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
   */
  protected function getMatchingCauseIds($search_term) {
    return $this->entityTypeManager->getStorage('taxonomy_term')->getQuery()
      ->condition('vid', 'rescue_causes')
      ->condition('field_rescue_cause_detail', $search_term, 'CONTAINS')
      ->accessCheck(FALSE)
      ->execute();
  }

}
