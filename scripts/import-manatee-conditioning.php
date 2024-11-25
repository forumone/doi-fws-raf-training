<?php

/**
 * @file
 * Drush script to import data into manatee_conditioning content type.
 *
 * Usage: drush scr scripts/import_manatee_conditioning.php.
 */

use Drupal\Core\Entity\EntityStorageException;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;

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
    [$mlog, $captivity_date, $cond, $stimulus, $project, $comments, $create_by, $create_date, $update_by, $update_date] = $data;

    // Check if manatee_conditioning node already exists.
    $existing_nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties([
        'type' => 'manatee_conditioning',
        'field_animal' => get_manatee_node_id($mlog),
        'field_conditioning_type' => get_conditioning_type_term_id($cond),
      ]);

    // Prepare node data.
    $node_data = [
      'type' => 'manatee_conditioning',
      'title' => "Manatee Conditioning for MLog $mlog",
      'field_animal' => [
        'target_id' => get_manatee_node_id($mlog),
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
      print("\nUpdating existing manatee_conditioning node: MLog $mlog");
      $updated_count++;
    }
    else {
      // Create new node.
      $node_data['uid'] = get_user_id($create_by);
      $node = Node::create($node_data);
      print("\nCreating new manatee_conditioning node: MLog $mlog");
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

  $term = Term::create([
    'vid' => 'conditioning',
    'name' => $conditioning_type,
  ]);
  $term->save();
  return $term->id();
}

/**
 * Helper function to get Manatee node ID.
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
