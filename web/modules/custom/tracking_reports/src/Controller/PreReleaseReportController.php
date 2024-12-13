<?php

namespace Drupal\tracking_reports\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for the pre-release without release report.
 */
class PreReleaseReportController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The date formatter.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * Constructs a PreReleaseReportController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
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
   * Builds the report page.
   *
   * @return array
   *   A render array representing the report page.
   */
  public function content() {
    // Get all pre-release records.
    $pre_release_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'manatee_prerelease')
      ->condition('field_animal', NULL, 'IS NOT NULL')
      ->accessCheck(FALSE);

    $pre_release_ids = $pre_release_query->execute();

    if (empty($pre_release_ids)) {
      return [
        '#markup' => $this->t('No pre-release records found.'),
      ];
    }

    $rows = [];
    $pre_release_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($pre_release_ids);

    foreach ($pre_release_nodes as $pre_release) {
      // Check if there's a matching release record.
      $release_query = $this->entityTypeManager->getStorage('node')->getQuery()
        ->condition('type', 'manatee_release')
        ->condition('field_animal', $pre_release->field_animal->target_id)
        ->accessCheck(FALSE);

      $release_exists = !empty($release_query->execute());

      // Skip if there's a matching release record.
      if ($release_exists) {
        continue;
      }

      // Load the associated species entity.
      $species_entity = $this->entityTypeManager->getStorage('node')->load($pre_release->field_animal->target_id);
      if (!$species_entity) {
        continue;
      }

      // Load the node author.
      $author = $this->entityTypeManager->getStorage('user')->load($pre_release->getOwnerId());

      $mlog = !$species_entity->field_mlog->isEmpty() ? $species_entity->field_mlog->value : 'N/A';
      $mlog_link = Link::createFromRoute(
        $mlog,
        'entity.node.canonical',
        ['node' => $species_entity->id()]
      );

      // Get the primary name.
      $name_query = $this->entityTypeManager->getStorage('node')->getQuery()
        ->condition('type', 'manatee_name')
        ->condition('field_animal', $species_entity->id())
        ->condition('field_primary', 1)
        ->accessCheck(FALSE)
        ->execute();

      $name = 'N/A';
      if (!empty($name_query)) {
        $name_node = $this->entityTypeManager->getStorage('node')->load(reset($name_query));
        if ($name_node && !$name_node->field_name->isEmpty()) {
          $name = $name_node->field_name->value;
        }
      }

      // Build the row.
      $row = [
        'data' => [
          ['data' => $mlog_link],
          ['data' => $name],
          ['data' => !$pre_release->field_org->isEmpty() ? $pre_release->field_org->entity->label() : 'N/A'],
          ['data' => !$pre_release->field_release_date->isEmpty() ? $this->dateFormatter->format(strtotime($pre_release->field_release_date->value), 'custom', 'm/d/Y') : 'N/A'],
          ['data' => $author ? $author->getDisplayName() : 'N/A'],
          ['data' => $author ? $author->getEmail() : 'N/A'],
          ['data' => $author && $author->hasField('field_phone') && !$author->field_phone->isEmpty() ? $author->field_phone->value : 'N/A'],
        ],
      ];

      $rows[] = $row;
    }

    // Build the table.
    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('MLog'),
        $this->t('Name'),
        $this->t('Facility'),
        $this->t('Expected Release'),
        $this->t('Entered by'),
        $this->t('EMail'),
        $this->t('Phone'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No pre-release records without matching release records found.'),
      '#attributes' => ['class' => ['tracking-prerelease-report']],
    ];

    return $build;
  }

}
