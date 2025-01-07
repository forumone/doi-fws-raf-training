<?php

namespace Drupal\tracking_reports\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Query\TableSortExtender;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for tracking reports without primary ID.
 */
class TrackingWithoutPrimaryIdController extends ControllerBase {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The number of items to display per page.
   *
   * @var int
   */
  protected $itemsPerPage = 25;

  /**
   * Constructor.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  /**
   * Returns a comma-separated string of non-primary species IDs.
   *
   * @param int $species_id
   *   The Node ID of the species to get non-primary IDs for.
   *
   * @return string
   *   A comma-separated list of IDs or an empty string if none found.
   */
  private function getNonPrimarySpeciesIds($species_id) {
    $ids = [];
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'species_id')
      ->condition('field_species_ref', $species_id)
      // Only get non-primary IDs.
      ->condition('field_primary_id', 1, '<>')
      ->accessCheck(FALSE)
      ->execute();

    if (!empty($query)) {
      $id_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($query);
      foreach ($id_nodes as $node) {
        if (!$node->field_species_id->isEmpty()) {
          $ids[] = $node->field_species_id->value;
        }
      }
    }

    return implode(', ', $ids);
  }

  /**
   * Renders the report.
   */
  public function content() {
    // Table headers.
    $header = [
      'field_number_value' => [
        'data' => $this->t('Tracking Number'),
        'field' => 'field_number_value',
        'sort' => 'asc',
      ],
      'primary_name' => [
        'data' => $this->t('Primary Name'),
        'field' => 'primary_name_value',
        'sort' => 'asc',
      ],
      'non_primary_ids' => [
        'data' => $this->t('Species IDs (Not Primary List)'),
      ],
    ];

    // Main DB query for species.
    $database = \Drupal::database();
    $query = $database->select('node_field_data', 'n');
    $query = $query->extend(TableSortExtender::class);
    $query = $query->extend('Drupal\Core\Database\Query\PagerSelectExtender');

    // Join to retrieve field_number (Tracking Number).
    $query->join('node__field_number', 'nf', 'nf.entity_id = n.nid');

    // Join to retrieve paragraphs referencing names.
    $query->leftJoin('node__field_names', 'names', 'names.entity_id = n.nid');
    $query->leftJoin('paragraphs_item_field_data', 'p', 'p.id = names.field_names_target_id');
    // Join to get "primary" flag and name value from the paragraph.
    $query->leftJoin('paragraph__field_primary', 'fp', 'fp.entity_id = p.id');
    $query->leftJoin('paragraph__field_name', 'fn', 'fn.entity_id = p.id');

    // Select needed fields.
    $query->fields('n', ['nid']);
    $query->addField('nf', 'field_number_value', 'field_number_value');
    $query->addField('fn', 'field_name_value', 'primary_name_value');

    // We only care about species nodes.
    $query->condition('n.type', 'species');

    // Instead of excluding rows without primary name (fp.field_primary_value = 1),
    // we create an OR condition so that rows with NULL "primary" also show up.
    $or = $query->orConditionGroup()
      ->condition('fp.field_primary_value', '1')
      ->isNull('fp.field_primary_value');
    $query->condition($or);

    // ---- Exclude species that DO have a primary ID ----
    // If a species_id node references this species via field_species_ref
    // AND has field_primary_id_value = 1, exclude it.
    $primary_id_subquery = $database->select('node_field_data', 'pid_n')
      ->fields('pid_n', ['nid']);
    $primary_id_subquery->join(
      'node__field_species_ref',
      'pid_ref',
      'pid_n.nid = pid_ref.entity_id'
    );
    $primary_id_subquery->join(
      'node__field_primary_id',
      'pid_primary',
      'pid_n.nid = pid_primary.entity_id'
    );
    $primary_id_subquery->condition('pid_n.type', 'species_id');
    $primary_id_subquery->condition('pid_primary.field_primary_id_value', 1);
    // Link back to main species node.
    $primary_id_subquery->where('pid_ref.field_species_ref_target_id = n.nid');

    // Exclude species that DO have a species_id (type=species_id) with primary flag.
    $query->notExists($primary_id_subquery);

    // TableSort + Pager.
    $query->orderByHeader($header);
    $query->limit($this->itemsPerPage);
    // Use DISTINCT if you see duplicates from the joins.
    $query->distinct();

    $results = $query->execute()->fetchAll();

    // Build table rows.
    $rows = [];
    foreach ($results as $result) {
      /** @var \Drupal\node\Entity\Node $node */
      $node = $this->entityTypeManager->getStorage('node')->load($result->nid);
      $tracking_number = $node->get('field_number')->value ?? '';

      // Create a link to the species node.
      $tracking_link = Link::createFromRoute(
        $tracking_number,
        'entity.node.canonical',
        ['node' => $node->id()]
      );

      $rows[] = [
        'field_number_value' => [
          'data' => $tracking_link,
        ],
        'primary_name' => $result->primary_name_value ?? '',
        'non_primary_ids' => $this->getNonPrimarySpeciesIds($node->id()),
      ];
    }

    return [
      'table' => [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => $this->t('No results found without a primary ID.'),
        '#attributes' => ['class' => ['tracking-without-primary-id-report']],
        '#prefix' => '<div class="tracking-without-primary-id-wrapper">',
        '#suffix' => '</div>',
        '#tablesort' => TRUE,
      ],
      'pager' => [
        '#type' => 'pager',
      ],
    ];
  }

}
