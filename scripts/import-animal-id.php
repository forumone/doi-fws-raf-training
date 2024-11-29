<?php

/**
 * @file
 * Drush script to import data into manatee_animal_id content type.
 *
 * Usage: drush scr scripts/import_manatee_animal_id.php.
 */

use Drupal\Core\Entity\EntityStorageException;
use Drupal\node\Entity\Node;

$csv_file = '../scripts/data/T_Animal_Id.csv';
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
    // CSV columns: AnimalID, MLog, PrimaryID, IDType, CreateBy, CreateDate, UpdateBy, UpdateDate.
    [$animal_id, $mlog, $primary_id, $id_type, $create_by, $create_date, $update_by, $update_date] = $data;

    // Check if manatee_animal_id node already exists.
    $existing_nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties([
        'type' => 'manatee_animal_id',
        'field_animal_id' => $animal_id,
      ]);

    // Prepare node data.
    $node_data = [
      'type' => 'manatee_animal_id',
      'title' => "Animal ID $animal_id",
      'field_animal_id' => $animal_id,
      'field_id_type' => [
        'target_id' => get_id_type_term_id($id_type),
      ],
      'field_animal' => [
        'target_id' => get_manatee_node_id($mlog),
      ],
      'field_primary_id' => $primary_id == '1' ? 1 : 0,
      'changed' => strtotime($update_date),
      'status' => 1,
    ];

    if (!empty($existing_nodes)) {
      // Update existing node.
      $node = reset($existing_nodes);
      foreach ($node_data as $field => $value) {
        $node->set($field, $value);
      }
      print("\nUpdating existing manatee_animal_id node: Animal ID $animal_id");
      $updated_count++;
    }
    else {
      // Create new node.
      $node_data['uid'] = get_user_id($create_by);
      $node_data['created'] = strtotime($create_date);
      $node = Node::create($node_data);
      print("\nCreating new manatee_animal_id node: Animal ID $animal_id");
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
 * Helper function to get ID Type taxonomy term ID.
 */
function get_id_type_term_id($id_type) {
  // Handle empty or unknown ID types.
  if (empty($id_type)) {
    return NULL;
  }

  $terms = \Drupal::entityTypeManager()
    ->getStorage('taxonomy_term')
    ->loadByProperties([
      'vid' => 'id_type',
      'name' => $id_type,
    ]);

  if (!empty($terms)) {
    return reset($terms)->id();
  }
}

/**
 * Helper function to get manatee node ID from MLog.
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

  // If the manatee node doesn't exist, log an error or handle accordingly.
  print("\nError: Manatee node with MLog $mlog not found.");
  return NULL;
}

/**
 * Helper function to get user ID from username.
 */
function get_user_id($username) {
  $users = \Drupal::entityTypeManager()
    ->getStorage('user')
    ->loadByProperties(['name' => $username]);

  if (!empty($users)) {
    return reset($users)->id();
  }
  // Default to user 1 if not found.
  return 1;
}
