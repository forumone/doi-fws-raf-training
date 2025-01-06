<?php

/**
 * @file
 * Drush script to import data into species_prerelease and species_release content types.
 *
 * Usage: drush scr scripts/import_species_combined.php
 */

use Drupal\Core\Entity\EntityStorageException;
use Drupal\node\Entity\Node;

// Define CSV files.
$prerelease_csv = '../scripts/data/T_PreRelease.csv';
$release_csv = '../scripts/data/T_Release.csv';

// Check files exist.
if (!file_exists($prerelease_csv)) {
  exit('PreRelease CSV file not found at: ' . $prerelease_csv);
}
if (!file_exists($release_csv)) {
  exit('Release CSV file not found at: ' . $release_csv);
}

// Initialize counters.
$prerelease_created = 0;
$prerelease_updated = 0;
$release_created = 0;
$prerelease_from_release = 0;
$error_count = 0;

// First, process prereleases.
print("\nProcessing prereleases...");
process_prereleases($prerelease_csv);

// Then process releases and create missing prereleases.
print("\nProcessing releases...");
process_releases($release_csv);

// Print final summary.
print("\nImport completed:");
print("\nPrereleases created: $prerelease_created");
print("\nPrereleases updated: $prerelease_updated");
print("\nPrereleases created from release data: $prerelease_from_release");
print("\nReleases created: $release_created");
print("\nErrors: $error_count\n");

/**
 * Process the prereleases CSV file.
 */
function process_prereleases($csv_file) {
  global $prerelease_created, $prerelease_updated, $error_count;

  $handle = fopen($csv_file, 'r');
  if (!$handle) {
    exit('Error opening PreRelease CSV file.');
  }

  // Skip header row.
  fgetcsv($handle);

  while (($data = fgetcsv($handle)) !== FALSE) {
    try {
      [
        $number,
        $entry_date,
        $rel_date,
        $rel_site,
        $state,
        $county,
        $city,
        $waterway,
        $photos,
        $form_complete,
        $cond_stop,
        $bottom_feed,
        $nat_veg,
        $veg_type,
        $months_veg,
        $exp_water,
        $display_type,
        $rel_type,
        $months_exp_water,
        $vet_id,
        $weight,
        $est_w,
        $w_date,
        $length,
        $est_l,
        $l_date,
        $comments,
        $org,
        $status,
        $create_by,
        $create_date,
        $update_by,
        $update_date,
      ] = $data;

      if (empty($number)) {
        throw new Exception("MLog is empty.");
      }

      $node_data = prepare_prerelease_data($data);
      save_prerelease_node($node_data, $number);
    }
    catch (Exception $e) {
      print("\nError processing prerelease: " . $e->getMessage());
      $error_count++;
    }
  }
  fclose($handle);
}

/**
 * Process the releases CSV file.
 */
