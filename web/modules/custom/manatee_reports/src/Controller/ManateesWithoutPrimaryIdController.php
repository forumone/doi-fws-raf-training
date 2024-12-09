<?php

namespace Drupal\manatee_reports\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for generating reports of manatees without a primary animal ID.
 *
 * This controller identifies manatees by looking at manatee_animal_id nodes
 * and finding cases where there are no primary IDs set for a given manatee.
 */
class ManateesWithoutPrimaryIdController extends ControllerBase {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a ManateesWithoutPrimaryIdController object.
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
   * Gets the primary name for a manatee.
   *
   * @param int $manatee_id
   *   The node ID of the manatee entity.
   *
   * @return string
   *   The primary name or empty string if none exists.
   */
  private function getPrimaryName($manatee_id) {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'manatee_name')
      ->condition('field_animal', $manatee_id)
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
   * Gets all non-primary animal IDs for a manatee.
   *
   * @param int $manatee_id
   *   The node ID of the manatee entity.
   *
   * @return string
   *   Comma-separated list of non-primary animal IDs.
   */
  private function getNonPrimaryAnimalIds($manatee_id) {
    $ids = [];
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'manatee_animal_id')
      ->condition('field_animal', $manatee_id)
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
   * Builds the content for the manatees without primary ID report.
   *
   * @return array
   *   A render array for a table of manatees without primary IDs.
   */
  public function content() {
    // First get all manatee nodes.
    $manatee_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'manatee')
      ->accessCheck(FALSE);

    $manatee_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($manatee_query->execute());

    $rows = [];
    foreach ($manatee_nodes as $manatee) {
      // Check if this manatee has any primary IDs.
      $primary_id_query = $this->entityTypeManager->getStorage('node')->getQuery()
        ->condition('type', 'manatee_animal_id')
        ->condition('field_animal', $manatee->id())
        ->condition('field_primary_id', 1)
        ->accessCheck(FALSE);

      $has_primary = !empty($primary_id_query->execute());

      // If no primary IDs found, add to our results.
      if (!$has_primary) {
        $mlog = !$manatee->field_mlog->isEmpty() ? $manatee->field_mlog->value : '';
        $mlog_link = Link::createFromRoute(
        $mlog,
        'entity.node.canonical',
        ['node' => $manatee->id()]
        );

        $row = [
          'data' => [
          ['data' => $mlog_link],
          ['data' => $this->getPrimaryName($manatee->id())],
          ['data' => $this->getNonPrimaryAnimalIds($manatee->id())],
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
      '#empty' => $this->t('No manatees found without a primary animal ID.'),
      '#attributes' => ['class' => ['manatees-without-primary-id-report']],
    ];
  }

}
