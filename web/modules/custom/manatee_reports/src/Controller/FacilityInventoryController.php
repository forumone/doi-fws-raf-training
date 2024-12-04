<?php

namespace Drupal\manatee_reports\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for displaying current captive manatees by facility.
 */
class FacilityInventoryController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a FacilityInventoryController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
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
   * Calculate time in captivity in years and months.
   *
   * @param \Drupal\Core\Datetime\DrupalDateTime $start_date
   *   The start date.
   * @param \Drupal\Core\Datetime\DrupalDateTime $current_date
   *   The current date.
   *
   * @return string
   *   Formatted string showing years and/or months.
   */
  protected function calculateTimeInCaptivity(DrupalDateTime $start_date, DrupalDateTime $current_date) {
    $interval = $current_date->diff($start_date);
    $years = $interval->y;
    $months = $interval->m;

    if ($years > 0) {
      if ($months > 0) {
        return sprintf('%d yr, %d mo', $years, $months);
      }
      return sprintf('%d yr', $years);
    }
    return sprintf('%d mo', $months);
  }

  /**
   * Returns the page content.
   *
   * @return array
   *   Render array for the page.
   */
  public function content() {
    // Get deceased manatee IDs.
    $death_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'manatee_death')
      ->condition('field_animal', NULL, 'IS NOT NULL')
      ->accessCheck(FALSE)
      ->execute();

    $deceased_manatee_ids = [];
    if (!empty($death_query)) {
      $death_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($death_query);
      foreach ($death_nodes as $death_node) {
        if ($death_node->hasField('field_animal') && !$death_node->field_animal->isEmpty()) {
          $deceased_manatee_ids[] = $death_node->field_animal->target_id;
        }
      }
    }

    // Get primary names.
    $name_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'manatee_name')
      ->condition('field_animal', NULL, 'IS NOT NULL')
      ->condition('field_primary', 1)
      ->accessCheck(FALSE)
      ->execute();

    $primary_names = [];
    if (!empty($name_query)) {
      $name_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($name_query);
      foreach ($name_nodes as $name_node) {
        if ($name_node->hasField('field_animal') && !$name_node->field_animal->isEmpty()) {
          $animal_id = $name_node->field_animal->target_id;
          if ($name_node->hasField('field_name') && !$name_node->field_name->isEmpty()) {
            $primary_names[$animal_id] = $name_node->field_name->value;
          }
        }
      }
    }

    // Get animal IDs.
    $animal_id_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'manatee_animal_id')
      ->condition('field_animal', NULL, 'IS NOT NULL')
      ->accessCheck(FALSE)
      ->execute();

    $animal_ids = [];
    if (!empty($animal_id_query)) {
      $animal_id_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($animal_id_query);
      foreach ($animal_id_nodes as $animal_id_node) {
        if ($animal_id_node->hasField('field_animal') && !$animal_id_node->field_animal->isEmpty()) {
          $animal_id = $animal_id_node->field_animal->target_id;
          if ($animal_id_node->hasField('field_animal_id') && !$animal_id_node->field_animal_id->isEmpty()) {
            $animal_ids[$animal_id] = $animal_id_node->field_animal_id->value;
          }
        }
      }
    }

    // Get rescue events.
    $rescue_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'manatee_rescue')
      ->condition('field_animal', NULL, 'IS NOT NULL')
      ->condition('field_rescue_date', NULL, 'IS NOT NULL')
      ->accessCheck(FALSE)
      ->execute();

    $rescue_types = [];
    $type_b_rescue_dates = [];
    if (!empty($rescue_query)) {
      $rescue_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($rescue_query);
      $animal_rescues = [];

      foreach ($rescue_nodes as $rescue_node) {
        if ($rescue_node->hasField('field_animal') && !$rescue_node->field_animal->isEmpty()) {
          $animal_id = $rescue_node->field_animal->target_id;
          $date = $rescue_node->field_rescue_date->value;
          $rescue_type = '';
          if ($rescue_node->hasField('field_rescue_type') && !$rescue_node->field_rescue_type->isEmpty()) {
            $rescue_type = $rescue_node->field_rescue_type->entity->getName();
          }

          $animal_rescues[$animal_id][] = [
            'date' => $date,
            'type' => $rescue_type,
          ];

          if ($rescue_type === 'B') {
            if (!isset($type_b_rescue_dates[$animal_id]) || $date > $type_b_rescue_dates[$animal_id]) {
              $type_b_rescue_dates[$animal_id] = $date;
            }
          }
        }
      }

      foreach ($animal_rescues as $animal_id => $rescues) {
        usort($rescues, function ($a, $b) {
          return strcmp($b['date'], $a['date']);
        });
        $rescue_types[$animal_id] = $rescues[0]['type'];
      }
    }

    // Get birth dates.
    $birth_dates = [];
    $birth_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'manatee_birth')
      ->condition('field_animal', NULL, 'IS NOT NULL')
      ->condition('field_birth_date', NULL, 'IS NOT NULL')
      ->accessCheck(FALSE)
      ->execute();

    if (!empty($birth_query)) {
      $birth_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($birth_query);
      foreach ($birth_nodes as $birth_node) {
        if ($birth_node->hasField('field_animal') && !$birth_node->field_animal->isEmpty()) {
          $animal_id = $birth_node->field_animal->target_id;
          $birth_dates[$animal_id] = $birth_node->field_birth_date->value;
        }
      }
    }

    // Define event types.
    $event_types = [
      'manatee_birth' => 'field_birth_date',
      'manatee_rescue' => 'field_rescue_date',
      'transfer' => 'field_transfer_date',
      'manatee_release' => 'field_release_date',
    ];

    // Get all manatees with MLOGs that aren't deceased.
    $manatee_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'manatee')
      ->condition('field_mlog', NULL, 'IS NOT NULL');

    if (!empty($deceased_manatee_ids)) {
      $manatee_query->condition('nid', $deceased_manatee_ids, 'NOT IN');
    }

    $manatee_ids = $manatee_query->accessCheck(FALSE)->execute();

    // Get all events.
    $event_nodes = [];
    foreach ($event_types as $type => $date_field) {
      $query = $this->entityTypeManager->getStorage('node')->getQuery()
        ->condition('type', $type)
        ->condition('field_animal', $manatee_ids, 'IN')
        ->condition('field_animal', NULL, 'IS NOT NULL')
        ->condition($date_field, NULL, 'IS NOT NULL')
        ->accessCheck(FALSE);

      $results = $query->execute();

      if (!empty($results)) {
        $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($results);
        foreach ($nodes as $node) {
          if ($node->hasField('field_animal') && !$node->field_animal->isEmpty()) {
            $animal_id = $node->field_animal->target_id;
            $date_value = $node->get($date_field)->value;

            $facility_term = NULL;
            if ($type === 'transfer' && $node->hasField('field_to_facility') && !$node->field_to_facility->isEmpty()) {
              $facility_term = $node->field_to_facility->entity;
            }
            elseif ($node->hasField('field_org') && !$node->field_org->isEmpty()) {
              $facility_term = $node->field_org->entity;
            }

            $event_nodes[$animal_id][] = [
              'nid' => $node->id(),
              'type' => $type,
              'date' => $date_value,
              'date_field' => $date_field,
              'facility_term' => $facility_term,
            ];
          }
        }
      }
    }

    // Process events.
    $most_recent_events = [];
    foreach ($manatee_ids as $animal_id) {
      if (isset($event_nodes[$animal_id])) {
        usort($event_nodes[$animal_id], function ($a, $b) {
          return strcmp($b['date'], $a['date']);
        });

        if ($event_nodes[$animal_id][0]['type'] !== 'manatee_release') {
          $most_recent_events[$animal_id] = $event_nodes[$animal_id][0];
        }
      }
    }

    // Load manatees.
    $manatees = $this->entityTypeManager->getStorage('node')->loadMultiple($manatee_ids);
    $current_date = new DrupalDateTime();

    // Prepare rows.
    $rows = [];
    foreach ($manatees as $manatee) {
      if (!isset($most_recent_events[$manatee->id()])) {
        continue;
      }

      $event = $most_recent_events[$manatee->id()];

      $mlog = "N/A";
      if ($manatee->hasField('field_mlog') && !$manatee->field_mlog->isEmpty()) {
        $mlog_value = $manatee->get('field_mlog')->getValue();
        $mlog = $mlog_value[0]['value'] ?? "N/A";
      }

      // Create a link for the MLOG value.
      $mlog_link = Link::createFromRoute(
        $mlog,
        'entity.node.canonical',
        ['node' => $manatee->id()]
      );

      $event_type = str_replace('manatee_', '', $event['type']);
      $event_type = str_replace('_', ' ', $event_type);
      $event_type = ucfirst($event_type);

      $date = new DrupalDateTime($event['date']);
      $formatted_date = $date->format('Y-m-d');

      $name = $primary_names[$manatee->id()] ?? '';
      $animal_id = $animal_ids[$manatee->id()] ?? '';
      $rescue_type = $rescue_types[$manatee->id()] ?? 'none';

      $captivity_date = NULL;
      if (isset($type_b_rescue_dates[$manatee->id()])) {
        $captivity_date = new DrupalDateTime($type_b_rescue_dates[$manatee->id()]);
      }
      elseif (isset($birth_dates[$manatee->id()])) {
        $captivity_date = new DrupalDateTime($birth_dates[$manatee->id()]);
      }

      $time_in_captivity = 'N/A';
      if ($captivity_date) {
        $time_in_captivity = $this->calculateTimeInCaptivity($captivity_date, $current_date);
      }

      if ($rescue_type === 'B' || $rescue_type === 'none') {
        $facility_name = 'N/A';
        if ($event['facility_term'] && $event['facility_term']->hasField('name')) {
          $facility_name = $event['facility_term']->getName();
        }

        $rows[] = [
          'data' => [
            ['data' => $facility_name],
            ['data' => $name],
            ['data' => $animal_id],
            ['data' => $mlog_link],
            ['data' => $event_type],
            ['data' => $formatted_date],
            ['data' => $time_in_captivity],
          ],
          'facility_name' => $facility_name,
        ];
      }
    }

    // Sort rows by facility name ascending.
    usort($rows, function ($a, $b) {
      return strcmp($a['facility_name'], $b['facility_name']);
    });

    // Prepare table headers.
    $header = [
      $this->t('Facility'),
      $this->t('Name'),
      $this->t('Manatee ID'),
      $this->t('Manatee Number'),
      $this->t('Event'),
      $this->t('Event Date'),
      $this->t('Time in Captivity'),
    ];

    // Build table.
    $table = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => array_map(function ($row) {
        return ['data' => $row['data']];
      }, $rows),
      '#empty' => $this->t('No manatees found'),
      '#attributes' => ['class' => ['manatee-report-table']],
    ];

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['manatee-report-container']],
      'table' => $table,
      '#attached' => [
        'library' => [
          'manatee_reports/manatee_reports',
        ],
      ],
    ];
  }

}