function process_releases($csv_file) {
  global $release_created, $prerelease_from_release, $error_count;

  $handle = fopen($csv_file, 'r');
  if (!$handle) {
    exit('Error opening Release CSV file.');
  }

  // Skip header row.
  fgetcsv($handle);

  while (($data = fgetcsv($handle)) !== FALSE) {
    try {
      [
        $number,
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

      if (empty($number)) {
        throw new Exception("MLog is empty.");
      }

      // Create release node.
      $release_data = prepare_release_data($data);
      $release_node = Node::create($release_data);
      $release_node->save();
      $release_created++;

      // Check if prerelease exists.
      $existing_prereleases = \Drupal::entityTypeManager()
        ->getStorage('node')
        ->loadByProperties([
          'type' => 'species_prerelease',
          'field_species_ref' => get_species_node_id($number),
        ]);

      // If no prerelease exists, create one from release data.
      if (empty($existing_prereleases)) {
        $prerelease_data = convert_release_to_prerelease($data);
        save_prerelease_node($prerelease_data, $number);
        $prerelease_from_release++;
      }
    }
    catch (Exception $e) {
      print("\nError processing release: " . $e->getMessage());
      $error_count++;
    }
  }
  fclose($handle);
}

/**
 * Prepare prerelease node data from CSV row.
 */
function prepare_prerelease_data($data) {
  [
    $number,
    $entry_date,
    $rel_date,
    $rel_site,
    $state,
    $county,
    $city,
    $waterway,
    $photos,
    $form_complete,
    $cond_stop,
    $bottom_feed,
    $nat_veg,
    $veg_type,
    $months_veg,
    $exp_water,
    $display_type,
    $rel_type,
    $months_exp_water,
    $vet_id,
    $weight,
    $est_w,
    $w_date,
    $length,
    $est_l,
    $l_date,
    $comments,
    $org,
    $status,
    $create_by,
    $create_date,
    $update_by,
    $update_date,
  ] = $data;

  return [
    'type' => 'species_prerelease',
    'title' => "Manatee PreRelease Entry MLog $number",
    'field_species_ref' => get_species_node_id($number),
    'field_release_date' => parse_date($rel_date),
    'field_release_site' => $rel_site,
    'field_state' => ['target_id' => get_taxonomy_term_id('state', $state)],
    'field_county' => ['target_id' => get_taxonomy_term_id('county', $county)],
    'field_city' => $city,
    'field_waterway' => $waterway,
    'field_photos' => filter_boolean($photos),
    'field_form_complete' => filter_boolean($form_complete),
    'field_cond_stop' => filter_boolean($cond_stop),
    'field_bottom_feed' => filter_boolean($bottom_feed),
    'field_nat_veg' => filter_boolean($nat_veg),
    'field_veg_type' => $veg_type,
    'field_months_veg' => ['target_id' => get_taxonomy_term_id('months', $months_veg)],
    'field_exp_water' => filter_boolean($exp_water),
    'field_display_type' => ['target_id' => get_taxonomy_term_id('water', $display_type)],
    'field_rel_type' => ['target_id' => get_taxonomy_term_id('water', $rel_type)],
    'field_months_exp_water' => ['target_id' => get_taxonomy_term_id('months', $months_exp_water)],
    'field_vet_id' => $vet_id,
    'field_weight' => is_numeric($weight) ? (int) $weight : NULL,
    'field_est_weight' => $est_w,
    'field_weight_date' => parse_datetime($w_date),
    'field_length' => is_numeric($length) ? (int) $length : NULL,
    'field_est_length' => $est_l,
    'field_length_date' => parse_date($l_date),
    'field_comments' => ['value' => $comments, 'format' => 'full_html'],
    'field_org' => ['target_id' => get_taxonomy_term_id('org', $org)],
    'created' => strtotime($create_date),
    'changed' => strtotime($update_date),
    'status' => filter_boolean($status) ? 1 : 0,
  ];
}

/**
 * Prepare release node data from CSV row.
 */
function prepare_release_data($data) {
  [
    $number,
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

  return [
    'type' => 'species_release',
    'title' => "Manatee Release Entry MLog $number",
    'field_species_ref' => get_species_node_id($number),
    'field_release_date' => parse_date($rel_date),
    'field_release_site' => $rel_site,
    'field_state' => ['target_id' => get_taxonomy_term_id('state', $state)],
    'field_county' => ['target_id' => get_taxonomy_term_id('county', $county)],
    'field_city' => $city,
    'field_waterway' => $waterway,
    'field_tracker' => $tracker,
    'field_participating_orgs' => ['target_id' => get_taxonomy_term_id('org', $part_org)],
    'field_veterinarian' => get_user_id($vet_id),
    'field_comments' => ['value' => $comments, 'format' => 'full_html'],
    'uid' => get_user_id($create_by),
    'created' => strtotime($create_date),
    'changed' => strtotime($update_date),
    'status' => 1,
  ];
}

/**
 * Convert release data to prerelease format.
 */
function convert_release_to_prerelease($data) {
  [
    $number,
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

  // Create prerelease data using available fields from release.
  return [
    'type' => 'species_prerelease',
    'title' => "Manatee PreRelease Entry MLog $number",
    'field_species_ref' => get_species_node_id($number),
    'field_release_date' => parse_date($rel_date),
    'field_release_site' => $rel_site,
    'field_state' => ['target_id' => get_taxonomy_term_id('state', $state)],
    'field_county' => ['target_id' => get_taxonomy_term_id('county', $county)],
    'field_city' => $city,
    'field_waterway' => $waterway,
    'field_bottom_feed' => filter_boolean($bottom_feed),
    'field_nat_veg' => filter_boolean($nat_veg),
    'field_veg_type' => $veg_type,
    'field_months_veg' => ['target_id' => get_taxonomy_term_id('months', $months_veg)],
    'field_display_type' => ['target_id' => get_taxonomy_term_id('water', $display_type)],
    'field_rel_type' => ['target_id' => get_taxonomy_term_id('water', $rel_type)],
    'field_months_exp_water' => ['target_id' => get_taxonomy_term_id('months', $months_exp_water)],
    'field_vet_id' => $vet_id,
    'field_weight' => is_numeric($weight) ? (int) $weight : NULL,
    'field_est_weight' => $est_w,
    'field_weight_date' => parse_datetime($w_date),
    'field_length' => is_numeric($length) ? (int) $length : NULL,
    'field_est_length' => $est_l,
    'field_length_date' => parse_date($l_date),
    'field_comments' => ['value' => $comments, 'format' => 'full_html'],
    'field_org' => ['target_id' => get_taxonomy_term_id('org', $org)],
    'created' => strtotime($create_date),
    'changed' => strtotime($update_date),
    'status' => 1,
  ];
}

/**
 * Save or update a prerelease node.
 */
function save_prerelease_node($node_data, $number) {
  global $prerelease_created, $prerelease_updated;

  $existing_nodes = \Drupal::entityTypeManager()
    ->getStorage('node')
    ->loadByProperties([
      'type' => 'species_prerelease',
      'field_species_ref' => get_species_node_id($number),
    ]);

  if (!empty($existing_nodes)) {
    // Update existing node
    $node = reset($existing_nodes);
    foreach ($node_data as $field => $value) {
      if ($field != 'type') {
        $node->set($field, $value);
      }
    }
    print("\nUpdating existing species_prerelease node: MLog $number");
    $prerelease_updated++;
  }
  else {
    // Create new node
    $node = Node::create($node_data);
    print("\nCreating new species_prerelease node: MLog $number");
    $prerelease_created++;
  }

  $node->save();
}

/**
 * Helper function to parse and format date values (YYYY-MM-DD).
 */
function parse_date($date_value) {
  try {
    if (empty($date_value)) {
      return NULL;
    }

    if (preg_match('/(\d{4}-\d{2}-\d{2})/', $date_value, $matches)) {
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

    if (preg_match('/(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})/', $datetime_value, $matches)) {
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
  if (empty($value)) {
    return 0;
  }
  
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
    return 0; // Default to false if value is not recognized
  }
}

/**
 * Helper function to get taxonomy term ID.
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
  return NULL;
}

/**
 * Helper function to get User ID by username.
 */
function get_user_id($username) {
  if (empty($username)) {
    return 1;  // Default user ID
  }

  $users = \Drupal::entityTypeManager()
    ->getStorage('user')
    ->loadByProperties(['name' => $username]);

  if (!empty($users)) {
    return reset($users)->id();
  }
  return 1;  // Default user ID if not found
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