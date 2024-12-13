<?php

/**
 * @file
 * Drush script to import data into the species_entangle content type.
 *
 * Usage: drush scr scripts/import_species_entangle.php.
 */

use Drupal\Core\Entity\EntityStorageException;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;

/**
 * Configuration.
 */
// Adjust the path as needed.
$csv_file = '../scripts/data/T_Entangle.csv';

if (!file_exists($csv_file)) {
  exit("CSV file not found at: $csv_file\n");
}

// Initialize counters.
$row_count = 0;
$created_count = 0;
$updated_count = 0;
$error_count = 0;

// Open CSV file.
if (($handle = fopen($csv_file, 'r')) === FALSE) {
  exit("Error opening CSV file at: $csv_file\n");
}

// Read header row.
$header = fgetcsv($handle);
if ($header === FALSE) {
  exit("CSV file is empty or invalid.\n");
}

// Expected headers for validation (optional).
$expected_headers = [
  'MLog',
  'RescueDate',
  'RescuePerDay',
  'Gear',
  'GearDesc',
  'EntLoc',
  'CreateBy',
  'CreateDate',
  'UpdateBy',
  'UpdateDate',
];

$missing_headers = array_diff($expected_headers, $header);
if (!empty($missing_headers)) {
  exit("CSV is missing the following required headers: " . implode(', ', $missing_headers) . "\n");
}

// Process each row.
while (($data = fgetcsv($handle)) !== FALSE) {
  $row_count++;

  // Map CSV columns to variables.
  $row = array_combine($header, $data);
  if ($row === FALSE) {
    print("\nError: Row $row_count has mismatched columns.\n");
    $error_count++;
    continue;
  }

  // Extract variables.
  $number = trim($row['MLog']);
  $rescue_date = trim($row['RescueDate']);
  $rescue_per_day = trim($row['RescuePerDay']);
  $gear = trim($row['Gear']);
  $gear_desc = trim($row['GearDesc']);
  $ent_loc = trim($row['EntLoc']);
  $create_by = trim($row['CreateBy']);
  $create_date = trim($row['CreateDate']);
  $update_by = trim($row['UpdateBy']);
  $update_date = trim($row['UpdateDate']);

  // Validate required fields.
  if (empty($number) || empty($rescue_date) || empty($gear)) {
    print("\nError: Missing required fields in row $row_count.\n");
    $error_count++;
    continue;
  }

  try {
    // Get the referenced Manatee node ID.
    $species_nid = get_species_node_id($number);
    if (is_null($species_nid)) {
      print("\nError: Manatee with MLog '$number' not found in row $row_count.\n");
      $error_count++;
      continue;
    }

    // Parse rescue date.
    $parsed_rescue_date = parse_date($rescue_date);
    if (is_null($parsed_rescue_date)) {
      print("\nError: Invalid RescueDate '$rescue_date' in row $row_count.\n");
      $error_count++;
      continue;
    }

    // Prepare unique identifier for existing node (e.g., MLog + RescueDate).
    $unique_key = "$number|$parsed_rescue_date";

    // Check if species_entangle node already exists.
    $existing_nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties([
        'type' => 'species_entangle',
        'field_species_ref' => $species_nid,
        'field_rescue_date' => $parsed_rescue_date,
      ]);

    // Prepare node data.
    $node_data = [
      'type' => 'species_entangle',
      'title' => "Entanglement Record for MLog $number on $parsed_rescue_date",
      'field_species_ref' => [
        'target_id' => $species_nid,
      ],
      'field_rescue_date' => [
        'value' => $parsed_rescue_date,
        'timezone' => 'UTC',
      ],
      'field_rescue_per_day' => (int) $rescue_per_day,
      'field_gear_type' => [
        'target_id' => get_gear_type_term_id($gear),
      ],
      'field_gear_description' => [
        'value' => $gear_desc,
        'format' => 'basic_html',
      ],
      'field_entanglement_location' => [
        'value' => $ent_loc,
        'format' => 'basic_html',
      ],
      // Timestamps.
      'created' => strtotime($create_date),
      'changed' => strtotime($update_date),
      'status' => 1,
    ];

    // Handle User References.
    $creator_uid = get_user_id($create_by);
    if ($creator_uid !== NULL) {
      $node_data['uid'] = $creator_uid;
    }
    else {
      // Default user ID.
      $node_data['uid'] = 1;
    }

    if (!empty($existing_nodes)) {
      // Update existing node.
      $node = reset($existing_nodes);
      foreach ($node_data as $field => $value) {
        $node->set($field, $value);
      }
      print("\nUpdating existing species_entangle node (MLog: $number, RescueDate: $parsed_rescue_date).\n");
      $updated_count++;
    }
    else {
      // Create new node.
      $node = Node::create($node_data);
      print("\nCreating new species_entangle node (MLog: $number, RescueDate: $parsed_rescue_date).\n");
      $created_count++;
    }

    $node->save();

  }
  catch (EntityStorageException $e) {
    print("\nEntityStorageException on row $row_count: " . $e->getMessage() . "\n");
    $error_count++;
  }
  catch (Exception $e) {
    print("\nException on row $row_count: " . $e->getMessage() . "\n");
    $error_count++;
  }
}

