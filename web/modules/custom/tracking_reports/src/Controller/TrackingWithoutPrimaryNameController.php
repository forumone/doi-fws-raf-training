<?php

namespace Drupal\tracking_reports\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for generating reports of species without a primary name.
 *
 * This controller identifies species by looking at nodes
 * and finding cases where there are no primary names set for a given species.
 */
class TrackingWithoutPrimaryNameController extends ControllerBase {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a TrackingWithoutPrimaryNameController object.
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
   * Gets all non-primary names for a species.
   *
   * @param \Drupal\node\NodeInterface $species_node
   *   The species node entity.
   *
   * @return string
   *   Comma-separated list of non-primary names.
   */
  private function getNonPrimaryNames($species_node) {
    $names = [];

    if ($species_node->hasField('field_names')) {
      foreach ($species_node->field_names->referencedEntities() as $paragraph) {
        if ($paragraph->hasField('field_name') && !$paragraph->field_name->isEmpty()) {
          // Only include names that are not marked as primary.
          if (!$paragraph->hasField('field_primary')
              || $paragraph->field_primary->isEmpty()
              || !$paragraph->field_primary->value) {
            $names[] = $paragraph->field_name->value;
          }
        }
      }
    }

    return implode(', ', $names);
  }

  /**
   * Gets all species IDs for a species.
   *
   * @param int $species_id
   *   The node ID of the species entity.
   *
   * @return string
   *   Comma-separated list of all species IDs.
   */
  private function getAllSpeciesIds($species_id) {
    $ids = [];
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'species_id')
      ->condition('field_species_ref', $species_id)
      ->accessCheck(FALSE);

    $id_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($query->execute());
    foreach ($id_nodes as $node) {
      if (!$node->field_species_ref->isEmpty()) {
        $ids[] = $node->field_species_ref->value;
      }
    }
    return implode(', ', $ids);
  }

  /**
   * Checks if a species has any names.
   *
   * @param \Drupal\node\NodeInterface $species_node
   *   The species node entity.
   *
   * @return bool
   *   TRUE if the species has any names, FALSE otherwise.
   */
  private function hasAnyNames($species_node) {
    return $species_node->hasField('field_names')
           && !$species_node->field_names->isEmpty();
  }

  /**
   * Checks if a species has a primary name.
   *
   * @param \Drupal\node\NodeInterface $species_node
   *   The species node entity.
   *
   * @return bool
   *   TRUE if the species has a primary name, FALSE otherwise.
   */
  private function hasPrimaryName($species_node) {
    if (!$species_node->hasField('field_names')) {
      return FALSE;
    }

    foreach ($species_node->field_names->referencedEntities() as $paragraph) {
      if ($paragraph->hasField('field_primary')
          && !$paragraph->field_primary->isEmpty()
          && $paragraph->field_primary->value == 1) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Builds the content for the species without primary name report.
   *
   * @return array
   *   A render array for a table of species without primary names.
   */
  public function content() {
    // First get all species nodes.
    $species_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'species')
      ->accessCheck(FALSE);

    $species_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($species_query->execute());

    $rows = [];
    foreach ($species_nodes as $species_entity) {
      // First check if this species has any names at all.
      $has_any_names = $this->hasAnyNames($species_entity);

      if ($has_any_names) {
        // Then check if any of those names are primary.
        $has_primary = $this->hasPrimaryName($species_entity);

        // If no primary names found but has other names, add to our results.
        if (!$has_primary) {
          $number = !$species_entity->field_number->isEmpty() ? $species_entity->field_number->value : '';
          $number_link = Link::createFromRoute(
            $number,
            'entity.node.canonical',
            ['node' => $species_entity->id()]
          );

          $row = [
            'data' => [
              ['data' => $number_link],
              ['data' => $this->getNonPrimaryNames($species_entity)],
              ['data' => $this->getAllSpeciesIds($species_entity->id())],
            ],
          ];

          $rows[] = $row;
        }
      }
    }

    return [
      '#type' => 'table',
      '#header' => [
        $this->t('Tracking Number'),
        $this->t('Species') . ' ' . $this->t('Name List (Not Primary)'),
        $this->t('Species') . ' ' . $this->t('ID List (All)'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No results found without a primary name.'),
      '#attributes' => ['class' => ['tracking-without-primary-name-report']],
    ];
  }

}
