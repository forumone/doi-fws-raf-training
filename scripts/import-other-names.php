<?php

/**
 * @file
 * Drush script to import data into other_names content type.
 *
 * Usage: drush scr scripts/import_other_names.php.
 */

use Drupal\Core\Entity\EntityStorageException;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;

$csv_file = '../scripts/data/T_Other_Names.csv';
if (!file_exists($csv_file)) {
  exit('CSV file not found at: ' . $csv_file);
}

// Initialize counters.
$row_count = 0;
$created_count = 0;
$updated_count = 0;
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
    // CSV columns: Name, SystemID, System, CreateBy, CreateDate, UpdateBy, UpdateDate.
    [$name, $system_id, $system, $create_by, $create_date, $update_by, $update_date] = $data;

    // Check if other_names node already exists.
    $existing_nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties([
        'type' => 'other_names',
        'field_name' => $name,
        'field_system_id' => $system_id,
      ]);

    // Prepare node data.
    $node_data = [
      'type' => 'other_names',
      'title' => "Other Name: $name",
      'field_name' => $name,
      'field_system_id' => $system_id,
      'field_system' => [
        'target_id' => get_system_term_id($system),
      ],
      'created' => strtotime($create_date),
      'changed' => strtotime($update_date),
      'status' => 1,
    ];

    if (!empty($existing_nodes)) {
      // Update existing node.
      $node = reset($existing_nodes);
      foreach ($node_data as $field => $value) {
        $node->set($field, $value);
      }
      print("\nUpdating existing other_names node: $name");
      $updated_count++;
    }
    else {
      // Create new node.
      $node_data['uid'] = get_user_id($create_by);
      $node = Node::create($node_data);
      print("\nCreating new other_names node: $name");
      $created_count++;
    }

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
print("\nUpdated: $updated_count");
print("\nErrors: $error_count\n");

/**
 * Helper function to get System taxonomy term ID.
 */
function get_system_term_id($system) {
  if (empty($system)) {
    return NULL;
  }

  $terms = \Drupal::entityTypeManager()
    ->getStorage('taxonomy_term')
    ->loadByProperties([
      'vid' => 'system',
      'name' => $system,
    ]);

  if (!empty($terms)) {
    return reset($terms)->id();
  }

  $term = Term::create([
    'vid' => 'system',
    'name' => $system,
  ]);
  $term->save();
  return $term->id();
}

/**
 * Helper function to get User ID by username.
 */
function get_user_id($username) {
  $users = \Drupal::entityTypeManager()
    ->getStorage('user')
    ->loadByProperties(['name' => $username]);

  if (!empty($users)) {
    return reset($users)->id();
  }
  // Default user ID.
  return 1;
}
