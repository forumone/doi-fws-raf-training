<?php

/**
 * @file
 * Drush script to import data into species_tag content type.
 *
 * Usage: drush scr scripts/import_species_tag.php.
 */

use Drupal\Core\Entity\EntityStorageException;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;

$csv_file = '../scripts/data/T_Tag.csv';
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
    // "MLog","TagType","DateApplied","TagId","Info","Event","EventDate","CreateBy","CreateDate","UpdateBy","UpdateDate".
    [
      $number,
      $tag_type,
      $date_applied,
      $tag_id,
      $info,
      $event,
      $event_date,
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
      'type' => 'species_tag',
      'title' => "Manatee Tag Entry MLog $number",
      'field_species_ref' => get_species_node_id($number),
      'field_tag_type' => [
        'target_id' => get_taxonomy_term_id('tag_type', $tag_type),
      ],
      'field_date_applied' => parse_datetime($date_applied),
      'field_tag_id' => $tag_id,
      'field_tag_info' => [
        'value' => $info,
        'format' => 'full_html',
      ],
      'field_event' => [
        'target_id' => get_taxonomy_term_id('event', $event),
      ],
      'field_event_date' => parse_date($event_date),
      'uid' => get_user_id($create_by),
      'created' => strtotime($create_date),
      'changed' => strtotime($update_date),
      'status' => 1,
    ];

    // Create new node.
    $node = Node::create($node_data);
    print("\nCreating new species_tag node: MLog $number");
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
