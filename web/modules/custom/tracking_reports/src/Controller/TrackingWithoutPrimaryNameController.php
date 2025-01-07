<?php

namespace Drupal\tracking_reports\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for generating reports of species without a primary name.
 */
class TrackingWithoutPrimaryNameController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Items to show per page.
   *
   * @var int
   */
  protected $itemsPerPage = 25;

  /**
   * Constructs a new controller instance.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    Connection $database
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('database')
    );
  }

  /**
   * Page callback: Lists species without a primary name, with pagination.
   */
  public function content() {
    // Define the header for the table.
    $header = [
      'tracking_number' => [
        'data' => $this->t('Tracking Number'),
        'field' => 'fn.field_number_value',
        'sort' => 'asc',
      ],
      'species_names' => [
        'data' => $this->t('Species') . $this->t(' Name List (Not Primary)'),
      ],
      'species_ids' => [
        'data' => $this->t('Species ID') . $this->t(' List (All)'),
      ],
    ];

    // Build the base query.
    $query = $this->database->select('node_field_data', 'n')
      ->extend('Drupal\Core\Database\Query\TableSortExtender')
      ->extend('Drupal\Core\Database\Query\PagerSelectExtender');

    // Add fields from node_field_data
    $query->fields('n', ['nid']);

    // Join with field_number
    $query->leftJoin('node__field_number', 'fn', 'n.nid = fn.entity_id');
    $query->fields('fn', ['field_number_value']);

    // Join with names paragraph tables
    $query->leftJoin('node__field_names', 'names', 'n.nid = names.entity_id');
    $query->leftJoin('paragraph__field_primary', 'pri', 'names.field_names_target_id = pri.entity_id');
    $query->leftJoin('paragraph__field_name', 'p', 'names.field_names_target_id = p.entity_id');

    // Create a subquery to find nodes that have at least one name but no primary names
    $primary_name_subquery = $this->database->select('node__field_names', 'pn_names')
      ->fields('pn_names', ['entity_id']);
    $primary_name_subquery->leftJoin('paragraph__field_primary', 'pn_pri', 'pn_names.field_names_target_id = pn_pri.entity_id');
    $primary_name_subquery->condition('pn_pri.field_primary_value', 1);

    // Add conditions
    $query->condition('n.type', 'species')
      ->condition('n.status', 1)
      // Has at least one name
      ->condition('names.field_names_target_id', NULL, 'IS NOT NULL')
      // Does not have a primary name
      ->notExists($primary_name_subquery->where('pn_names.entity_id = n.nid'));

    // Add group by to prevent duplicate rows
    $query->groupBy('n.nid')
      ->groupBy('fn.field_number_value');

    // Apply the table sorting
    $query->orderByHeader($header);

    // Add the pager
    $query->limit($this->itemsPerPage);

    // Execute query
    $result = $query->execute();

    // Build rows
    $rows = [];
    foreach ($result as $record) {
      $species_entity = $this->entityTypeManager->getStorage('node')->load($record->nid);
      
      // Create link for tracking number
      $number = $record->field_number_value ?? '';
      $number_link = Link::createFromRoute(
        $number,
        'entity.node.canonical',
        ['node' => $record->nid]
      );

      $rows[] = [
        'data' => [
          'tracking_number' => [
            'data' => $number_link,
          ],
          'species_names' => [
            'data' => ['#markup' => $this->getNonPrimaryNames($species_entity)],
          ],
          'species_ids' => [
            'data' => ['#markup' => $this->getAllSpeciesIds($record->nid)],
          ],
        ],
      ];
    }

    // Build the render array with cache metadata.
    return [
      'table' => [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => $this->t('No results found without a primary name.'),
        '#attributes' => ['class' => ['sortable']],
        '#attached' => [
          'library' => [
            'core/drupal.tablesort',
          ],
        ],
        // Add cache metadata to prevent caching.
        '#cache' => [
          'max-age' => 0,
          'contexts' => [
            'url.query_args',
            'user.permissions',
          ],
          'tags' => [
            'node_list:species',
            'node_list:species_id',
          ],
        ],
      ],
      'pager' => [
        '#type' => 'pager',
      ],
    ];
  }

  /**
   * List all non-primary names as a comma-separated string.
   */
  private function getNonPrimaryNames($species_node) {
    $names = [];
    if ($species_node->hasField('field_names')) {
      foreach ($species_node->get('field_names')->referencedEntities() as $paragraph) {
        $name_field = $paragraph->get('field_name');
        $primary_field = $paragraph->get('field_primary');
        if ($name_field && !$name_field->isEmpty()) {
          // Skip if it's marked primary.
          if (!$primary_field || $primary_field->isEmpty() || !$primary_field->value) {
            $names[] = $name_field->value;
          }
        }
      }
    }
    return implode(', ', $names);
  }

  /**
   * List all species IDs (from some related node type "species_id").
   */
  private function getAllSpeciesIds($species_id) {
    $ids = [];
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'species_id')
      ->condition('field_species_ref', $species_id)
      ->accessCheck(FALSE);
    $id_nids = $query->execute();
    if ($id_nids) {
      $id_nodes = $this->entityTypeManager
        ->getStorage('node')
        ->loadMultiple($id_nids);
      foreach ($id_nodes as $node) {
        if (!$node->get('field_species_id')->isEmpty()) {
          $ids[] = $node->get('field_species_id')->value;
        }
      }
    }
    return implode(', ', $ids);
  }
}