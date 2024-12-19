<?php

/**
 * @file
 * Drush script to import data into the species_death content type.
 *
 * Usage: drush scr scripts/import_species_death.php.
 */

use Drupal\Core\Entity\EntityStorageException;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;

/**
 * Configuration.
 */
$csv_file = '../scripts/data/T_Death.csv';

if (!file_exists($csv_file)) {
  exit("CSV file not found at: $csv_file\n");
}

// Initialize counters.
$row_count = 0;
$created_count = 0;
$updated_count = 0;
$error_count = 0;

// Open CSV file.
if (($handle = fopen($csv_file, 'r')) === FALSE) {
  exit("Error opening CSV file at: $csv_file\n");
}

// Read header row.
$header = fgetcsv($handle);
if ($header === FALSE) {
  exit("CSV file is empty or invalid.\n");
}

// Expected headers for validation (optional).
$expected_headers = [
  'MLog',
  'DeathDate',
  'CauseID',
  'OtherText',
  'Euth',
  'DeathLoc',
  'Org',
  'VetID',
  'Comments',
  'CreateBy',
  'CreateDate',
  'UpdateBy',
  'UpdateDate',
];

$missing_headers = array_diff($expected_headers, $header);
if (!empty($missing_headers)) {
  exit("CSV is missing the following required headers: " . implode(', ', $missing_headers) . "\n");
}

// Process each row.
while (($data = fgetcsv($handle)) !== FALSE) {
  $row_count++;

  // Map CSV columns to variables.
  $row = array_combine($header, $data);
  if ($row === FALSE) {
    print("\nError: Row $row_count has mismatched columns.\n");
    $error_count++;
    continue;
  }

  // Extract variables.
  $number = trim($row['MLog']);
  $death_date = trim($row['DeathDate']);
  $cause_id = trim($row['CauseID']);
  $other_text = trim($row['OtherText']);
  $euth = trim($row['Euth']);
  $death_loc = trim($row['DeathLoc']);
  $org = trim($row['Org']);
  $vet_id = trim($row['VetID']);
  $comments = trim($row['Comments']);
  $create_by = trim($row['CreateBy']);
  $create_date = trim($row['CreateDate']);
  $update_by = trim($row['UpdateBy']);
  $update_date = trim($row['UpdateDate']);

  // Validate required fields.
  if (empty($number) || empty($death_date) || empty($cause_id)) {
    print("\nError: Missing required fields in row $row_count.\n");
    $error_count++;
    continue;
  }

  try {
    // Get the referenced Manatee node ID.
    $species_nid = get_species_node_id($number);
    if (is_null($species_nid)) {
      print("\nError: Manatee with MLog '$number' not found in row $row_count.\n");
      $error_count++;
      continue;
    }

    // Parse death date.
    $parsed_death_date = parse_date($death_date);
    if (is_null($parsed_death_date)) {
      print("\nError: Invalid DeathDate '$death_date' in row $row_count.\n");
      $error_count++;
      continue;
    }

    // Prepare unique identifier for existing node (e.g., MLog + DeathDate).
    $unique_key = "$number|$parsed_death_date";

    // Check if species_death node already exists.
    $existing_nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties([
        'type' => 'species_death',
        'field_species_ref' => $species_nid,
        'field_death_date' => $parsed_death_date,
      ]);

    // Prepare node data.
    $node_data = [
      'type' => 'species_death',
      'title' => "Death Record for MLog $number on $parsed_death_date",
      'field_species_ref' => [
        'target_id' => $species_nid,
      ],
      'field_death_date' => [
        'value' => $parsed_death_date,
        'timezone' => 'UTC',
      ],
      'field_cause_id' => [
        'target_id' => get_death_cause_term_id($cause_id),
      ],
      'field_death_location' => [
        'target_id' => get_death_location_term_id($death_loc),
      ],
      'field_euthanasia' => filter_var($euth, FILTER_VALIDATE_BOOLEAN),
      'field_org' => [
        'target_id' => get_org_term_id($org),
      ],
      'field_other_text' => [
        'value' => $other_text,
        'format' => 'basic_html',
      ],
      'field_comments' => [
        'value' => $comments,
        'format' => 'basic_html',
      ],
      'field_veterinarian' => [
        'target_id' => get_user_id($vet_id),
      ],
      // Timestamps.
      'created' => strtotime($create_date),
      'changed' => strtotime($update_date),
      'status' => 1,
    ];

    if (!empty($existing_nodes)) {
      // Update existing node.
      $node = reset($existing_nodes);
      foreach ($node_data as $field => $value) {
        $node->set($field, $value);
      }
      print("\nUpdating existing species_death node (MLog: $number, DeathDate: $parsed_death_date).\n");
      $updated_count++;
    }
    else {
      // Create new node.
      $node_data['uid'] = get_user_id($create_by);
      $node = Node::create($node_data);
      print("\nCreating new species_death node (MLog: $number, DeathDate: $parsed_death_date).\n");
      $created_count++;
    }

    $node->save();

  }
  catch (EntityStorageException $e) {
    print("\nEntityStorageException on row $row_count: " . $e->getMessage() . "\n");
    $error_count++;
  }
  catch (Exception $e) {
    print("\nException on row $row_count: " . $e->getMessage() . "\n");
    $error_count++;
  }
}

