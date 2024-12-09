<?php

namespace Drupal\manatee_reports\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for generating reports of manatees without birth or rescue events.
 *
 * This controller provides functionality to list all manatee entities that do not
 * have associated birth or rescue event records. The report includes basic
 * manatee information such as IDs, names, and creation/update metadata.
 */
class ManateesWithoutEventsController extends ControllerBase {

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
   * Constructs a ManateesWithoutEventsController object.
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
   * Retrieves all animal IDs associated with a manatee.
   *
   * @param int $manatee_id
   *   The node ID of the manatee entity.
   *
   * @return string
   *   Comma-separated list of animal IDs.
   */
  private function getAnimalIds($manatee_id) {
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
   * Retrieves the primary name of a manatee.
   *
   * @param int $manatee_id
   *   The node ID of the manatee entity.
   *
   * @return string
   *   The primary name of the manatee, or an empty string if none exists.
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
   * Retrieves the sex of a manatee.
   *
   * @param \Drupal\node\NodeInterface $manatee_node
   *   The manatee node entity.
   *
   * @return string
   *   The sex of the manatee ('M', 'F', or 'U' for unknown).
   */
  private function getManateeSex($manatee_node) {
    if ($manatee_node->hasField('field_sex') && !$manatee_node->field_sex->isEmpty()) {
      $term = $manatee_node->field_sex->entity;
      if ($term) {
        return $term->getName();
      }
    }
    return 'U';
  }

  /**
   * Builds the content for the manatees without events report.
   *
   * Generates a table displaying all manatees that don't have associated birth
   * or rescue events. The table includes the following columns:
   * - MLog: Link to the manatee's detail page
   * - Primary Name: The manatee's primary name
   * - Animal ID List: All associated animal IDs
   * - Sex: The manatee's sex (M/F/U)
   * - Created By: Username of the creator
   * - Created Date: Creation date in m/d/Y format
   * - Updated By: Username of the last updater
   * - Updated Date: Last update date in m/d/Y format.
   *
   * @return array
   *   A render array for a table of manatees without events.
   */
  public function content() {
    $manatee_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'manatee')
      ->accessCheck(FALSE);

    $manatee_ids = $manatee_query->execute();
    $rows = [];

    foreach ($manatee_ids as $manatee_id) {
      $birth_query = $this->entityTypeManager->getStorage('node')->getQuery()
        ->condition('type', 'manatee_birth')
        ->condition('field_animal', $manatee_id)
        ->accessCheck(FALSE);

      $rescue_query = $this->entityTypeManager->getStorage('node')->getQuery()
        ->condition('type', 'manatee_rescue')
        ->condition('field_animal', $manatee_id)
        ->accessCheck(FALSE);

      if (!empty($birth_query->execute()) || !empty($rescue_query->execute())) {
        continue;
      }

      $manatee = $this->entityTypeManager->getStorage('node')->load($manatee_id);

      $created_user = $this->entityTypeManager->getStorage('user')->load($manatee->getOwner()->id());
      $updated_user = $this->entityTypeManager->getStorage('user')->load($manatee->getRevisionUser()->id());

      $mlog = !$manatee->field_mlog->isEmpty() ? $manatee->field_mlog->value : '';
      $mlog_link = Link::createFromRoute(
        $mlog,
        'entity.node.canonical',
        ['node' => $manatee_id]
      );

      $row = [
        'data' => [
          ['data' => $mlog_link],
          ['data' => $this->getPrimaryName($manatee_id)],
          ['data' => $this->getAnimalIds($manatee_id)],
          ['data' => $this->getManateeSex($manatee)],
          ['data' => $created_user ? $created_user->getAccountName() : ''],
          ['data' => $this->dateFormatter->format($manatee->getCreatedTime(), 'custom', 'm/d/Y')],
          ['data' => $updated_user ? $updated_user->getAccountName() : ''],
          ['data' => $this->dateFormatter->format($manatee->getChangedTime(), 'custom', 'm/d/Y')],
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
        $this->t('MLog'),
        $this->t('Primary Name'),
        $this->t('Animal ID List'),
        $this->t('Sex'),
        $this->t('Created By'),
        $this->t('Created Date'),
        $this->t('Updated By'),
        $this->t('Updated Date'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No manatees found without birth or rescue records.'),
      '#attributes' => ['class' => ['manatees-without-events-report']],
    ];
  }

}
