<?php

namespace Drupal\tracking_reports\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for generating reports of species without birth or rescue events.
 *
 * This controller provides functionality to list all species entities that do not
 * have associated birth or rescue event records. The report includes basic
 * species information such as IDs, names, and creation/update metadata.
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
   * Constructs a TrackingWithoutEventsController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    DateFormatterInterface $date_formatter,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->dateFormatter = $date_formatter;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('date.formatter')
    );
  }

  /**
   * Retrieves all species IDs associated with a species.
   *
   * @param int $species_id
   *   The node ID of the species entity.
   *
   * @return string
   *   Comma-separated list of species IDs.
   */
  private function getSpeciesIds($species_id) {
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
   * Retrieves the primary name of a species.
   *
   * @param int $species_id
   *   The node ID of the species entity.
   *
   * @return string
   *   The primary name of the species, or an empty string if none exists.
   */
  private function getPrimaryName($species_id) {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'species_name')
      ->condition('field_species_ref', $species_id)
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
   * Generates a table displaying all species that don't have associated birth
   * or rescue events. The table includes the following columns:
   * - Tracking Number: Link to the species' detail page
   * - Primary Name: The species' primary name
   * - Species ID List: All associated species IDs
   * - Sex: The species' sex (M/F/U)
   * - Created By: Username of the creator
   * - Created Date: Creation date in m/d/Y format
   * - Updated By: Username of the last updater
   * - Updated Date: Last update date in m/d/Y format.
   *
   * @return array
   *   A render array for a table of species without events.
   */
  public function content() {
    $species_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'species')
      ->accessCheck(FALSE);

    $species_ids = $species_query->execute();
    $rows = [];

    foreach ($species_ids as $species_id) {
      $birth_query = $this->entityTypeManager->getStorage('node')->getQuery()
        ->condition('type', 'species_birth')
        ->condition('field_species_ref', $species_id)
        ->accessCheck(FALSE);

      $rescue_query = $this->entityTypeManager->getStorage('node')->getQuery()
        ->condition('type', 'species_rescue')
        ->condition('field_species_ref', $species_id)
        ->accessCheck(FALSE);

      if (!empty($birth_query->execute()) || !empty($rescue_query->execute())) {
        continue;
      }

      $species_entity = $this->entityTypeManager->getStorage('node')->load($species_id);

      $created_user = $this->entityTypeManager->getStorage('user')->load($species_entity->getOwner()->id());
      $updated_user = $this->entityTypeManager->getStorage('user')->load($species_entity->getRevisionUser()->id());

      $number = !$species_entity->field_number->isEmpty() ? $species_entity->field_number->value : '';
      $number_link = Link::createFromRoute(
        $number,
        'entity.node.canonical',
        ['node' => $species_id]
      );

      $row = [
        'data' => [
          ['data' => $number_link],
          ['data' => $this->getPrimaryName($species_id)],
          ['data' => $this->getSpeciesIds($species_id)],
          ['data' => $this->getSpeciesSex($species_entity)],
          ['data' => $created_user ? $created_user->getAccountName() : ''],
          ['data' => $this->dateFormatter->format($species_entity->getCreatedTime(), 'custom', 'm/d/Y')],
          ['data' => $updated_user ? $updated_user->getAccountName() : ''],
          ['data' => $this->dateFormatter->format($species_entity->getChangedTime(), 'custom', 'm/d/Y')],
        ],
      ];

      $rows[] = $row;
    }

    usort($rows, function ($a, $b) {
      return strcasecmp($a['data'][1]['data'], $b['data'][1]['data']);
    });

    return [
      '#type' => 'table',
      '#header' => [
        $this->t('Tracking Number'),
        $this->t('Primary Name'),
        $this->t('Species ID List'),
        $this->t('Sex'),
        $this->t('Created By'),
        $this->t('Created Date'),
        $this->t('Updated By'),
        $this->t('Updated Date'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No results found without birth or rescue records.'),
      '#attributes' => ['class' => ['species-without-events-report']],
    ];
  }

}
