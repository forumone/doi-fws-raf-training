<?php

namespace Drupal\tracking_reports\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for generating reports of species without a primary animal ID.
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
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'manatee_name')
      ->condition('field_animal', $species_id)
      ->condition('field_primary', 1)
      ->accessCheck(FALSE);

    $results = $query->execute();
    if (!empty($results)) {
      $name_node = $this->entityTypeManager->getStorage('node')->load(reset($results));
      return !$name_node->field_name->isEmpty() ? $name_node->field_name->value : '';
    }
    return '';
  }

  /**
   * Gets all non-primary animal IDs for a species.
   *
   * @param int $species_id
   *   The node ID of the species entity.
   *
   * @return string
   *   Comma-separated list of non-primary animal IDs.
   */
  private function getNonPrimaryAnimalIds($species_id) {
    $ids = [];
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'manatee_animal_id')
      ->condition('field_animal', $species_id)
      ->accessCheck(FALSE);

    $id_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($query->execute());
    foreach ($id_nodes as $node) {
      // Only include IDs that are not marked as primary.
      if (!$node->field_animal_id->isEmpty() &&
          (!$node->hasField('field_primary_id') ||
           $node->field_primary_id->isEmpty() ||
           !$node->field_primary_id->value)) {
        $ids[] = $node->field_animal_id->value;
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
      ->condition('type', 'manatee')
      ->accessCheck(FALSE);

    $species_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($species_query->execute());

    $rows = [];
    foreach ($species_nodes as $species_entity) {
      // Check if this species has any primary IDs.
      $primary_id_query = $this->entityTypeManager->getStorage('node')->getQuery()
        ->condition('type', 'manatee_animal_id')
        ->condition('field_animal', $species_entity->id())
        ->condition('field_primary_id', 1)
        ->accessCheck(FALSE);

      $has_primary = !empty($primary_id_query->execute());

      // If no primary IDs found, add to our results.
      if (!$has_primary) {
        $mlog = !$species_entity->field_mlog->isEmpty() ? $species_entity->field_mlog->value : '';
        $mlog_link = Link::createFromRoute(
        $mlog,
        'entity.node.canonical',
        ['node' => $species_entity->id()]
        );

        $row = [
          'data' => [
          ['data' => $mlog_link],
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
        $this->t('MLog'),
        $this->t('Primary Name'),
        $this->t('Animal IDs (Not Primary List)'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No results found without a primary ID.'),
      '#attributes' => ['class' => ['tracking-without-primary-id-report']],
    ];
  }

}
