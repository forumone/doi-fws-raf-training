<?php

/**
 * @file
 * Drush script to import T_Manatee.csv data into manatee content type nodes.
 * Updates existing nodes if they already exist.
 *
 * Usage: drush scr scripts/import_manatee.php.
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
$mlog_to_nid = [];

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
    [$mlog, $sex, $dam, $sire, $rearing, $studbook, $create_by, $create_date, $update_by, $update_date] = $data;

    // Check if node already exists.
    $existing_nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties([
        'type' => 'manatee',
        'field_animal_id' => $mlog,
      ]);

    $node_data = [
      'type' => 'manatee',
      'title' => "Manatee $mlog",
      'field_animal_id' => $mlog,
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
      print("\nUpdating existing manatee node: Manatee $mlog");
      $updated_count++;
    }
    else {
      // Create new node.
      $node_data['uid'] = get_user_id($create_by);
      $node_data['created'] = strtotime($create_date);
      $node = Node::create($node_data);
      print("\nCreating new manatee node: Manatee $mlog");
      $created_count++;
    }

    $node->save();

    // Map 'mlog' to node ID for the second pass.
    $mlog_to_nid[$mlog] = $node->id();

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
    [$mlog, $sex, $dam, $sire, $rearing, $studbook, $create_by, $create_date, $update_by, $update_date] = $data;

    // Load the node by 'mlog'.
    $node_id = $mlog_to_nid[$mlog];
    $node = Node::load($node_id);

    // Update 'field_dam' and 'field_sire' if they exist in the mapping.
    $dam_id = $mlog_to_nid[$dam] ?? NULL;
    $sire_id = $mlog_to_nid[$sire] ?? NULL;

    if ($dam_id) {
      $node->set('field_dam', ['target_id' => $dam_id]);
    }
    if ($sire_id) {
      $node->set('field_sire', ['target_id' => $sire_id]);
    }

    $node->save();
    print("\nUpdated 'field_dam' and 'field_sire' for Manatee $mlog");

  }
  catch (EntityStorageException $e) {
    print("\nError updating 'field_dam' and 'field_sire' for Manatee $mlog: " . $e->getMessage());
    $error_count++;
  }
  catch (Exception $e) {
    print("\nGeneral error updating 'field_dam' and 'field_sire' for Manatee $mlog: " . $e->getMessage());
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
