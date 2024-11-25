<?php

/**
 * @file
 * Drush script to import data into the manatee_name content type.
 *
 * Usage: drush scr scripts/import_manatee_name.php.
 */

use Drupal\Core\Entity\EntityStorageException;
use Drupal\node\Entity\Node;

/**
 * Configuration.
 */
// Adjust the path as needed.
$csv_file = '../scripts/data/T_Manatee_Name.csv';

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
  'Name',
  'Primary',
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
  $mlog = trim($row['MLog']);
  $name = trim($row['Name']);
  $primary = trim($row['Primary']);
  $create_by = trim($row['CreateBy']);
  $create_date = trim($row['CreateDate']);
  $update_by = trim($row['UpdateBy']);
  $update_date = trim($row['UpdateDate']);

  // Validate required fields.
  if (empty($mlog) || empty($name)) {
    print("\nError: Missing required fields (MLog or Name) in row $row_count.\n");
    $error_count++;
    continue;
  }

  try {
    // Get the referenced Manatee node ID.
    $animal_nid = get_manatee_node_id($mlog);
    if (is_null($animal_nid)) {
      print("\nError: Manatee with MLog '$mlog' not found in row $row_count.\n");
      $error_count++;
      continue;
    }

    // Prepare unique identifier for existing node (e.g., MLog + Name).
    $unique_key = "$mlog|$name";

    // Check if manatee_name node already exists.
    $existing_nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties([
        'type' => 'manatee_name',
        'field_animal' => $animal_nid,
        'field_name' => $name,
      ]);

    // Prepare node data.
    $node_data = [
      'type' => 'manatee_name',
      'title' => "Name '$name' for MLog $mlog",
      'field_animal' => [
        'target_id' => $animal_nid,
      ],
      'field_name' => [
        'value' => $name,
      ],
      'field_primary' => filter_var($primary, FILTER_VALIDATE_BOOLEAN),
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
      // Default user ID (usually admin).
      $node_data['uid'] = 1;
    }

    if (!empty($existing_nodes)) {
      // Update existing node.
      $node = reset($existing_nodes);
      foreach ($node_data as $field => $value) {
        $node->set($field, $value);
      }
      print("\nUpdating existing manatee_name node (MLog: $mlog, Name: $name).\n");
      $updated_count++;
    }
    else {
      // Create new node.
      $node = Node::create($node_data);
      print("\nCreating new manatee_name node (MLog: $mlog, Name: $name).\n");
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
 * Helper function to get Manatee node ID based on MLog.
 *
 * @param string $mlog
 *   The MLog identifier.
 *
 * @return int|null
 *   The node ID or NULL if not found.
 */
function get_manatee_node_id($mlog) {
  if (empty($mlog)) {
    return NULL;
  }

  $nodes = \Drupal::entityTypeManager()
    ->getStorage('node')
    ->loadByProperties([
      'type' => 'manatee',
  // Ensure 'field_mlog' is the correct field name.
      'field_mlog' => $mlog,
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

  // Use super admin for 'D'.
  if ($username == 'D') {
    return 1;
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
