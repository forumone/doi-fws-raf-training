<?php

/**
 * @file
 * Get species including those without rescue events.
 */

// First get all deceased species IDs to exclude them.
$death_query = \Drupal::entityQuery('node')
  ->condition('type', 'species_death')
  ->condition('field_species_ref', NULL, 'IS NOT NULL')
  ->accessCheck(FALSE)
  ->execute();

$deceased_species_ids = [];
if (!empty($death_query)) {
  $death_nodes = \Drupal::entityTypeManager()
    ->getStorage('node')
    ->loadMultiple($death_query);

  foreach ($death_nodes as $death_node) {
    if ($death_node->hasField('field_species_ref') && !$death_node->field_species_ref->isEmpty()) {
      $deceased_species_ids[] = $death_node->field_species_ref->target_id;
    }
  }
}

// Get primary names for all species.
$name_query = \Drupal::entityQuery('node')
  ->condition('type', 'species_name')
  ->condition('field_species_ref', NULL, 'IS NOT NULL')
  ->condition('field_primary', 1)
  ->accessCheck(FALSE)
  ->execute();

$primary_names = [];
if (!empty($name_query)) {
  $name_nodes = \Drupal::entityTypeManager()
    ->getStorage('node')
    ->loadMultiple($name_query);

  foreach ($name_nodes as $name_node) {
    if ($name_node->hasField('field_species_ref') && !$name_node->field_species_ref->isEmpty()) {
      $species_id = $name_node->field_species_ref->target_id;
      if ($name_node->hasField('field_name') && !$name_node->field_name->isEmpty()) {
        $primary_names[$species_id] = $name_node->field_name->value;
      }
    }
  }
}

// Get species IDs for all species.
$species_id_query = \Drupal::entityQuery('node')
  ->condition('type', 'species_id')
  ->condition('field_species_ref', NULL, 'IS NOT NULL')
  ->accessCheck(FALSE)
  ->execute();

$species_ids = [];
if (!empty($species_id_query)) {
  $species_id_nodes = \Drupal::entityTypeManager()
    ->getStorage('node')
    ->loadMultiple($species_id_query);

  foreach ($species_id_nodes as $species_id_node) {
    if ($species_id_node->hasField('field_species_ref') && !$species_id_node->field_species_ref->isEmpty()) {
      $species_id = $species_id_node->field_species_ref->target_id;
      if ($species_id_node->hasField('field_species_ref') && !$species_id_node->field_species_ref->isEmpty()) {
        $species_ids[$species_id] = $species_id_node->field_species_ref->value;
      }
    }
  }
}

// Get all rescue events to determine rescue types.
$rescue_query = \Drupal::entityQuery('node')
  ->condition('type', 'species_rescue')
  ->condition('field_species_ref', NULL, 'IS NOT NULL')
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

  // Group rescues by species ID.
  $species_rescues = [];
  foreach ($rescue_nodes as $rescue_node) {
    if ($rescue_node->hasField('field_species_ref') && !$rescue_node->field_species_ref->isEmpty()) {
      $species_id = $rescue_node->field_species_ref->target_id;
      $date = $rescue_node->field_rescue_date->value;
      $rescue_type = '';
      if ($rescue_node->hasField('field_rescue_type') && !$rescue_node->field_rescue_type->isEmpty()) {
        $rescue_type = $rescue_node->field_rescue_type->entity->getName();
      }

      $species_rescues[$species_id][] = [
        'date' => $date,
        'type' => $rescue_type,
      ];

      // Store Type B rescue dates.
      if ($rescue_type === 'B') {
        if (!isset($type_b_rescue_dates[$species_id]) || $date > $type_b_rescue_dates[$species_id]) {
          $type_b_rescue_dates[$species_id] = $date;
        }
      }
    }
  }

  // Determine most recent rescue type for each species.
  foreach ($species_rescues as $species_id => $rescues) {
    usort($rescues, function ($a, $b) {
      return strcmp($b['date'], $a['date']);
    });
    $rescue_types[$species_id] = $rescues[0]['type'];
  }
}

// Get birth dates for all species.
$birth_dates = [];
$birth_query = \Drupal::entityQuery('node')
  ->condition('type', 'species_birth')
  ->condition('field_species_ref', NULL, 'IS NOT NULL')
  ->condition('field_birth_date', NULL, 'IS NOT NULL')
  ->accessCheck(FALSE)
  ->execute();

if (!empty($birth_query)) {
  $birth_nodes = \Drupal::entityTypeManager()
    ->getStorage('node')
    ->loadMultiple($birth_query);

  foreach ($birth_nodes as $birth_node) {
    if ($birth_node->hasField('field_species_ref') && !$birth_node->field_species_ref->isEmpty()) {
      $species_id = $birth_node->field_species_ref->target_id;
      $birth_dates[$species_id] = $birth_node->field_birth_date->value;
    }
  }
}

// Define our event types and their date fields.
$event_types = [
  'species_birth' => 'field_birth_date',
  'species_rescue' => 'field_rescue_date',
  'transfer' => 'field_transfer_date',
  'species_release' => 'field_release_date',
];

// Get all species with MLOGs that aren't deceased.
$species_query = \Drupal::entityQuery('node')
  ->condition('type', 'species')
  ->condition('field_number', NULL, 'IS NOT NULL');

// Exclude deceased species.
if (!empty($deceased_species_ids)) {
  $species_query->condition('nid', $deceased_species_ids, 'NOT IN');
}

