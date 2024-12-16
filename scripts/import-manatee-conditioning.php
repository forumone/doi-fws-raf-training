<?php

/**
 * @file
 * Drush script to import data into species_conditioning content type.
 *
 * Usage: drush scr scripts/import_species_conditioning.php.
 */

use Drupal\Core\Entity\EntityStorageException;
use Drupal\node\Entity\Node;

$csv_file = '../scripts/data/T_Conditioning.csv';
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
    // CSV columns: MLog, CaptivityDate, Cond, Stimulus, Project, Comments, CreateBy, CreateDate, UpdateBy, UpdateDate.
    [$number, $captivity_date, $cond, $stimulus, $project, $comments, $create_by, $create_date, $update_by, $update_date] = $data;

    // Check if species_conditioning node already exists.
    $existing_nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties([
        'type' => 'species_conditioning',
        'field_species_ref' => get_species_node_id($number),
        'field_conditioning_type' => get_conditioning_type_term_id($cond),
      ]);

    // Prepare node data.
    $node_data = [
      'type' => 'species_conditioning',
      'title' => "Manatee Conditioning for MLog $number",
      'field_species_ref' => [
        'target_id' => get_species_node_id($number),
      ],
      // ISO 8601 format.
      'field_captivity_date' => parse_date($captivity_date, FALSE),
      'field_conditioning_type' => [
        'target_id' => get_conditioning_type_term_id($cond),
      ],
      'field_project' => [
        'target_id' => get_project_node_id($project),
      ],
      'field_comments' => $comments,
      'field_stimulus' => $stimulus,
      // UNIX timestamp.
      'created' => strtotime($create_date),
      // UNIX timestamp.
      'changed' => strtotime($update_date),
      'status' => 1,
    ];

    if (!empty($existing_nodes)) {
      // Update existing node.
      $node = reset($existing_nodes);
      foreach ($node_data as $field => $value) {
        $node->set($field, $value);
      }
      print("\nUpdating existing species_conditioning node: MLog $number");
      $updated_count++;
    }
    else {
      // Create new node.
      $node_data['uid'] = get_user_id($create_by);
      $node = Node::create($node_data);
      print("\nCreating new species_conditioning node: MLog $number");
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
 * Helper function to get Conditioning Type taxonomy term ID.
 */
function get_conditioning_type_term_id($conditioning_type) {
  if (empty($conditioning_type)) {
    return NULL;
  }

  $terms = \Drupal::entityTypeManager()
    ->getStorage('taxonomy_term')
    ->loadByProperties([
      'vid' => 'conditioning',
      'name' => $conditioning_type,
    ]);

  if (!empty($terms)) {
    return reset($terms)->id();
  }
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
 * Helper function to get Research Project node ID.
 */
function get_project_node_id($project_id) {
  $nodes = \Drupal::entityTypeManager()
    ->getStorage('node')
    ->loadByProperties([
      'type' => 'research_project',
      'field_project_id' => $project_id,
    ]);

  if (!empty($nodes)) {
    return reset($nodes)->id();
  }

  print("\nError: Research Project node with project id '$project_id' not found.");
  return NULL;
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
