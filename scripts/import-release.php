<?php

/**
 * @file
 * Drush script to import data into manatee_release content type.
 *
 * Usage: drush scr scripts/import_manatee_release.php.
 */

use Drupal\Core\Entity\EntityStorageException;
use Drupal\node\Entity\Node;

$csv_file = '../scripts/data/T_Release.csv';
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
    // CSV columns:
    // "MLog","RelDate","RelSite","State","County","City","Waterway",
    // "BottomFeed","NatVeg","VegType","MonthsVeg","DisplayType","RelType",
    // "MonthsExpWater","Tracker","PartOrg","VetID","Weight","EstW","WDate",
    // "Length","EstL","LDate","Comments","Org","CreateBy","CreateDate",
    // "UpdateBy","UpdateDate".
    [
      $mlog,
      $rel_date,
      $rel_site,
      $state,
      $county,
      $city,
      $waterway,
      $bottom_feed,
      $nat_veg,
      $veg_type,
      $months_veg,
      $display_type,
      $rel_type,
      $months_exp_water,
      $tracker,
      $part_org,
      $vet_id,
      $weight,
      $est_w,
      $w_date,
      $length,
      $est_l,
      $l_date,
      $comments,
      $org,
      $create_by,
      $create_date,
      $update_by,
      $update_date,
    ] = $data;

    // Ensure required fields are present.
    if (empty($mlog)) {
      throw new Exception("MLog is empty.");
    }

    // Prepare node data.
    $node_data = [
      'type' => 'manatee_release',
      'title' => "Manatee Release Entry MLog $mlog",
      'field_animal' => get_manatee_node_id($mlog),
      'field_release_date' => parse_date($rel_date),
      'field_release_site' => $rel_site,
      'field_state' => [
        'target_id' => get_taxonomy_term_id('state', $state),
      ],
      'field_county' => [
        'target_id' => get_taxonomy_term_id('county', $county),
      ],
      'field_city' => $city,
      'field_waterway' => $waterway,
      'field_bottom_feeding' => filter_boolean($bottom_feed),
      'field_natural_vegetation' => filter_boolean($nat_veg),
      'field_vegetation_type' => $veg_type,
      'field_months_vegetation' => [
        'target_id' => get_taxonomy_term_id('months', $months_veg),
      ],
      'field_display_water_type' => [
        'target_id' => get_taxonomy_term_id('water', $display_type),
      ],
      'field_release_water_type' => [
        'target_id' => get_taxonomy_term_id('water', $rel_type),
      ],
      'field_months_water_exposure' => [
        'target_id' => get_taxonomy_term_id('months', $months_exp_water),
      ],
      'field_tracker' => $tracker,
      'field_participating_orgs' => [
        'target_id' => get_taxonomy_term_id('org', $part_org),
      ],
      'field_veterinarian' => get_user_id($vet_id),
      'field_weight' => is_numeric($weight) ? (int) $weight : NULL,
      'field_weight_estimated' => filter_boolean($est_w),
      'field_weight_date' => parse_date($w_date),
      'field_length' => is_numeric($length) ? (int) $length : NULL,
      'field_length_estimated' => filter_boolean($est_l),
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

    // Create new node.
    $node = Node::create($node_data);
    print("\nCreating new manatee_release node: MLog $mlog");
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
print("\nUpdated: $updated_count");
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

/**
 * Helper function to convert boolean-like values to integer.
 */
function filter_boolean($value) {
  $true_values = ['1', 'true', 't', 'yes', 'y'];
  $false_values = ['0', 'false', 'f', 'no', 'n'];
  $value_lower = strtolower(trim($value));
  if (in_array($value_lower, $true_values, TRUE)) {
    return 1;
  }
  elseif (in_array($value_lower, $false_values, TRUE)) {
    return 0;
  }
  else {
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
  if (empty($term_name)) {
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
