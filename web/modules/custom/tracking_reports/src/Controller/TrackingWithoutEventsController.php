<?php

namespace Drupal\tracking_reports\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Database\Connection;

/**
 * Controller for generating reports of species without birth or rescue events.
 */
class TrackingWithoutEventsController extends ControllerBase {

  /**
   * The entity type manager service.
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
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructs a TrackingWithoutEventsController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter service.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    DateFormatterInterface $date_formatter,
    Connection $database
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->dateFormatter = $date_formatter;
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
      $container->get('database')
    );
  }

  /**
   * Retrieves all "species ID" values for the species node.
   *
   * @param int $species_id
   *   The node ID of the species entity.
   *
   * @return string
   *   Comma-separated list of species ID values.
   */
  private function getSpeciesIds($species_id) {
    $ids = [];
    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->condition('type', 'species_id')
      ->condition('field_species_ref', $species_id)
      ->accessCheck(FALSE);

    $id_nodes = $storage->loadMultiple($query->execute());

    foreach ($id_nodes as $node) {
      $ids[] = $node->field_species_id->value;
    }

    return implode(', ', $ids);
  }

  /**
   * Retrieves the primary name of a species.
   *
   * @param int $species_id
   *   The node ID of the species entity.
   *
   * @return string
   *   The primary name of the species, or an empty string if none exists.
   */
  private function getPrimaryName($species_id) {
    $species_node = $this->entityTypeManager->getStorage('node')->load($species_id);
    if (!$species_node || !$species_node->hasField('field_names')) {
      return '';
    }

    // Iterate through the paragraph references.
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
   * Retrieves the sex of a species.
   *
   * @param \Drupal\node\NodeInterface $species_node
   *   The species node entity.
   *
   * @return string
   *   The sex of the species ('M', 'F', or 'U' for unknown).
   */
  private function getSpeciesSex($species_node) {
    if ($species_node->hasField('field_sex') && !$species_node->field_sex->isEmpty()) {
      $term = $species_node->field_sex->entity;
      if ($term) {
        return $term->getName();
      }
    }
    return 'U';
  }

  /**
   * Builds the content for the species without events report.
   *
   * @return array
   *   A render array for a table of species without events.
   */
  public function content() {
    // Define table headers.
    $header = [
      'tracking_number' => [
        'data' => $this->t('Tracking Number'),
        'field' => 'fn.field_number_value',
        'sort' => 'asc',
      ],
      'primary_name' => [
        'data' => $this->t('Primary Name'),
      ],
      'species_ids' => [
        'data' => $this->t('Species') . ' ' . $this->t('ID List'),
      ],
      'sex' => [
        'data' => $this->t('Sex'),
        'field' => 'fs.field_sex_target_id',
      ],
      'created_by' => [
        'data' => $this->t('Created By'),
        'field' => 'n.uid',
      ],
      'created_date' => [
        'data' => $this->t('Created Date'),
        'field' => 'n.created',
        'sort' => 'desc',
      ],
      'updated_by' => [
        'data' => $this->t('Updated By'),
        'field' => 'nr.revision_uid',
      ],
      'updated_date' => [
        'data' => $this->t('Updated Date'),
        'field' => 'n.changed',
        'sort' => 'desc',
      ],
    ];

    // Build the query.
    $query = $this->database->select('node_field_data', 'n')
      ->extend('Drupal\Core\Database\Query\TableSortExtender');

    // Add fields from node_field_data
    $query->fields('n', ['nid', 'uid', 'created', 'changed']);

    // Join with node_revision to get revision_uid
    $query->leftJoin('node_revision', 'nr', 'n.nid = nr.nid AND n.vid = nr.vid');
    $query->fields('nr', ['revision_uid']);

    // Join with field tables
    $query->leftJoin('node__field_number', 'fn', 'n.nid = fn.entity_id');
    $query->leftJoin('node__field_sex', 'fs', 'n.nid = fs.entity_id');
    $query->fields('fn', ['field_number_value']);
    $query->fields('fs', ['field_sex_target_id']);

    // Add conditions
    $query->condition('n.type', 'species');

    // Apply the table sorting
    $query->orderByHeader($header);

    // Execute query
    $result = $query->execute();

    // Build rows
    $rows = [];
    foreach ($result as $record) {
      // Skip if the species already has birth or rescue events.
      $birth_query = $this->entityTypeManager->getStorage('node')->getQuery()
        ->condition('type', 'species_birth')
        ->condition('field_species_ref', $record->nid)
        ->accessCheck(FALSE);

      $rescue_query = $this->entityTypeManager->getStorage('node')->getQuery()
        ->condition('type', 'species_rescue')
        ->condition('field_species_ref', $record->nid)
        ->accessCheck(FALSE);

      if (!empty($birth_query->execute()) || !empty($rescue_query->execute())) {
        // Skip species if either query returns something
        continue;
      }

      // Load relevant entities
      $species_entity = $this->entityTypeManager->getStorage('node')->load($record->nid);
      $created_user = $this->entityTypeManager->getStorage('user')->load($record->uid);
      $updated_user = $this->entityTypeManager->getStorage('user')->load($record->revision_uid);

      // Create a link to the species node using the field_number_value as the link text
      $number = $record->field_number_value ?? '';
      $number_link = Link::createFromRoute(
        $number,
        'entity.node.canonical',
        ['node' => $record->nid]
      );

      // Add the row data
      $rows[] = [
        'data' => [
          ['data' => $number_link],
          ['data' => $this->getPrimaryName($record->nid)],
          ['data' => $this->getSpeciesIds($record->nid)],
          ['data' => $this->getSpeciesSex($species_entity)],
          ['data' => $created_user ? $created_user->getAccountName() : ''],
          ['data' => $this->dateFormatter->format($record->created, 'custom', 'm/d/Y')],
          ['data' => $updated_user ? $updated_user->getAccountName() : ''],
          ['data' => $this->dateFormatter->format($record->changed, 'custom', 'm/d/Y')],
        ],
      ];
    }

    // Return the render array
    return [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No results found without birth or rescue records.'),
      '#attributes' => ['class' => ['species-without-events-report']],
    ];
  }

}
