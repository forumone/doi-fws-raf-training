<?php

/**
 * @file
 * Get manatees including those without rescue events.
 */

// First get all deceased manatee IDs to exclude them.
$death_query = \Drupal::entityQuery('node')
  ->condition('type', 'manatee_death')
  ->condition('field_animal', NULL, 'IS NOT NULL')
  ->accessCheck(FALSE)
  ->execute();

$deceased_manatee_ids = [];
if (!empty($death_query)) {
  $death_nodes = \Drupal::entityTypeManager()
    ->getStorage('node')
    ->loadMultiple($death_query);

  foreach ($death_nodes as $death_node) {
    if ($death_node->hasField('field_animal') && !$death_node->field_animal->isEmpty()) {
      $deceased_manatee_ids[] = $death_node->field_animal->target_id;
    }
  }
}

// Get primary names for all manatees.
$name_query = \Drupal::entityQuery('node')
  ->condition('type', 'manatee_name')
  ->condition('field_animal', NULL, 'IS NOT NULL')
  ->condition('field_primary', 1)
  ->accessCheck(FALSE)
  ->execute();

$primary_names = [];
if (!empty($name_query)) {
  $name_nodes = \Drupal::entityTypeManager()
    ->getStorage('node')
    ->loadMultiple($name_query);

  foreach ($name_nodes as $name_node) {
    if ($name_node->hasField('field_animal') && !$name_node->field_animal->isEmpty()) {
      $animal_id = $name_node->field_animal->target_id;
      if ($name_node->hasField('field_name') && !$name_node->field_name->isEmpty()) {
        $primary_names[$animal_id] = $name_node->field_name->value;
      }
    }
  }
}

// Get animal IDs for all manatees.
$animal_id_query = \Drupal::entityQuery('node')
  ->condition('type', 'manatee_animal_id')
  ->condition('field_animal', NULL, 'IS NOT NULL')
  ->accessCheck(FALSE)
  ->execute();

$animal_ids = [];
if (!empty($animal_id_query)) {
  $animal_id_nodes = \Drupal::entityTypeManager()
    ->getStorage('node')
    ->loadMultiple($animal_id_query);

  foreach ($animal_id_nodes as $animal_id_node) {
    if ($animal_id_node->hasField('field_animal') && !$animal_id_node->field_animal->isEmpty()) {
      $animal_id = $animal_id_node->field_animal->target_id;
      if ($animal_id_node->hasField('field_animal_id') && !$animal_id_node->field_animal_id->isEmpty()) {
        $animal_ids[$animal_id] = $animal_id_node->field_animal_id->value;
      }
    }
  }
}

// Get all rescue events to determine rescue types.
$rescue_query = \Drupal::entityQuery('node')
  ->condition('type', 'manatee_rescue')
  ->condition('field_animal', NULL, 'IS NOT NULL')
  ->condition('field_rescue_date', NULL, 'IS NOT NULL')
  ->accessCheck(FALSE)
  ->execute();

$rescue_types = [];
// New array to store Type B rescue dates.
$type_b_rescue_dates = [];
if (!empty($rescue_query)) {
  $rescue_nodes = \Drupal::entityTypeManager()
    ->getStorage('node')
    ->loadMultiple($rescue_query);

  // Group rescues by animal ID.
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

      // Store Type B rescue dates.
      if ($rescue_type === 'B') {
        if (!isset($type_b_rescue_dates[$animal_id]) || $date > $type_b_rescue_dates[$animal_id]) {
          $type_b_rescue_dates[$animal_id] = $date;
        }
      }
    }
  }

  // Determine most recent rescue type for each animal.
  foreach ($animal_rescues as $animal_id => $rescues) {
    usort($rescues, function ($a, $b) {
      return strcmp($b['date'], $a['date']);
    });
    $rescue_types[$animal_id] = $rescues[0]['type'];
  }
}

