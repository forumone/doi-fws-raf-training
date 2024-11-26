<?php

/**
 * @file
 * Drush script to import data into rescue_release content type.
 *
 * Usage: drush scr scripts/import_rescue_release.php.
 */

use Drupal\Core\Entity\EntityStorageException;
use Drupal\node\Entity\Node;

$csv_file = '../scripts/data/T_RescueReleaseLink.csv';
if (!file_exists($csv_file)) {
  exit('CSV file not found at: ' . $csv_file);
}

// Initialize counters.
$row_count = 0;
$created_count = 0;
$error_count = 0;

// Open CSV file.
$handle = fopen($csv_file, 'r');
if (!$handle) {
  exit('Error opening CSV file.');
}

// Skip header row.
fgetcsv($handle);

// Process each row.
while (($data = fgetcsv($handle)) !== FALSE) {
  $row_count++;

  try {
    // CSV columns:
    // "MLog","RescueDate","RescuePerDay","RelDate".
    [
      $mlog,
      $rescue_date,
      $rescue_per_day,
      $rel_date,
    ] = $data;

    // Ensure required fields are present.
    if (empty($mlog)) {
      throw new Exception("MLog is empty.");
    }

    // Prepare node data.
    $node_data = [
      'type' => 'rescue_release',
      'title' => "Rescue Release Entry MLog $mlog",
      'field_animal' => get_manatee_node_id($mlog),
      'field_rescue_date' => parse_date($rescue_date),
      'field_rescue_per_day' => is_numeric($rescue_per_day) ? (int) $rescue_per_day : NULL,
      'field_release_date' => parse_date($rel_date),
    // Default user ID.
      'uid' => 1,
      'created' => time(),
      'changed' => time(),
      'status' => 1,
    ];

    // Create new node.
    $node = Node::create($node_data);
    print("\nCreating new rescue_release node: MLog $mlog");
    $created_count++;

    $node->save();

  }
  catch (EntityStorageException $e) {
    print("\nError on row $row_count: " . $e->getMessage());
    $error_count++;
  }
  catch (Exception $e) {
    print("\nGeneral error on row $row_count: " . $e->getMessage());
    $error_count++;
  }
}

fclose($handle);

// Print summary.
print("\nImport completed:");
print("\nTotal rows processed: $row_count");
print("\nNewly created: $created_count");
print("\nErrors: $error_count\n");

/**
 * Helper function to parse and format date values (YYYY-MM-DD).
 */
function parse_date($date_value) {
  try {
    if (empty($date_value)) {
      // Return NULL if date_value is empty.
      return NULL;
    }

    // Use regex to extract the YYYY-mm-dd part from the date_value.
    if (preg_match('/(\d{4}-\d{2}-\d{2})/', $date_value, $matches)) {
      // Return the matched date.
      return $matches[1];
    }
    else {
      print("\nError parsing date: $date_value");
      return NULL;
    }
  }
  catch (Exception $e) {
    print("\nException while parsing date: " . $e->getMessage());
    return NULL;
  }
}

/**
 * Helper function to get Manatee node ID.
 */
function get_manatee_node_id($mlog) {
  $nodes = \Drupal::entityTypeManager()
    ->getStorage('node')
    ->loadByProperties([
      'type' => 'manatee',
      'field_mlog' => $mlog,
    ]);

  if (!empty($nodes)) {
    return reset($nodes)->id();
  }

  print("\nError: Manatee node with MLog $mlog not found.");
  return NULL;
}
