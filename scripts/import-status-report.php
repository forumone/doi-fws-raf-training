<?php

/**
 * @file
 * Drush script to import data into status_report content type.
 *
 * Usage: drush scr scripts/import_status_report.php.
 */

use Drupal\Core\Entity\EntityStorageException;
use Drupal\node\Entity\Node;

$csv_file = '../scripts/data/T_Status.csv';
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
    // "MLog","ReqHalfYr","ReqYear","Health","HealthDiag","Bloods","Status",
    // "Time","Weight","EstW","WDate","Length","EstL","LDate","VetID","Org",
    // "CreateBy","CreateDate","UpdateBy","UpdateDate".
    [
      $number,
      $req_half_yr,
      $req_year,
      $health,
      $health_diag,
      $bloods,
      $status,
      $time,
      $weight,
      $est_w,
      $w_date,
      $length,
      $est_l,
      $l_date,
      $vet_id,
      $org,
      $create_by,
      $create_date,
      $update_by,
      $update_date,
    ] = $data;

    // Ensure required fields are present.
    if (empty($number)) {
      throw new Exception("MLog is empty.");
    }

    // Prepare node data.
    $node_data = [
      'type' => 'status_report',
      'title' => "Status Report Entry MLog $number",
      'field_species_ref' => get_species_node_id($number),
      'field_req_half_yr' => $req_half_yr,
      'field_req_year' => is_numeric($req_year) ? (int) $req_year : NULL,
      'field_health' => [
        'target_id' => get_taxonomy_term_id('health', $health),
      ],
      'field_health_diag' => [
        'value' => $health_diag,
        'format' => 'full_html',
      ],
      'field_bloods' => [
        'target_id' => get_taxonomy_term_id('blood', $bloods),
      ],
      'field_status' => [
        'target_id' => get_taxonomy_term_id('release_status', $status),
      ],
      'field_release_time' => [
        'target_id' => get_taxonomy_term_id('release_time', $time),
      ],
      'field_weight' => is_numeric($weight) ? (int) $weight : NULL,
      'field_est_weight' => $est_w,
      'field_weight_date' => parse_datetime($w_date),
      'field_length' => is_numeric($length) ? (int) $length : NULL,
      'field_est_length' => $est_l,
      'field_length_date' => parse_date($l_date),
      'field_vet_id' => $vet_id,
      'field_org' => [
        'target_id' => get_taxonomy_term_id('org', $org),
      ],
      'uid' => get_user_id($create_by),
      'created' => strtotime($create_date),
      'changed' => strtotime($update_date),
      'status' => 1,
    ];

    // Create new node.
    $node = Node::create($node_data);
    print("\nCreating new status_report node: MLog $number");
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
 * Helper function to get taxonomy term ID.
 *
 * @param string $vocabulary
 *   The machine name of the vocabulary.
 * @param string $term_name
 *   The name of the term.
 *
 * @return int|null
 *   The term ID or NULL if not found/created.
 */
function get_taxonomy_term_id($vocabulary, $term_name) {
  if (empty($term_name) || $term_name == 'U') {
    return NULL;
  }

  $terms = \Drupal::entityTypeManager()
    ->getStorage('taxonomy_term')
    ->loadByProperties([
      'vid' => $vocabulary,
      'name' => $term_name,
    ]);

  if (!empty($terms)) {
    return reset($terms)->id();
  }
}

/**
 * Helper function to get User ID by username.
 *
 * @param string $username
 *   The username.
 *
 * @return int
 *   The user ID. Defaults to 1 if not found.
 */
function get_user_id($username) {
  if (empty($username)) {
    // Default user ID.
    return 1;
  }

  $users = \Drupal::entityTypeManager()
    ->getStorage('user')
    ->loadByProperties(['name' => $username]);

  if (!empty($users)) {
    return reset($users)->id();
  }
  // Default user ID.
  return 1;
}

/**
 * Helper function to get Manatee node ID.
 */
function get_species_node_id($number) {
  $nodes = \Drupal::entityTypeManager()
    ->getStorage('node')
    ->loadByProperties([
      'type' => 'species',
      'field_number' => $number,
    ]);

  if (!empty($nodes)) {
    return reset($nodes)->id();
  }

  print("\nError: Manatee node with MLog $number not found.");
  return NULL;
}

/**
 * Helper function to parse and format datetime values (YYYY-MM-DDTHH:MM:SS).
 */
function parse_datetime($datetime_value) {
  try {
    if (empty($datetime_value)) {
      return NULL;
    }

    // Use regex to extract the datetime part.
    if (preg_match('/(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})/', $datetime_value, $matches)) {
      // Combine date and time with 'T' separator.
      return $matches[1] . 'T' . $matches[2];
    }
    else {
      print("\nError parsing datetime: $datetime_value");
      return NULL;
    }
  }
  catch (Exception $e) {
    print("\nException while parsing datetime: " . $e->getMessage());
    return NULL;
  }
}
