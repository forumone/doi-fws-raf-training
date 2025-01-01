<?php

namespace Drupal\tracking_reports\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

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
   * Gets the primary name for a species entity.
   *
   * @param \Drupal\node\NodeInterface $species
   *   The species node.
   *
   * @return string
   *   The primary name or 'N/A' if not found.
   */
  protected function getPrimaryName(NodeInterface $species) {
    if ($species->hasField('field_names') && !$species->field_names->isEmpty()) {
      foreach ($species->field_names->referencedEntities() as $paragraph) {
        if ($paragraph->hasField('field_primary')
            && !$paragraph->field_primary->isEmpty()
            && $paragraph->field_primary->value == 1
            && !$paragraph->field_name->isEmpty()) {
          return $paragraph->field_name->value;
        }
      }
    }
    return 'N/A';
  }

  /**
   * Sorts the rows array by the specified column.
   *
   * @param array $rows
   *   The rows to sort.
   * @param string $sort
   *   The column to sort by.
   * @param string $direction
   *   The sort direction ('asc' or 'desc').
   *
   * @return array
   *   The sorted rows.
   */
  protected function sortRows(array $rows, $sort, $direction) {
    $column_map = [
      'number' => 0,
      'name' => 1,
      'facility' => 2,
      'release_date' => 3,
      'entered_by' => 4,
      'email' => 5,
      'phone' => 6,
    ];

    if (!isset($column_map[$sort])) {
      return $rows;
    }

    $column = $column_map[$sort];

    usort($rows, function ($a, $b) use ($column, $direction) {
      $a_value = strip_tags($a['data'][$column]['data']);
      $b_value = strip_tags($b['data'][$column]['data']);

      // Special handling for dates
      if ($column === 3 && $a_value !== 'N/A' && $b_value !== 'N/A') {
        $a_value = strtotime($a_value);
        $b_value = strtotime($b_value);
      }

      if ($direction === 'asc') {
        return strcasecmp($a_value, $b_value);
      }
      return strcasecmp($b_value, $a_value);
    });

    return $rows;
  }

  /**
   * Builds the report page.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return array
   *   A render array representing the report page.
   */
  public function content(Request $request) {
    // Get sorting parameters from the request.
    $sort = $request->query->get('sort', 'number');
    $direction = $request->query->get('direction', 'asc');

    // First, get all species references that have a release record
    $release_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'species_release')
      ->condition('field_species_ref', NULL, 'IS NOT NULL')
      ->accessCheck(FALSE);
    $release_refs = $release_query->execute();

    // Get the species references from the release nodes.
    $released_species_ids = [];
    if (!empty($release_refs)) {
      $release_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($release_refs);
      foreach ($release_nodes as $release) {
        if (!$release->field_species_ref->isEmpty()) {
          $released_species_ids[] = $release->field_species_ref->target_id;
        }
      }
    }

    // Get pre-release records that don't have a corresponding release.
    $pre_release_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'species_prerelease')
      ->condition('field_species_ref', NULL, 'IS NOT NULL')
      ->accessCheck(FALSE);

    // If we found any released species, exclude them.
    if (!empty($released_species_ids)) {
      $pre_release_query->condition('field_species_ref', $released_species_ids, 'NOT IN');
    }

    $pre_release_ids = $pre_release_query->execute();

    if (empty($pre_release_ids)) {
      return [
        '#markup' => $this->t('No pre-release records found.'),
      ];
    }

    $rows = [];
    $pre_release_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($pre_release_ids);

    foreach ($pre_release_nodes as $pre_release) {
      // Load the associated species entity.
      if ($pre_release->field_species_ref->isEmpty()) {
        continue;
      }

      $species_entity = $this->entityTypeManager->getStorage('node')->load($pre_release->field_species_ref->target_id);
      if (!$species_entity) {
        continue;
      }

      // Load the node author.
      $author = $this->entityTypeManager->getStorage('user')->load($pre_release->getOwnerId());

      $number = !$species_entity->field_number->isEmpty() ? $species_entity->field_number->value : 'N/A';
      $number_link = Link::createFromRoute(
        $number,
        'entity.node.canonical',
        ['node' => $species_entity->id()]
      );

      // Get the primary name.
      $name = $this->getPrimaryName($species_entity);

      // Build the row.
      $row = [
        'data' => [
          ['data' => $number_link],
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

    // Sort the rows.
    $rows = $this->sortRows($rows, $sort, $direction);

    // Create sortable headers.
    $headers = [
      ['data' => $this->t('Tracking Number'), 'field' => 'number'],
      ['data' => $this->t('Name'), 'field' => 'name'],
      ['data' => $this->t('Facility'), 'field' => 'facility'],
      ['data' => $this->t('Expected Release'), 'field' => 'release_date'],
      ['data' => $this->t('Entered by'), 'field' => 'entered_by'],
      ['data' => $this->t('EMail'), 'field' => 'email'],
      ['data' => $this->t('Phone'), 'field' => 'phone'],
    ];

    // Build the table.
    $build['table'] = [
      '#type' => 'table',
      '#header' => $headers,
      '#rows' => $rows,
      '#empty' => $this->t('No pre-release records without matching release records found.'),
      '#attributes' => ['class' => ['tracking-prerelease-report']],
    ];

    // Add table sort functionality.
    foreach ($headers as &$header) {
      if (isset($header['field'])) {
        $header['sort'] = 'asc';
        if ($sort == $header['field']) {
          $header['sort'] = $direction == 'asc' ? 'desc' : 'asc';
          $header['sorted'] = TRUE;
        }
      }
    }

    return $build;
  }

}