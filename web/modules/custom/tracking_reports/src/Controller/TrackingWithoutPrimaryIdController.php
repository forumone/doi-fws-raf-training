<?php

namespace Drupal\tracking_reports\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Query\TableSortExtender;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\node\Entity\Node;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for generating a report of species without a primary ID.
 *
 * Uses a custom SQL query (with TableSortExtender) to allow table sorting.
 */
class TrackingWithoutPrimaryIdController extends ControllerBase {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a TrackingWithoutPrimaryIdController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
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
   * Checks if a species node has at least one primary ID (via a separate query).
   *
   * @param \Drupal\node\Entity\Node $species_node
   *   The species node to check.
   *
   * @return bool
   *   TRUE if the species has at least one primary ID, FALSE otherwise.
   */
  private function hasPrimaryId(Node $species_node) {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'species_id')
      ->condition('field_species_ref', $species_node->id())
      ->condition('field_primary_id', 1)
      ->accessCheck(FALSE);

    $result = $query->execute();
    return !empty($result);
  }

  /**
   * Gets the "primary name" for a species (from your snippet).
   *
   * @param int $species_id
   *   The node ID of the species entity.
   *
   * @return string
   *   The primary name or empty string if none is found.
   */
  private function getPrimaryName($species_id) {
    $species_node = $this->entityTypeManager->getStorage('node')->load($species_id);
    if (!$species_node || !$species_node->hasField('field_names')) {
      return '';
    }

    // Example logic: if a Paragraph reference has 'field_primary' == 1,
    // use 'field_name'.
    foreach ($species_node->field_names->referencedEntities() as $paragraph) {
      if ($paragraph->hasField('field_primary')
          && !$paragraph->field_primary->isEmpty()
          && $paragraph->field_primary->value == 1
          && !$paragraph->field_name->isEmpty()) {
        return $paragraph->field_name->value;
      }
    }
    return '';
  }

  /**
   * Gets a list of non-primary IDs (from your snippet).
   *
   * @param int $species_id
   *   The node ID of the species entity.
   *
   * @return string
   *   Comma-separated list of non-primary species IDs.
   */
  private function getNonPrimaryAnimalIds($species_id) {
    $ids = [];
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'species_id')
      ->condition('field_species_ref', $species_id)
      ->accessCheck(FALSE);

    $id_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($query->execute());
    foreach ($id_nodes as $node) {
      // Only include IDs that are NOT marked as primary.
      if (!$node->field_species_ref->isEmpty()
        && (
          !$node->hasField('field_primary_id')
          || $node->field_primary_id->isEmpty()
          || !$node->field_primary_id->value
        )
      ) {
        $ids[] = $node->field_species_ref->value;
      }
    }
    return implode(', ', $ids);
  }

  /**
   * Builds the species-without-primary-ID report.
   *
   * @return array
   *   A render array for a table, sorted by the numeric "Tracking Number".
   */
  public function content() {
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
        'data' => $this->t('Species') . ' ' . $this->t('IDs (Not Primary List)'),
      ],
    ];

    $database = \Drupal::database();
    $query = $database->select('node_field_data', 'n');
    $query = $query->extend(TableSortExtender::class);

    // Join with field_number table
    $query->join('node__field_number', 'nf', 'nf.entity_id = n.nid');
    
    // Join with paragraphs field tables - using LEFT JOINs to preserve rows without primary names
    $query->leftJoin('node__field_names', 'names', 'names.entity_id = n.nid');
    $query->leftJoin(
      'paragraphs_item_field_data', 
      'p', 
      'p.id = names.field_names_target_id'
    );
    // Include the primary condition in the JOIN
    $query->leftJoin(
      'paragraph__field_primary', 
      'fp', 
      "fp.entity_id = p.id AND fp.field_primary_value = '1'"
    );
    $query->leftJoin(
      'paragraph__field_name', 
      'fn', 
      'fn.entity_id = p.id'
    );
    
    $query->fields('n', ['nid']);
    $query->addField('nf', 'field_number_value', 'field_number_value');
    $query->addField('fn', 'field_name_value', 'primary_name_value');

    // Filter for species type
    $query->condition('n.type', 'species', '=');

    $query->orderByHeader($header);

    // Execute and get results with both nid and primary name
    $results = $query->execute()->fetchAll();

    $rows = [];
    foreach ($results as $result) {
      $node = $this->entityTypeManager->getStorage('node')->load($result->nid);
      
      // Skip nodes that DO have a primary ID
      if ($this->hasPrimaryID($node)) {
        continue;
      }

      $tracking_number = $node->get('field_number')->value ?? '';
      $tracking_link = Link::createFromRoute($tracking_number, 'entity.node.canonical', ['node' => $node->id()]);

      $rows[] = [
        'field_number_value' => [
          'data' => $tracking_link,
        ],
        'primary_name' => $result->primary_name_value ?? '',
        'non_primary_ids' => $this->getNonPrimaryAnimalIds($node->id()),
      ];
    }

    return [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No results found without a primary ID.'),
      '#attributes' => ['class' => ['tracking-without-primary-id-report']],
      '#tablesort' => TRUE,
    ];
  }

}
