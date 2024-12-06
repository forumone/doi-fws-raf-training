<?php

namespace Drupal\manatee_reports\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for generating reports of manatees without a primary name.
 *
 * This controller identifies manatees by looking at manatee_name nodes
 * and finding cases where there are no primary names set for a given manatee.
 */
class ManateesWithoutPrimaryNameController extends ControllerBase {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a ManateesWithoutPrimaryNameController object.
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
   * Gets all non-primary names for a manatee.
   *
   * @param int $manatee_id
   *   The node ID of the manatee entity.
   *
   * @return string
   *   Comma-separated list of non-primary names.
   */
  private function getNonPrimaryNames($manatee_id) {
    $names = [];
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'manatee_name')
      ->condition('field_animal', $manatee_id)
      ->condition('field_primary', 1, '<>')
      ->accessCheck(FALSE);

    $name_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($query->execute());
    foreach ($name_nodes as $node) {
      if (!$node->field_name->isEmpty()) {
        $names[] = $node->field_name->value;
      }
    }
    return implode(', ', $names);
  }

  /**
   * Gets all animal IDs for a manatee.
   *
   * @param int $manatee_id
   *   The node ID of the manatee entity.
   *
   * @return string
   *   Comma-separated list of all animal IDs.
   */
  private function getAllAnimalIds($manatee_id) {
    $ids = [];
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'manatee_animal_id')
      ->condition('field_animal', $manatee_id)
      ->accessCheck(FALSE);

    $id_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($query->execute());
    foreach ($id_nodes as $node) {
      if (!$node->field_animal_id->isEmpty()) {
        $ids[] = $node->field_animal_id->value;
      }
    }
    return implode(', ', $ids);
  }

  /**
   * Builds the content for the manatees without primary name report.
   *
   * @return array
   *   A render array for a table of manatees without primary names.
   */
  public function content() {
    // First get all manatee nodes that have associated manatee_name nodes.
    $manatee_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'manatee')
      ->accessCheck(FALSE);

    $manatee_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($manatee_query->execute());

    $rows = [];
    foreach ($manatee_nodes as $manatee) {
      // First check if this manatee has any name nodes at all.
      $has_names_query = $this->entityTypeManager->getStorage('node')->getQuery()
        ->condition('type', 'manatee_name')
        ->condition('field_animal', $manatee->id())
        ->accessCheck(FALSE);

      $has_any_names = !empty($has_names_query->execute());

      if ($has_any_names) {
        // Then check if any of those names are primary.
        $primary_name_query = $this->entityTypeManager->getStorage('node')->getQuery()
          ->condition('type', 'manatee_name')
          ->condition('field_animal', $manatee->id())
          ->condition('field_primary', 1)
          ->accessCheck(FALSE);

        $has_primary = !empty($primary_name_query->execute());

        // If no primary names found but has other names, add to our results.
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
              ['data' => $this->getNonPrimaryNames($manatee->id())],
              ['data' => $this->getAllAnimalIds($manatee->id())],
            ],
          ];

          $rows[] = $row;
        }
      }
    }

    return [
      '#type' => 'table',
      '#header' => [
        $this->t('MLog'),
        $this->t('Manatee Name List(Not Primary)'),
        $this->t('Animal ID List (All)'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No manatees found without a primary name.'),
      '#attributes' => ['class' => ['manatees-without-primary-name-report']],
    ];
  }

}
