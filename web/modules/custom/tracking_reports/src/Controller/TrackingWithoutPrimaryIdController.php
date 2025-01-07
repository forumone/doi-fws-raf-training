<?php

namespace Drupal\tracking_reports\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Query\TableSortExtender;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\node\Entity\Node;
use Symfony\Component\DependencyInjection\ContainerInterface;

class TrackingWithoutPrimaryIdController extends ControllerBase {
  protected $entityTypeManager;
  protected $itemsPerPage = 25;

  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  private function getNonPrimarySpeciesIds($species_id) {
    $ids = [];
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'species_id')
      ->condition('field_species_ref', $species_id)
      ->condition('field_primary_id', 1, '<>')  // Get non-primary IDs
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

  public function content() {
    $page = \Drupal::request()->query->get('page') ?? 0;

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
    
    // Main query for species
    $query = $database->select('node_field_data', 'n');
    $query = $query->extend(TableSortExtender::class);
    $query = $query->extend('Drupal\Core\Database\Query\PagerSelectExtender');

    // Join with field_number table
    $query->join('node__field_number', 'nf', 'nf.entity_id = n.nid');
    
    // Join with field_names table
    $query->leftJoin('node__field_names', 'names', 'names.entity_id = n.nid');
    
    // Join with paragraphs table for names
    $query->leftJoin(
      'paragraphs_item_field_data',
      'p',
      'p.id = names.field_names_target_id'
    );
    
    // Join to get primary flag
    $query->leftJoin(
      'paragraph__field_primary',
      'fp',
      "fp.entity_id = p.id"
    );
    
    // Join to get the name value
    $query->leftJoin(
      'paragraph__field_name',
      'fn',
      'fn.entity_id = p.id'
    );

    // Select fields
    $query->fields('n', ['nid']);
    $query->addField('nf', 'field_number_value', 'field_number_value');
    $query->addField('fn', 'field_name_value', 'primary_name_value');
    
    // Filter conditions
    $query->condition('n.type', 'species', '=');
    // Only get primary names
    $query->condition('fp.field_primary_value', '1', '=');
    
    // Exclude species with primary IDs
    $primary_id_subquery = $database->select('node_field_data', 'pid_n')
      ->fields('pid_n', ['nid']);
    $primary_id_subquery->join(
      'node__field_species_ref',
      'pid_ref',
      'pid_n.nid = pid_ref.field_species_ref_target_id'
    );
    $primary_id_subquery->join(
      'node__field_primary_id',
      'pid_primary',
      'pid_n.nid = pid_primary.entity_id'
    );
    $primary_id_subquery->condition('pid_n.type', 'species_id')
      ->condition('pid_primary.field_primary_id_value', 1);

    $query->notExists($primary_id_subquery->where('pid_n.nid = n.nid'));

    // Add sorting and paging
    $query->orderByHeader($header);
    $query->limit($this->itemsPerPage);
    $query->distinct();  // Ensure we only get one row per species
    
    // Execute query
    $results = $query->execute()->fetchAll();

    $rows = [];
    foreach ($results as $result) {
      $node = $this->entityTypeManager->getStorage('node')->load($result->nid);
      $tracking_number = $node->get('field_number')->value ?? '';
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