// Get birth dates for all manatees.
$birth_dates = [];
$birth_query = \Drupal::entityQuery('node')
  ->condition('type', 'manatee_birth')
  ->condition('field_animal', NULL, 'IS NOT NULL')
  ->condition('field_birth_date', NULL, 'IS NOT NULL')
  ->accessCheck(FALSE)
  ->execute();

if (!empty($birth_query)) {
  $birth_nodes = \Drupal::entityTypeManager()
    ->getStorage('node')
    ->loadMultiple($birth_query);

  foreach ($birth_nodes as $birth_node) {
    if ($birth_node->hasField('field_animal') && !$birth_node->field_animal->isEmpty()) {
      $animal_id = $birth_node->field_animal->target_id;
      $birth_dates[$animal_id] = $birth_node->field_birth_date->value;
    }
  }
}

// Define our event types and their date fields.
$event_types = [
  'manatee_birth' => 'field_birth_date',
  'manatee_rescue' => 'field_rescue_date',
  'transfer' => 'field_transfer_date',
  'manatee_release' => 'field_release_date',
];

// Get all manatees with MLOGs that aren't deceased.
$manatee_query = \Drupal::entityQuery('node')
  ->condition('type', 'manatee')
  ->condition('field_mlog', NULL, 'IS NOT NULL');

// Exclude deceased manatees.
if (!empty($deceased_manatee_ids)) {
  $manatee_query->condition('nid', $deceased_manatee_ids, 'NOT IN');
}

$manatee_ids = $manatee_query->accessCheck(FALSE)->execute();

// Get all events for these manatees.
$event_nodes = [];
foreach ($event_types as $type => $date_field) {
  $query = \Drupal::entityQuery('node')
    ->condition('type', $type)
    ->condition('field_animal', $manatee_ids, 'IN')
    ->condition('field_animal', NULL, 'IS NOT NULL')
    ->condition($date_field, NULL, 'IS NOT NULL')
    ->accessCheck(FALSE);

  $results = $query->execute();

  if (!empty($results)) {
    $nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadMultiple($results);

    foreach ($nodes as $node) {
      if ($node->hasField('field_animal') && !$node->field_animal->isEmpty()) {
        $animal_id = $node->field_animal->target_id;
        $date_value = $node->get($date_field)->value;

        // Get facility organization information based on event type.
        $organization = '';
        if ($type === 'transfer' && $node->hasField('field_to_facility') && !$node->field_to_facility->isEmpty()) {
          $facility_term = $node->field_to_facility->entity;
          if ($facility_term && $facility_term->hasField('field_organization')) {
            $organization = $facility_term->field_organization->value ?? '';
          }
        }
        elseif ($node->hasField('field_org') && !$node->field_org->isEmpty()) {
          $facility_term = $node->field_org->entity;
          if ($facility_term && $facility_term->hasField('field_organization')) {
            $organization = $facility_term->field_organization->value ?? '';
          }
        }

        $event_nodes[$animal_id][] = [
          'nid' => $node->id(),
          'type' => $type,
          'date' => $date_value,
          'date_field' => $date_field,
          'organization' => $organization,
        ];
      }
    }
  }
}

// Process events to find most recent per animal.
$most_recent_events = [];
foreach ($manatee_ids as $animal_id) {
  if (isset($event_nodes[$animal_id])) {
    // Sort events by date descending.
    usort($event_nodes[$animal_id], function ($a, $b) {
      return strcmp($b['date'], $a['date']);
    });

    // Skip if most recent event is a release.
    if ($event_nodes[$animal_id][0]['type'] !== 'manatee_release') {
      $most_recent_events[$animal_id] = $event_nodes[$animal_id][0];
    }
  }
}

// Load all qualifying manatees.
$manatees = \Drupal::entityTypeManager()
  ->getStorage('node')
  ->loadMultiple($manatee_ids);

// Get current date for days in captivity calculation.
$current_date = new DateTime();

