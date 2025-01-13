<?php

/**
 * @file
 * Drush script to import data into the 'transfer' content type.
 *
 * Usage: drush scr scripts/import_transfer.php.
 */

use Drupal\Core\Entity\EntityStorageException;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;

// Path to the CSV file.
$csv_file = '../scripts/manatee/data/T_Transfer.csv';
if (!file_exists($csv_file)) {
  exit('CSV file not found at: ' . $csv_file);
}

// Initialize counters.
$row_count = 0;
$created_count = 0;
$error_count = 0;

// Open the CSV file.
$handle = fopen($csv_file, 'r');
if (!$handle) {
  exit('Error opening CSV file.');
}

// Specify CSV parameters.
// Update this if your CSV uses a different delimiter.
$delimiter = ',';
$enclosure = '"';
$escape = '\\';

// Skip the header row.
fgetcsv($handle, 0, $delimiter, $enclosure, $escape);

// Process each row.
while (($data = fgetcsv($handle, 0, $delimiter, $enclosure, $escape)) !== FALSE) {
  $row_count++;

  try {
    // Assign variables based on the CSV columns.
    [
    // "MLog"
      $number,
    // "TransDate"
      $trans_date,
    // "Reason"
      $reason,
    // "FromFac"
      $from_fac,
    // "ToFac"
      $to_fac,
    // "VetID"
      $vet_id,
    // "Weight"
      $weight,
    // "EstW"
      $est_w,
    // "WDate"
      $w_date,
    // "Length"
      $length,
    // "EstL"
      $est_l,
    // "LDate"
      $l_date,
    // "Comments"
      $comments,
    // "CreateBy"
      $create_by,
    // "CreateDate"
      $create_date,
    // "UpdateBy"
      $update_by,
    // "UpdateDate"
      $update_date,
    ] = $data;

    // Prepare node data.
    $node_data = [
      'type' => 'transfer',
      'title' => "Transfer Entry MLog $number",
      'field_species_ref' => get_species_node_id($number),
      'field_transfer_date' => parse_date($trans_date),
      'field_reason' => [
        'target_id' => get_taxonomy_term_id('transfer_reason', $reason),
      ],
      'field_from_facility' => [
        'target_id' => get_taxonomy_term_id('org', $from_fac),
      ],
      'field_to_facility' => [
        'target_id' => get_taxonomy_term_id('org', $to_fac),
      ],
      'field_veterinarian' => get_user_id($vet_id),
      'field_weight' => is_numeric($weight) ? $weight : NULL,
      'field_estimated_weight' => parse_boolean($est_w),
      'field_weight_date' => parse_datetime($w_date),
      'field_length' => is_numeric($length) ? $length : NULL,
      'field_estimated_length' => parse_boolean($est_l),
      'field_length_date' => parse_date($l_date),
      'field_comments' => [
        'value' => $comments,
        'format' => 'full_html',
      ],
      'uid' => get_user_id($create_by),
      'created' => strtotime($create_date),
      'changed' => strtotime($update_date),
      'status' => 1,
    ];

    // Create and save the new node.
    $node = Node::create($node_data);
    print("\nCreating new transfer node: MLog $number");
    $node->save();
    $created_count++;
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

// Close the CSV file.
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

    // Use regex to extract the YYYY-MM-DD part from the date_value.
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
 * Helper function to parse boolean values.
 */
function parse_boolean($value) {
  $value = trim($value);
  if (strtoupper($value) == 'Y') {
    return 1;
  }
  elseif (strtoupper($value) == 'N' || strtoupper($value) == 'U' || $value == '') {
    return 0;
  }
  else {
    // For any other value, return 0.
    return 0;
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
 *   The term ID or NULL if not found or created.
 */
function get_taxonomy_term_id($vocabulary, $term_name) {
  if (empty($term_name) || $term_name == 'U') {
    return NULL;
  }

  $term_name = trim($term_name);

  $terms = \Drupal::entityTypeManager()
    ->getStorage('taxonomy_term')
    ->loadByProperties([
      'vid' => $vocabulary,
      'name' => $term_name,
    ]);

  if (!empty($terms)) {
    return reset($terms)->id();
  }

  // Create the term if it does not exist.
  $term = Term::create([
    'vid' => $vocabulary,
    'name' => $term_name,
  ]);
  $term->save();
  return $term->id();
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

  $username = trim($username);

  $users = \Drupal::entityTypeManager()
    ->getStorage('user')
    ->loadByProperties(['name' => $username]);

  if (!empty($users)) {
    return reset($users)->id();
  }
  // Default user ID if user not found.
  return 1;
}

/**
 * Helper function to get Manatee node ID by MLog.
 *
 * @param int $number
 *   The MLog number.
 *
 * @return int|null
 *   The node ID or NULL if not found.
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
