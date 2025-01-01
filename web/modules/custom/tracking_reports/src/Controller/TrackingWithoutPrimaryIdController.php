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
    // 1) Define a header array. We'll only sort by 'field_number_value'.
    // That must match the alias used in our custom SQL query below.
    $header = [
      'field_number_value' => [
        'data' => $this->t('Tracking Number'),
        'field' => 'field_number_value', // Must match the query alias
        'sort' => 'asc',
      ],
      'primary_name' => [
        'data' => $this->t('Primary Name'),
      ],
      'non_primary_ids' => [
        'data' => $this->t('Species') . ' ' . $this->t('IDs (Not Primary List)'),
      ],
    ];

    // 2) Build a custom DB query so we can do table sorting on 'field_number_value'.
    $database = \Drupal::database();
    $query = $database->select('node_field_data', 'n');
    // Extend it so we can use orderByHeader() from TableSortExtender.
    $query = $query->extend(TableSortExtender::class);

    // Join the table for your numeric field "field_number".
    // Make sure to update 'node__field_number' & 'field_number_value'
    // to your actual field machine name.
    $query->join('node__field_number', 'nf', 'nf.entity_id = n.nid');

    // Grab the node ID and the numeric field "field_number_value" from the
    // joined table.
    $query->fields('n', ['nid']);
    // Alias must match what we used in $header['field_number_value']['field'].
    $query->addField('nf', 'field_number_value', 'field_number_value');

    // Filter: only load 'species' type.
    $query->condition('n.type', 'species', '=');

    // Let TableSortExtender handle ordering from $header (by
    // 'field_number_value').
    $query->orderByHeader($header);

    // 3) Execute and get the node IDs in sorted order.
    $nids = $query->execute()->fetchCol();

    // 4) Load the species nodes by these IDs.
    $species_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);

    // 5) Build the table rows.
    $rows = [];
    foreach ($species_nodes as $node) {
      // Skip nodes that DO have a primary ID. We only want "no primary ID"
      // results.
      if ($this->hasPrimaryID($node)) {
        continue;
      }

      // Use "field_number" to get the numeric tracking number.
      // Adjust if your field is different.
      $tracking_number = $node->get('field_number')->value ?? '';
      $tracking_link = Link::createFromRoute($tracking_number, 'entity.node.canonical', ['node' => $node->id()]);

      // Build one row. The key names should match the $header.
      $rows[] = [
        'field_number_value' => [
          'data' => $tracking_link,
        ],
        'primary_name' => $this->getPrimaryName($node->id()),
        'non_primary_ids' => $this->getNonPrimaryAnimalIds($node->id()),
      ];
    }

    // 6) Return the render array.
    // #tablesort => TRUE means Drupal will pass the "sort by" parameters
    // to the TableSortExtender query above.
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