// Prepare data for sorting.
$rows = [];
foreach ($manatees as $manatee) {
  // Skip if the most recent event is a release.
  if (!isset($most_recent_events[$manatee->id()])) {
    continue;
  }

  $event = $most_recent_events[$manatee->id()];

  // Get mlog value.
  $mlog = "N/A";
  $mlog_num = PHP_INT_MAX;
  if ($manatee->hasField('field_mlog') && !$manatee->field_mlog->isEmpty()) {
    $mlog_value = $manatee->get('field_mlog')->getValue();
    $mlog = $mlog_value[0]['value'] ?? "N/A";
    if (preg_match('/(\d+)/', $mlog, $matches)) {
      $mlog_num = intval($matches[0]);
    }
  }

  // Format event type for display.
  $event_type = str_replace('manatee_', '', $event['type']);
  $event_type = str_replace('_', ' ', $event_type);
  $event_type = ucfirst($event_type);

  // Format date.
  $date = new DateTime($event['date']);
  $formatted_date = $date->format('Y-m-d');

  // Get primary name if it exists.
  $name = $primary_names[$manatee->id()] ?? '';

  // Get animal ID if it exists.
  $animal_id = $animal_ids[$manatee->id()] ?? '';

  // Get rescue type if it exists, or mark as none.
  $rescue_type = $rescue_types[$manatee->id()] ?? 'none';

  // Calculate days in captivity.
  $captivity_date = NULL;
  if (isset($type_b_rescue_dates[$manatee->id()])) {
    // Use Type B rescue date if available.
    $captivity_date = new DateTime($type_b_rescue_dates[$manatee->id()]);
  }
  elseif (isset($birth_dates[$manatee->id()])) {
    // Use birth date if no Type B rescue.
    $captivity_date = new DateTime($birth_dates[$manatee->id()]);
  }

  $days_in_captivity = NULL;
  if ($captivity_date) {
    $interval = $current_date->diff($captivity_date);
    $days_in_captivity = $interval->days;
  }

  // Only include if either:
  // 1. The most recent rescue is type B
  // 2. There are no rescues associated with this manatee.
  if ($rescue_type === 'B' || $rescue_type === 'none') {
    $rows[] = [
      'nid' => $manatee->id(),
      'mlog' => $mlog,
      'mlog_num' => $mlog_num,
      'name' => $name,
      'animal_id' => $animal_id,
      'rescue_type' => $rescue_type,
      'event_type' => $event_type,
      'date' => $formatted_date,
      'organization' => $event['organization'],
      'days_in_captivity' => $days_in_captivity,
    ];
  }
}

// Sort rows by MLOG number.
usort($rows, function ($a, $b) {
  return $a['mlog_num'] - $b['mlog_num'];
});

// Display table with reordered columns.
echo str_pad("Manatee Nid", 12) . " | "
   . str_pad("Mlog", 20) . " | "
   . str_pad("Name", 20) . " | "
   . str_pad("Animal ID", 15) . " | "
   . str_pad("Event Type", 15) . " | "
   . str_pad("Event Date", 12) . " | "
   . str_pad("Days in Captivity", 17) . " | "
   . "Organization\n";
echo str_repeat("-", 150) . "\n";

foreach ($rows as $row) {
  echo str_pad($row['nid'], 12) . " | "
     . str_pad(substr($row['mlog'], 0, 19), 20) . " | "
     . str_pad(substr($row['name'], 0, 19), 20) . " | "
     . str_pad(substr($row['animal_id'], 0, 14), 15) . " | "
     . str_pad($row['event_type'], 15) . " | "
     . str_pad($row['date'], 12) . " | "
     . str_pad($row['days_in_captivity'] ?? 'N/A', 17) . " | "
     . substr($row['organization'], 0, 40) . "\n";
}

// Output summary.
echo "\nSummary:\n";
echo "Total manatees found: " . count($rows) . "\n";
echo "Excluded deceased manatees: " . count($deceased_manatee_ids) . "\n";
echo "Type B rescue manatees: " . count(array_filter($rows, function ($row) {
  return $row['rescue_type'] === 'B';
})) . "\n";
echo "Manatees with no rescues: " . count(array_filter($rows, function ($row) {
  return $row['rescue_type'] === 'none';
})) . "\n";
