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
   * Number of items to show per page.
   *
   * @var int
   */
  protected $itemsPerPage = 25;

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
    // Get the current page from the query parameters
    $page = \Drupal::request()->query->get('page') ?? 0;

    // Define table headers.
    $header = [
      'tracking_number' => [
        'data' => $this->t('Tracking Number'),
        'field' => 'fn.field_number_value',
        'sort' => 'asc',
      ],
      'primary_name' => [
        'data' => $this->t('Primary Name'),
        'field' => 'pn.field_name_value',
        'sort' => 'asc',
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
      ->extend('Drupal\Core\Database\Query\TableSortExtender')
      ->extend('Drupal\Core\Database\Query\PagerSelectExtender');

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

    // Join with names paragraph tables using a subquery to get only primary names
    $primary_names = $this->database->select('node__field_names', 'names')
      ->fields('names', ['entity_id'])
      ->fields('p', ['field_name_value']);
    $primary_names->leftJoin('paragraph__field_primary', 'pri', 'names.field_names_target_id = pri.entity_id');
    $primary_names->leftJoin('paragraph__field_name', 'p', 'names.field_names_target_id = p.entity_id');
    $primary_names->condition('pri.field_primary_value', 1);
    
    // Left join with the primary names subquery
    $query->leftJoin(
      $primary_names,
      'pn',
      'n.nid = pn.entity_id'
    );
    $query->fields('pn', ['field_name_value']);

    // Add subqueries to check for birth and rescue events
    $birth_subquery = $this->database->select('node__field_species_ref', 'birth_ref')
      ->fields('birth_ref', ['field_species_ref_target_id'])
      ->condition('birth_ref.bundle', 'species_birth');

    $rescue_subquery = $this->database->select('node__field_species_ref', 'rescue_ref')
      ->fields('rescue_ref', ['field_species_ref_target_id'])
      ->condition('rescue_ref.bundle', 'species_rescue');

    // Add conditions
    $query->condition('n.type', 'species')
      ->condition('n.status', 1)
      ->notExists($birth_subquery->where('birth_ref.field_species_ref_target_id = n.nid'))
      ->notExists($rescue_subquery->where('rescue_ref.field_species_ref_target_id = n.nid'));

    // Apply the table sorting
    $query->orderByHeader($header);

    // Add the pager
    $query->limit($this->itemsPerPage);

    // Execute query
    $result = $query->execute();

    // Build rows
    $rows = [];
    foreach ($result as $record) {
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
          ['data' => $record->field_name_value ?? ''],
          ['data' => $this->getSpeciesIds($record->nid)],
          ['data' => $this->getSpeciesSex($species_entity)],
          ['data' => $created_user ? $created_user->getAccountName() : ''],
          ['data' => $this->dateFormatter->format($record->created, 'custom', 'm/d/Y')],
          ['data' => $updated_user ? $updated_user->getAccountName() : ''],
          ['data' => $this->dateFormatter->format($record->changed, 'custom', 'm/d/Y')],
        ],
      ];
    }

    // Return the render array with separate table and pager
    return [
      'table' => [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => $this->t('No results found without birth or rescue records.'),
        '#attributes' => ['class' => ['species-without-events-report']],
        '#prefix' => '<div class="species-without-events-wrapper">',
        '#suffix' => '</div>',
      ],
      'pager' => [
        '#type' => 'pager',
      ],
    ];
  }
}