fclose($handle);

// Print summary.
print("\nImport completed:\n");
print("Total rows processed: $row_count\n");
print("Newly created: $created_count\n");
print("Updated: $updated_count\n");
print("Errors: $error_count\n");

/**
 * Helper function to parse and format date values.
 *
 * @param string $date_value
 *   The date string from CSV.
 *
 * @return string|null
 *   The formatted date in 'Y-m-d' format or NULL on failure.
 */
function parse_date($date_value) {
  try {
    if (empty($date_value)) {
      return NULL;
    }

    $date = new DateTime($date_value);
    return $date->format('Y-m-d');
  }
  catch (Exception $e) {
    print("\nException while parsing date '$date_value': " . $e->getMessage() . "\n");
    return NULL;
  }
}

/**
 * Helper function to get Death Causes taxonomy term ID.
 *
 * @param string $cause_name
 *   The name of the death cause.
 *
 * @return int|null
 *   The term ID or NULL on failure.
 */
function get_death_cause_term_id($cause_name) {
  if (empty($cause_name)) {
    return NULL;
  }

  $terms = \Drupal::entityTypeManager()
    ->getStorage('taxonomy_term')
    ->loadByProperties([
  // Ensure the machine name matches.
      'vid' => 'death_cause',
      'name' => $cause_name,
    ]);

  if (!empty($terms)) {
    return reset($terms)->id();
  }

  // Create term if it doesn't exist.
  $term = Term::create([
    'vid' => 'death_cause',
    'name' => $cause_name,
  ]);
  $term->save();
  return $term->id();
}

/**
 * Helper function to get Death Location taxonomy term ID.
 *
 * @param string $location_name
 *   The name of the death location.
 *
 * @return int|null
 *   The term ID or NULL on failure.
 */
function get_death_location_term_id($location_name) {
  if (empty($location_name)) {
    return NULL;
  }

  $terms = \Drupal::entityTypeManager()
    ->getStorage('taxonomy_term')
    ->loadByProperties([
  // Ensure the machine name matches.
      'vid' => 'death_location',
      'name' => $location_name,
    ]);

  if (!empty($terms)) {
    return reset($terms)->id();
  }

  // Create term if it doesn't exist.
  $term = Term::create([
    'vid' => 'death_location',
    'name' => $location_name,
  ]);
  $term->save();
  return $term->id();
}

/**
 * Helper function to get Organizations taxonomy term ID.
 *
 * @param string $org_name
 *   The name of the organization.
 *
 * @return int|null
 *   The term ID or NULL on failure.
 */
function get_org_term_id($org_name) {
  if (empty($org_name)) {
    return NULL;
  }

  $terms = \Drupal::entityTypeManager()
    ->getStorage('taxonomy_term')
    ->loadByProperties([
  // Ensure the machine name matches.
      'vid' => 'org',
      'name' => $org_name,
    ]);

  if (!empty($terms)) {
    return reset($terms)->id();
  }

  // Create term if it doesn't exist.
  $term = Term::create([
    'vid' => 'org',
    'name' => $org_name,
  ]);
  $term->save();
  return $term->id();
}

/**
 * Helper function to get Manatee node ID based on MLog.
 *
 * @param string $number
 *   The MLog identifier.
 *
 * @return int|null
 *   The node ID or NULL if not found.
 */
function get_species_node_id($number) {
  if (empty($number)) {
    return NULL;
  }

  $nodes = \Drupal::entityTypeManager()
    ->getStorage('node')
    ->loadByProperties([
      'type' => 'species',
  // Ensure 'field_number' is the correct field name.
      'field_number' => $number,
    ]);

  if (!empty($nodes)) {
    return reset($nodes)->id();
  }

  return NULL;
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

  // Default user ID if user not found.
  return 1;
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
