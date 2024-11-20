<?php

/**
 * @file
 * Drush script to import T_Manatee.csv data into manatee content type nodes.
 *
 * Usage: drush scr scripts/import_manatee.php
 */

use Drupal\node\Entity\Node;
use Drupal\Core\Entity\EntityStorageException;

$csv_file = '../scripts/data/T_Manatee.csv';
if (!file_exists($csv_file)) {
  exit('CSV file not found at: ' . $csv_file);
}

// Initialize counters
$row_count = 0;
$success_count = 0;
$error_count = 0;

// Open CSV file
$handle = fopen($csv_file, 'r');
if (!$handle) {
  exit('Error opening CSV file.');
}

// Skip header row
fgetcsv($handle);

// Process each row
while (($data = fgetcsv($handle)) !== FALSE) {
  $row_count++;

  try {
    // CSV columns from T_Manatee: MLog,Sex,Dam,Sire,Rearing,StudBook,CreateBy,CreateDate,UpdateBy,UpdateDate
    list($mlog, $sex, $dam, $sire, $rearing, $studbook, $create_by, $create_date, $update_by, $update_date) = $data;

    // Check if node already exists
    $existing_nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties([
        'type' => 'manatee',
        'field_animal_id' => $mlog,
      ]);

    if (!empty($existing_nodes)) {
      print("\nNode already exists for Manatee ID: $mlog - skipping");
      continue;
    }

    // Create node
    $node = Node::create([
      'type' => 'manatee',
      'title' => "Manatee $mlog",
      'field_animal_id' => $mlog,
      'field_sex' => [
        'target_id' => get_sex_term_id($sex), // Helper function needed
      ],
      'field_dam' => [
        'target_id' => $dam ? get_manatee_node_id($dam) : null, // Helper function needed
      ],
      'field_sire' => [
        'target_id' => $sire ? get_manatee_node_id($sire) : null, // Helper function needed
      ],
      'field_rearing' => [
        'target_id' => get_rearing_term_id($rearing), // Helper function needed
      ],
      'field_studbook' => $studbook,
      'uid' => get_user_id($create_by), // Helper function needed
      'created' => strtotime($create_date),
      'changed' => strtotime($update_date),
      'status' => 1,
    ]);

    $node->save();
    $success_count++;
    print("\nImported manatee node: Manatee $mlog");
  } catch (EntityStorageException $e) {
    print("\nError on row $row_count: " . $e->getMessage());
    $error_count++;
  } catch (Exception $e) {
    print("\nGeneral error on row $row_count: " . $e->getMessage());
    $error_count++;
  }
}

fclose($handle);

// Print summary
print("\nImport completed:");
print("\nTotal rows processed: $row_count");
print("\nSuccessfully imported: $success_count");
print("\nErrors: $error_count\n");

/**
 * Helper function to get sex taxonomy term ID.
 */
function get_sex_term_id($sex)
{
  $terms = \Drupal::entityTypeManager()
    ->getStorage('taxonomy_term')
    ->loadByProperties([
      'vid' => 'sex',
      'name' => $sex,
    ]);

  if (!empty($terms)) {
    return reset($terms)->id();
  }
  return null;
}

/**
 * Helper function to get rearing taxonomy term ID.
 */
function get_rearing_term_id($rearing)
{
  $terms = \Drupal::entityTypeManager()
    ->getStorage('taxonomy_term')
    ->loadByProperties([
      'vid' => 'rearing_types',
      'name' => $rearing,
    ]);

  if (!empty($terms)) {
    return reset($terms)->id();
  }
  return null;
}

/**
 * Helper function to get manatee node ID.
 */
function get_manatee_node_id($mlog)
{
  $nodes = \Drupal::entityTypeManager()
    ->getStorage('node')
    ->loadByProperties([
      'type' => 'manatee',
      'field_animal_id' => $mlog,
    ]);

  if (!empty($nodes)) {
    return reset($nodes)->id();
  }
  return null;
}

/**
 * Helper function to get user ID from username.
 */
function get_user_id($username)
{
  $users = \Drupal::entityTypeManager()
    ->getStorage('user')
    ->loadByProperties(['name' => $username]);

  if (!empty($users)) {
    return reset($users)->id();
  }
  return 1; // Default to user 1 if not found
}
