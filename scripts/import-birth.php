<?php

/**
 * @file
 * Drush script to import data into manatee_birth content type.
 *
 * Usage: drush scr scripts/import_manatee_birth.php.
 */

use Drupal\Core\Entity\EntityStorageException;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;

$csv_file = '../scripts/data/T_Birth.csv';
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
    // CSV columns: MLog, BirthDate, Health, Conceive, Org, VetID, Comments, Weight, EstW, WDate, Length, EstL, LDate, CreateBy, CreateDate, UpdateBy, UpdateDate.
    [$mlog, $birth_date, $health, $conceive, $org, $vet_id, $comments, $weight, $est_w, $w_date, $length, $est_l, $l_date, $create_by, $create_date, $update_by, $update_date] = $data;

    // Check if manatee_birth node already exists.
    $existing_nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties([
        'type' => 'manatee_birth',
        'field_animal' => $mlog,
      ]);

    // Prepare node data.
    $node_data = [
      'type' => 'manatee_birth',
      'title' => "Manatee Birth Record $mlog",
      'field_animal' => [
        'target_id' => get_manatee_node_id($mlog),
      ],
      // ISO 8601.
      'field_birth_date' => parse_date($birth_date, FALSE),
      'field_health_status' => [
        'target_id' => get_health_status_term_id($health),
      ],
      'field_conceived' => [
        'target_id' => get_conceive_location_term_id($conceive),
      ],
      'field_facility' => $org,
      'field_veterinarian' => [
        'target_id' => get_user_id($vet_id),
      ],
      'field_comments' => $comments,
      'field_weight' => $weight,
      'field_weight_estimated' => $est_w == 'Y' ? 1 : 0,
      // ISO 8601.
      'field_weight_date' => parse_date($w_date, FALSE),
      'field_length' => $length,
      // UNIX timestamp.
      'created' => parse_date($create_date, TRUE),
      // UNIX timestamp.
      'changed' => parse_date($update_date, TRUE),
      'status' => 1,
    ];


    if (!empty($existing_nodes)) {
      // Update existing node.
      $node = reset($existing_nodes);
      foreach ($node_data as $field => $value) {
        $node->set($field, $value);
      }
      print("\nUpdating existing manatee_birth node: MLog $mlog");
      $updated_count++;
    }
    else {
      // Create new node.
      $node_data['uid'] = get_user_id($create_by);
      $node_data['created'] = parse_date($create_date);
      $node = Node::create($node_data);
      print("\nCreating new manatee_birth node: MLog $mlog");
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
 * Helper function to parse and format date values.
 * Returns UNIX timestamp by default, or ISO 8601 if specified.
 */
function parse_date($date_value, $as_timestamp = TRUE) {
  try {
    if (empty($date_value)) {
      return NULL;
    }
    $date = \DateTime::createFromFormat('Y-m-d H:i:s.u', $date_value);
    if ($date === FALSE) {
      // Try without microseconds if the format doesn't match.
      $date = \DateTime::createFromFormat('Y-m-d H:i:s', $date_value);
    }
    if ($date !== FALSE) {
      return $as_timestamp ? $date->getTimestamp() : $date->format('Y-m-d\TH:i:s');
    }
    print("\nError parsing date: $date_value");
    return NULL;
  }
  catch (Exception $e) {
    print("\nException while parsing date: " . $e->getMessage());
    return NULL;
  }
}

/**
 * Helper function to get Health Status taxonomy term ID.
 */
function get_health_status_term_id($health_status) {
  if (empty($health_status)) {
    return NULL;
  }

  $terms = \Drupal::entityTypeManager()
    ->getStorage('taxonomy_term')
    ->loadByProperties([
      'vid' => 'health',
      'name' => $health_status,
    ]);

  if (!empty($terms)) {
    return reset($terms)->id();
  }

  $term = Term::create([
    'vid' => 'health',
    'name' => $health_status,
  ]);
  $term->save();
  return $term->id();
}

/**
 * Helper function to get Conceived Location taxonomy term ID.
 */
function get_conceive_location_term_id($location) {
  if (empty($location)) {
    return NULL;
  }

  $terms = \Drupal::entityTypeManager()
    ->getStorage('taxonomy_term')
    ->loadByProperties([
      'vid' => 'conceived',
      'name' => $location,
    ]);

  if (!empty($terms)) {
    return reset($terms)->id();
  }

  $term = Term::create([
    'vid' => 'conceived',
    'name' => $location,
  ]);
  $term->save();
  return $term->id();
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
  // Default user ID.
  return 1;
}