$species_ids = $species_query->accessCheck(FALSE)->execute();

// Get all events for these species.
$event_nodes = [];
foreach ($event_types as $type => $date_field) {
  $query = \Drupal::entityQuery('node')
    ->condition('type', $type)
    ->condition('field_species_ref', $species_ids, 'IN')
    ->condition('field_species_ref', NULL, 'IS NOT NULL')
    ->condition($date_field, NULL, 'IS NOT NULL')
    ->accessCheck(FALSE);

  $results = $query->execute();

  if (!empty($results)) {
    $nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadMultiple($results);

    foreach ($nodes as $node) {
      if ($node->hasField('field_species_ref') && !$node->field_species_ref->isEmpty()) {
        $species_id = $node->field_species_ref->target_id;
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

        $event_nodes[$species_id][] = [
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

// Process events to find most recent per species.
$most_recent_events = [];
foreach ($species_ids as $species_id) {
  if (isset($event_nodes[$species_id])) {
    // Sort events by date descending.
    usort($event_nodes[$species_id], function ($a, $b) {
      return strcmp($b['date'], $a['date']);
    });

    // Skip if most recent event is a release.
    if ($event_nodes[$species_id][0]['type'] !== 'species_release') {
      $most_recent_events[$species_id] = $event_nodes[$species_id][0];
    }
  }
}

// Load all qualifying species.
$species = \Drupal::entityTypeManager()
  ->getStorage('node')
  ->loadMultiple($species_ids);

// Get current date for days in captivity calculation.
$current_date = new DateTime();

// Prepare data for sorting.
$rows = [];
foreach ($species as $specie_entity) {
  // Skip if the most recent event is a release.
  if (!isset($most_recent_events[$specie_entity->id()])) {
    continue;
  }

  $event = $most_recent_events[$specie_entity->id()];

  // Get number value.
  $number = "N/A";
  $number_num = PHP_INT_MAX;
  if ($specie_entity->hasField('field_number') && !$specie_entity->field_number->isEmpty()) {
    $number_value = $specie_entity->get('field_number')->getValue();
    $number = $number_value[0]['value'] ?? "N/A";
    if (preg_match('/(\d+)/', $number, $matches)) {
      $number_num = intval($matches[0]);
    }
  }

  // Format event type for display.
  $event_type = str_replace('species_', '', $event['type']);
  $event_type = str_replace('_', ' ', $event_type);
  $event_type = ucfirst($event_type);

  // Format date.
  $date = new DateTime($event['date']);
  $formatted_date = $date->format('Y-m-d');

  // Get primary name if it exists.
  $name = $primary_names[$specie_entity->id()] ?? '';

  // Get species ID if it exists.
  $species_id = $species_ids[$specie_entity->id()] ?? '';

  // Get rescue type if it exists, or mark as none.
  $rescue_type = $rescue_types[$specie_entity->id()] ?? 'none';

  // Calculate days in captivity.
  $captivity_date = NULL;
  if (isset($type_b_rescue_dates[$specie_entity->id()])) {
    // Use Type B rescue date if available.
    $captivity_date = new DateTime($type_b_rescue_dates[$specie_entity->id()]);
  }
  elseif (isset($birth_dates[$specie_entity->id()])) {
    // Use birth date if no Type B rescue.
    $captivity_date = new DateTime($birth_dates[$specie_entity->id()]);
  }

  $days_in_captivity = NULL;
  if ($captivity_date) {
    $interval = $current_date->diff($captivity_date);
    $days_in_captivity = $interval->days;
  }

  // Only include if either:
  // 1. The most recent rescue is type B
  // 2. There are no rescues associated with this species.
  if ($rescue_type === 'B' || $rescue_type === 'none') {
    $rows[] = [
      'nid' => $specie_entity->id(),
      'number' => $number,
      'number_num' => $number_num,
      'name' => $name,
      'species_id' => $species_id,
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
  return $a['number_num'] - $b['number_num'];
});

// Display table with reordered columns.
echo str_pad("Manatee Nid", 12) . " | "
   . str_pad("Number", 20) . " | "
   . str_pad("Name", 20) . " | "
   . str_pad("Species ID", 15) . " | "
   . str_pad("Event Type", 15) . " | "
   . str_pad("Event Date", 12) . " | "
   . str_pad("Days in Captivity", 17) . " | "
   . "Organization\n";
echo str_repeat("-", 150) . "\n";

foreach ($rows as $row) {
  echo str_pad($row['nid'], 12) . " | "
     . str_pad(substr($row['number'], 0, 19), 20) . " | "
     . str_pad(substr($row['name'], 0, 19), 20) . " | "
     . str_pad(substr($row['species_id'], 0, 14), 15) . " | "
     . str_pad($row['event_type'], 15) . " | "
     . str_pad($row['date'], 12) . " | "
     . str_pad($row['days_in_captivity'] ?? 'N/A', 17) . " | "
     . substr($row['organization'], 0, 40) . "\n";
}

// Output summary.
echo "\nSummary:\n";
echo "Total species found: " . count($rows) . "\n";
echo "Excluded deceased species: " . count($deceased_species_ids) . "\n";
echo "Type B rescue species: " . count(array_filter($rows, function ($row) {
  return $row['rescue_type'] === 'B';
})) . "\n";
echo "Manatees with no rescues: " . count(array_filter($rows, function ($row) {
  return $row['rescue_type'] === 'none';
})) . "\n";