fclose($handle);

// Print summary.
print("\nImport completed:\n");
print("Total rows processed: $row_count\n");
print("Newly created: $created_count\n");
print("Updated: $updated_count\n");
print("Errors: $error_count\n");

/**
 * Helper function to parse and format date values.
 *
 * @param string $date_value
 *   The date string from CSV.
 *
 * @return string|null
 *   The formatted date in 'Y-m-d' format or NULL on failure.
 */
function parse_date($date_value) {
  try {
    if (empty($date_value)) {
      return NULL;
    }

    $date = new DateTime($date_value);
    return $date->format('Y-m-d');
  }
  catch (Exception $e) {
    print("\nException while parsing date '$date_value': " . $e->getMessage() . "\n");
    return NULL;
  }
}

/**
 * Helper function to get Gear Type taxonomy term ID.
 *
 * @param string $gear_name
 *   The name of the gear type.
 *
 * @return int|null
 *   The term ID or NULL on failure.
 */
function get_gear_type_term_id($gear_name) {
  if (empty($gear_name)) {
    return NULL;
  }

  $terms = \Drupal::entityTypeManager()
    ->getStorage('taxonomy_term')
    ->loadByProperties([
  // Ensure the machine name matches.
      'vid' => 'gear_type',
      'name' => $gear_name,
    ]);

  if (!empty($terms)) {
    return reset($terms)->id();
  }

  // Create term if it doesn't exist.
  $term = Term::create([
    'vid' => 'gear_type',
    'name' => $gear_name,
  ]);
  $term->save();
  return $term->id();
}

/**
 * Helper function to get Manatee node ID based on MLog.
 *
 * @param string $number
 *   The MLog identifier.
 *
 * @return int|null
 *   The node ID or NULL if not found.
 */
function get_species_node_id($number) {
  if (empty($number)) {
    return NULL;
  }

  $nodes = \Drupal::entityTypeManager()
    ->getStorage('node')
    ->loadByProperties([
      'type' => 'species',
  // Ensure 'field_number' is the correct field name.
      'field_number' => $number,
    ]);

  if (!empty($nodes)) {
    return reset($nodes)->id();
  }

  return NULL;
}

/**
 * Helper function to get User ID by username.
 *
 * @param string $username
 *   The username.
 *
 * @return int|null
 *   The user ID or NULL if not found.
 */
function get_user_id($username) {
  if (empty($username)) {
    return NULL;
  }

  $users = \Drupal::entityTypeManager()
    ->getStorage('user')
    ->loadByProperties(['name' => $username]);

  if (!empty($users)) {
    return reset($users)->id();
  }

  print("\nWarning: User '$username' not found.\n");
  // You can choose to return NULL or a default user ID, e.g., 1.
  return NULL;
}
