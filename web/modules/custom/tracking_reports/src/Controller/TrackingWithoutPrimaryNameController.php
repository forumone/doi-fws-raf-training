<?php

namespace Drupal\tracking_reports\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\node\NodeInterface;
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
   * Constructs a new controller instance.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('entity_type.manager'));
  }

  /**
   * Page callback: Lists species without a primary name, manually sortable by "Tracking Number".
   */
  public function content() {
    // Define the header for the table.
    $header = [
      'tracking_number' => [
        'data' => $this->t('Tracking Number'),
        'field' => 'tracking_number',
        'sort' => 'asc',
      ],
      'species_names' => [
        'data' => $this->t('Species Name List (Not Primary)'),
      ],
      'species_ids' => [
        'data' => $this->t('Species ID List (All)'),
      ],
    ];

    // Get current sort from URL parameters.
    $sort = \Drupal::request()->query->get('sort', 'asc');

    // Build the base query.
    $query = $this->entityTypeManager
      ->getStorage('node')
      ->getQuery()
      ->condition('type', 'species')
      ->accessCheck(FALSE);

    // Always add the sort for field_number.
    $query->sort('field_number.value', strtoupper($sort));

    // Execute the query and load nodes.
    $nids = $query->execute();
    $species_nodes = $this->entityTypeManager
      ->getStorage('node')
      ->loadMultiple($nids);

    // Build table rows.
    $rows = [];
    foreach ($species_nodes as $species) {
      if ($this->hasAnyNames($species) && !$this->hasPrimaryName($species)) {
        $tracking_value = !$species->field_number->isEmpty() ? $species->field_number->value : '';
        $link = Link::createFromRoute($tracking_value, 'entity.node.canonical', [
          'node' => $species->id(),
        ]);

        $rows[] = [
          'data' => [
            'tracking_number' => [
              'data' => $link,
            ],
            'species_names' => [
              'data' => ['#markup' => $this->getNonPrimaryNames($species)],
            ],
            'species_ids' => [
              'data' => ['#markup' => $this->getAllSpeciesIds($species->id())],
            ],
          ],
        ];
      }
    }

    // Build the render array with cache metadata.
    $build = [
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
    ];

    return $build;
  }

  /**
   * Check if a node has any "names".
   */
  private function hasAnyNames(NodeInterface $species_node) {
    return $species_node->hasField('field_names')
      && !$species_node->get('field_names')->isEmpty();
  }

  /**
   * Check if a node has any "primary" name.
   */
  private function hasPrimaryName(NodeInterface $species_node) {
    if (!$species_node->hasField('field_names')) {
      return FALSE;
    }
    foreach ($species_node->get('field_names')->referencedEntities() as $paragraph) {
      if (
        $paragraph->hasField('field_primary') &&
        !$paragraph->get('field_primary')->isEmpty() &&
        $paragraph->get('field_primary')->value
      ) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * List all non-primary names as a comma-separated string.
   */
  private function getNonPrimaryNames(NodeInterface $species_node) {
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
        if (!$node->get('field_species_ref')->isEmpty()) {
          $ids[] = $node->get('field_species_ref')->value;
        }
      }
    }
    return implode(', ', $ids);
  }

}