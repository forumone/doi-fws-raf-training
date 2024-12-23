<?php

namespace Drupal\tracking_reports\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for generating reports of species without a primary species ID.
 *
 * This controller identifies species by looking at species nodes
 * and finding cases where there are no primary IDs set for a given species.
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
   * Gets the primary name for a species.
   *
   * @param int $species_id
   *   The node ID of the species entity.
   *
   * @return string
   *   The primary name or empty string if none exists.
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
   * Gets all non-primary species IDs for a species.
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
      // Only include IDs that are not marked as primary.
      if (!$node->field_species_ref->isEmpty() &&
          (!$node->hasField('field_primary_id') ||
           $node->field_primary_id->isEmpty() ||
           !$node->field_primary_id->value)) {
        $ids[] = $node->field_species_ref->value;
      }
    }
    return implode(', ', $ids);
  }

  /**
   * Builds the content for the species without primary ID report.
   *
   * @return array
   *   A render array for a table of species without primary IDs.
   */
  public function content() {
    // First get all species nodes.
    $species_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'species')
      ->accessCheck(FALSE);

    $species_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($species_query->execute());

    $rows = [];
    foreach ($species_nodes as $species_entity) {
      // Check if this species has any primary IDs.
      $primary_id_query = $this->entityTypeManager->getStorage('node')->getQuery()
        ->condition('type', 'species_id')
        ->condition('field_species_ref', $species_entity->id())
        ->condition('field_primary_id', 1)
        ->accessCheck(FALSE);

      $has_primary = !empty($primary_id_query->execute());

      // If no primary IDs found, add to our results.
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
          ['data' => $this->getPrimaryName($species_entity->id())],
          ['data' => $this->getNonPrimaryAnimalIds($species_entity->id())],
          ],
        ];

        $rows[] = $row;
      }
    }

    return [
      '#type' => 'table',
      '#header' => [
        $this->t('Tracking Number'),
        $this->t('Primary Name'),
        $this->t('Species') . ' ' . $this->t('IDs (Not Primary List)'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No results found without a primary ID.'),
      '#attributes' => ['class' => ['tracking-without-primary-id-report']],
    ];
  }

}
