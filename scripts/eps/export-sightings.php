<?php

/**
 * @file
 * Drush script to export sighting nodes to CSV.
 *
 * Usage: drush scr export-observations.php.
 */

use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;

// Get all sighting node IDs.
$query = \Drupal::entityQuery('node')
  ->condition('type', 'sighting')
// Only published nodes.
  ->condition('status', 1)
  ->accessCheck(FALSE);
$nids = $query->execute();

// Define CSV headers based on the field structure.
$headers = [
  'Node ID',
  'Title',
  'Date & Time',
  'Habitat',
  'Location',
  'Method',
  'Notes',
  'Number of Cranes',
  'Spotter Username',
  'Created Date',
];

// Create CSV file.
$filename = '../scripts/sightings_export_' . date('Y-m-d_H-i-s') . '.csv';
$file = fopen($filename, 'w');

// Write headers.
fputcsv($file, $headers);

// Load and write sighting data.
foreach ($nids as $nid) {
  $node = Node::load($nid);

  if ($node) {
    // Get field values, handling potential empty fields.
    $date_time = $node->field_date_time->value ?? '';
    if ($date_time) {
      $date_time = date('Y-m-d H:i:s', strtotime($date_time));
    }

    // For list fields, get the selected value.
    $habitat = $node->field_habitat->value ?? '';

    // For geolocation field, combine lat/long.
    $location = '';
    if ($node->field_location && !$node->field_location->isEmpty()) {
      $lat = $node->field_location->lat;
      $lng = $node->field_location->lng;
      $location = "$lat, $lng";
    }

    $method = $node->field_method->value ?? '';
    $notes = $node->field_notes->value ?? '';
    $bird_count = $node->field_bird_count->value ?? '';

    // Get node owner username.
    $spotter_username = '';
    $owner_id = $node->getOwnerId();
    if ($owner_id) {
      $owner = User::load($owner_id);
      if ($owner) {
        $spotter_username = $owner->getAccountName();
      }
    }

    $created = $node->getCreatedTime() ? date('Y-m-d H:i:s', $node->getCreatedTime()) : '';

    // Prepare row data.
    $row = [
      $node->id(),
      $node->getTitle(),
      $date_time,
      $habitat,
      $location,
      $method,
      $notes,
      $bird_count,
      $spotter_username,
      $created,
    ];

    // Write row to CSV.
    fputcsv($file, $row);
  }
}

// Close file.
fclose($file);

// Output success message.
print("Sightings exported successfully to $filename\n");

// Display total number of sightings exported.
print('Total sightings exported: ' . count($nids));
