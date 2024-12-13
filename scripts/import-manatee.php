<?php

/**
 * @file
 * Drush script to import T_Manatee.csv data into species content type nodes.
 * Updates existing nodes if they already exist.
 *
 * Usage: drush scr scripts/import_species.php.
 */

use Drupal\Core\Entity\EntityStorageException;
use Drupal\node\Entity\Node;

$csv_file = '../scripts/data/T_Manatee.csv';
if (!file_exists($csv_file)) {
  exit('CSV file not found at: ' . $csv_file);
}

// Initialize counters.
$row_count = 0;
$created_count = 0;
$updated_count = 0;
$error_count = 0;

// Arrays to hold data for second pass.
$data_rows = [];
$number_to_nid = [];

// Open CSV file.
$handle = fopen($csv_file, 'r');
if (!$handle) {
  exit('Error opening CSV file.');
}

// Skip header row.
fgetcsv($handle);

// First Pass: Import nodes without 'field_dam' and 'field_sire'.
while (($data = fgetcsv($handle)) !== FALSE) {
  $row_count++;

  // Store the data for the second pass.
  $data_rows[] = $data;

  try {
    // CSV columns from T_Manatee: MLog,Sex,Dam,Sire,Rearing,StudBook,CreateBy,CreateDate,UpdateBy,UpdateDate.
    [$number, $sex, $dam, $sire, $rearing, $studbook, $create_by, $create_date, $update_by, $update_date] = $data;

    // Check if node already exists.
    $existing_nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties([
        'type' => 'species',
        'field_number' => $number,
      ]);

    $node_data = [
      'type' => 'species',
      'title' => "Manatee $number",
      'field_number' => $number,
      'field_sex' => [
        'target_id' => get_sex_term_id($sex),
      ],
      // Exclude 'field_dam' and 'field_sire' for now.
      'field_rearing' => [
        'target_id' => get_rearing_term_id($rearing),
      ],
      'field_studbook' => $studbook,
      'changed' => strtotime($update_date),
      'status' => 1,
    ];

    if (!empty($existing_nodes)) {
      // Update existing node.
      $node = reset($existing_nodes);
      foreach ($node_data as $field => $value) {
        $node->set($field, $value);
      }
      print("\nUpdating existing species node: Manatee $number");
      $updated_count++;
    }
    else {
      // Create new node.
      $node_data['uid'] = get_user_id($create_by);
      $node_data['created'] = strtotime($create_date);
      $node = Node::create($node_data);
      print("\nCreating new species node: Manatee $number");
      $created_count++;
    }

    $node->save();

    // Map 'number' to node ID for the second pass.
    $number_to_nid[$number] = $node->id();

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

// Second Pass: Update 'field_dam' and 'field_sire' fields.
print("\n\nStarting second pass to update 'field_dam' and 'field_sire' fields.\n");

foreach ($data_rows as $data) {
  try {
    [$number, $sex, $dam, $sire, $rearing, $studbook, $create_by, $create_date, $update_by, $update_date] = $data;

    // Load the node by 'number'.
    $node_id = $number_to_nid[$number];
    $node = Node::load($node_id);

    // Update 'field_dam' and 'field_sire' if they exist in the mapping.
    $dam_id = $number_to_nid[$dam] ?? NULL;
    $sire_id = $number_to_nid[$sire] ?? NULL;

    if ($dam_id) {
      $node->set('field_dam', ['target_id' => $dam_id]);
    }
    if ($sire_id) {
      $node->set('field_sire', ['target_id' => $sire_id]);
    }

    $node->save();
    print("\nUpdated 'field_dam' and 'field_sire' for Manatee $number");

  }
  catch (EntityStorageException $e) {
    print("\nError updating 'field_dam' and 'field_sire' for Manatee $number: " . $e->getMessage());
    $error_count++;
  }
  catch (Exception $e) {
    print("\nGeneral error updating 'field_dam' and 'field_sire' for Manatee $number: " . $e->getMessage());
    $error_count++;
  }
}

// Print summary.
print("\nImport completed:");
print("\nTotal rows processed: $row_count");
print("\nNewly created: $created_count");
print("\nUpdated: $updated_count");
print("\nErrors: $error_count\n");

/**
 * Helper function to get sex taxonomy term ID.
 */
function get_sex_term_id($sex) {
  $terms = \Drupal::entityTypeManager()
    ->getStorage('taxonomy_term')
    ->loadByProperties([
      'vid' => 'sex',
      'name' => $sex,
    ]);

  if (!empty($terms)) {
    return reset($terms)->id();
  }
  return NULL;
}

/**
 * Helper function to get rearing taxonomy term ID.
 */
function get_rearing_term_id($rearing) {
  $terms = \Drupal::entityTypeManager()
    ->getStorage('taxonomy_term')
    ->loadByProperties([
      'vid' => 'rearing',
      'name' => $rearing,
    ]);

  if (!empty($terms)) {
    return reset($terms)->id();
  }
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
