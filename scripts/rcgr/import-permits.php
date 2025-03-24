<?php

/**
 * @file
 * Script to import permits from CSV into Drupal nodes.
 *
 * This script imports permit data from a CSV file and creates/updates
 * nodes in Drupal with proper entity reference fields to taxonomies.
 */

use Drupal\node\Entity\Node;
use Drush\Drush;

// Check if a limit was passed as an argument.
$limit = isset($argv[1]) ? (int) $argv[1] : 0;
$logger = Drush::logger();

// Initialize counters.
$total = 0;
$created = 0;
$updated = 0;
$skipped = 0;
$errors = 0;

// Log the start of the import.
$logger->notice('Starting import for permits');

// Open the CSV file.
$csv_file = dirname(__FILE__) . '/data/rcgr_permit_app_mast_202503031405.csv';
$handle = fopen($csv_file, 'r');

if ($handle === FALSE) {
  $logger->error('Could not open CSV file: ' . $csv_file);
  return;
}

// Read the header row and map column names to indices.
$header = fgetcsv($handle);
$csv_map = array_flip($header);

// Initialize field mapping.
$field_mapping = [
  'permit_no' => 'field_permit_no',
  'version_no' => 'field_version_no',
  'hid' => 'field_hid',
  'dt_create' => 'field_dt_create',
  'dt_update' => 'field_dt_update',
  'create_by' => 'field_create_by',
  'update_by' => 'field_update_by',
  'xml_cd' => 'field_xml_cd',
];

// Initialize taxonomy reference mapping.
$taxonomy_mapping = [
  'program_id' => [
    'field' => 'field_program_id',
    'vocabulary' => 'program',
    'field_match' => 'name',
  ],
  'region' => [
    'field' => 'field_region',
    'vocabulary' => 'states',
    'field_match' => 'field_region.value',
  ],
  'rcf_cd' => [
    'field' => 'field_rcf_cd',
    'vocabulary' => 'status',
    'field_match' => 'name',
  ],
  'registrant_type_cd' => [
    'field' => 'field_registrant_type_cd',
    'vocabulary' => 'registrant_type',
    'field_match' => 'name',
  ],
  'permit_status_cd' => [
    'field' => 'field_permit_status_cd',
    'vocabulary' => 'application_status',
    'field_match' => 'name',
  ],
  'applicant_state' => [
    'field' => 'field_applicant_state',
    'vocabulary' => 'states',
    'field_match' => 'field_state_cd.value',
  ],
];

// Cache for taxonomy terms to avoid repeated lookups.
$_import_terms_cache = [];

/**
 * Helper function to get taxonomy term ID by code.
 *
 * @param string $code
 *   The code to look up.
 * @param string $vocabulary
 *   The vocabulary machine name.
 * @param string $field_match
 *   The field to match on.
 * @param object $logger
 *   The logger object.
 *
 * @return int|null
 *   The term ID or null if not found.
 */
function get_taxonomy_term_id_by_code($code, $vocabulary, $field_match, $logger) {
  global $_import_terms_cache;

  // Check cache first.
  $cache_key = $vocabulary . ':' . $code;
  if (isset($_import_terms_cache[$cache_key])) {
    return $_import_terms_cache[$cache_key];
  }

  // Prepare the query.
  $query = \Drupal::entityQuery('taxonomy_term')
    ->condition('vid', $vocabulary)
    ->accessCheck(FALSE);

  // Add condition based on the field to match.
  if ($field_match === 'name') {
    $query->condition('name', $code);
  }
  else {
    $query->condition($field_match, $code);
  }

  $tids = $query->execute();

  if (!empty($tids)) {
    $tid = reset($tids);
    $_import_terms_cache[$cache_key] = $tid;
    return $tid;
  }

  $logger->warning('Could not find taxonomy term for code: ' . $code . ' in vocabulary: ' . $vocabulary);
  return NULL;
}

// Process each row.
while (($row = fgetcsv($handle)) !== FALSE) {
  $total++;

  // Check for limit.
  if ($limit > 0 && $total > $limit) {
    break;
  }

  // Extract the permit number.
  $permit_no = isset($row[$csv_map['permit_no']]) ? trim($row[$csv_map['permit_no']]) : '';

  // Skip if permit number is empty.
  if (empty($permit_no)) {
    $logger->notice('Skipping row @row: Empty permit number', ['@row' => $total]);
    $skipped++;
    continue;
  }

  // Check if a node with this permit number already exists.
  $query = \Drupal::entityQuery('node')
    ->condition('type', 'permit')
    ->condition('field_permit_no', $permit_no)
    ->accessCheck(FALSE);

  $nids = $query->execute();

  if (empty($nids)) {
    // Create a new node.
    try {
      $node = Node::create([
        'type' => 'permit',
        'title' => 'Permit: ' . $permit_no,
        'status' => 1,
      ]);

      // Set all mapped fields.
      foreach ($field_mapping as $csv_field => $drupal_field) {
        if (isset($csv_map[$csv_field]) && isset($row[$csv_map[$csv_field]])) {
          $value = trim($row[$csv_map[$csv_field]]);

          // Handle special cases.
          if ($drupal_field === 'field_dt_create' || $drupal_field === 'field_dt_update') {
            if (!empty($value)) {
              // Convert date to ISO format.
              $date = strtotime($value);
              if ($date) {
                $node->set($drupal_field, date('Y-m-d\TH:i:s', $date));
              }
            }
          }
          // Handle version_no as integer.
          elseif ($drupal_field === 'field_version_no' || $drupal_field === 'field_recno' || $drupal_field === 'field_report_year' || $drupal_field === 'field_is_removed') {
            if (is_numeric($value)) {
              $node->set($drupal_field, (int) $value);
            }
          }
          // Handle normal string fields.
          else {
            // For string fields, set the property correctly.
            $node->set($drupal_field, $value);
          }
        }
      }

      // Set taxonomy reference fields.
      foreach ($taxonomy_mapping as $csv_field => $mapping) {
        if (isset($csv_map[$csv_field]) && isset($row[$csv_map[$csv_field]])) {
          $code = trim($row[$csv_map[$csv_field]]);
          if (!empty($code)) {
            $term_id = get_taxonomy_term_id_by_code($code, $mapping['vocabulary'], $mapping['field_match'], $logger);
            if ($term_id) {
              $node->set($mapping['field'], ['target_id' => $term_id]);
            }
          }
        }
      }

      // Handle special case for applicant_state which is in the CSV but has a different field name.
      if (isset($csv_map['applicant_state']) && isset($row[$csv_map['applicant_state']])) {
        $state_code = trim($row[$csv_map['applicant_state']]);
        if (!empty($state_code)) {
          $term_id = get_taxonomy_term_id_by_code($state_code, 'states', 'field_state_cd.value', $logger);
          if ($term_id) {
            $node->set('field_applicant_state', ['target_id' => $term_id]);
          }
        }
      }

      // Set person name from applicant names if available.
      $first_name = isset($row[$csv_map['applicant_first_name']]) ? trim($row[$csv_map['applicant_first_name']]) : '';
      $last_name = isset($row[$csv_map['applicant_last_name']]) ? trim($row[$csv_map['applicant_last_name']]) : '';
      $person_name = '';

      if (!empty($first_name) || !empty($last_name)) {
        $person_name = trim($first_name . ' ' . $last_name);
        $node->set('field_person_name', $person_name);
      }

      // Set report year from dt_permit_issued if available.
      $dt_permit_issued = isset($row[$csv_map['dt_permit_issued']]) ? trim($row[$csv_map['dt_permit_issued']]) : '';
      if (!empty($dt_permit_issued)) {
        $date = strtotime($dt_permit_issued);
        if ($date) {
          $year = date('Y', $date);
          $node->set('field_report_year', (int) $year);
        }
      }

      // Set isRemoved to FALSE by default.
      $node->set('field_isremoved', FALSE);

      // Set recno as an auto-increment value.
      $node->set('field_recno', $total);

      // Save the node.
      $node->save();
      $logger->notice('Created permit node: ' . $permit_no);
      $created++;
    }
    catch (\Exception $e) {
      $logger->error('Error creating permit node: ' . $permit_no . '. Error: ' . $e->getMessage());
      $errors++;
    }
  }
  else {
    // Update existing node.
    try {
      $nid = reset($nids);
      $node = Node::load($nid);

      // Track updates.
      $updated_fields = 0;

      // Set all mapped fields.
      foreach ($field_mapping as $csv_field => $drupal_field) {
        if (isset($csv_map[$csv_field]) && isset($row[$csv_map[$csv_field]])) {
          $value = trim($row[$csv_map[$csv_field]]);

          // Handle special cases.
          if ($drupal_field === 'field_dt_create' || $drupal_field === 'field_dt_update') {
            if (!empty($value)) {
              // Convert date to ISO format.
              $date = strtotime($value);
              if ($date) {
                $new_value = date('Y-m-d\TH:i:s', $date);
                if ($node->get($drupal_field)->value !== $new_value) {
                  $node->set($drupal_field, $new_value);
                  $updated_fields++;
                }
              }
            }
          }
          // Handle version_no as integer.
          elseif ($drupal_field === 'field_version_no' || $drupal_field === 'field_recno' || $drupal_field === 'field_report_year' || $drupal_field === 'field_is_removed') {
            if (is_numeric($value) && (int) $node->get($drupal_field)->value !== (int) $value) {
              $node->set($drupal_field, (int) $value);
              $updated_fields++;
            }
          }
          // Handle normal string fields.
          elseif ($node->get($drupal_field)->value !== $value) {
            $node->set($drupal_field, $value);
            $updated_fields++;
          }
        }
      }

      // Set taxonomy reference fields.
      foreach ($taxonomy_mapping as $csv_field => $mapping) {
        if (isset($csv_map[$csv_field]) && isset($row[$csv_map[$csv_field]])) {
          $code = trim($row[$csv_map[$csv_field]]);
          if (!empty($code)) {
            $term_id = get_taxonomy_term_id_by_code($code, $mapping['vocabulary'], $mapping['field_match'], $logger);

            if ($term_id) {
              $current_value = $node->get($mapping['field'])->target_id;

              if ($current_value != $term_id) {
                $node->set($mapping['field'], ['target_id' => $term_id]);
                $updated_fields++;
              }
            }
          }
        }
      }

      // Handle special case for applicant_state.
      if (isset($csv_map['applicant_state']) && isset($row[$csv_map['applicant_state']])) {
        $state_code = trim($row[$csv_map['applicant_state']]);
        if (!empty($state_code)) {
          $term_id = get_taxonomy_term_id_by_code($state_code, 'states', 'field_state_cd.value', $logger);

          if ($term_id) {
            $current_value = $node->get('field_applicant_state')->target_id;

            if ($current_value != $term_id) {
              $node->set('field_applicant_state', ['target_id' => $term_id]);
              $updated_fields++;
            }
          }
        }
      }

      // Update person name if changed.
      $first_name = isset($row[$csv_map['applicant_first_name']]) ? trim($row[$csv_map['applicant_first_name']]) : '';
      $last_name = isset($row[$csv_map['applicant_last_name']]) ? trim($row[$csv_map['applicant_last_name']]) : '';
      $person_name = '';

      if (!empty($first_name) || !empty($last_name)) {
        $person_name = trim($first_name . ' ' . $last_name);
        if ($node->get('field_person_name')->value !== $person_name) {
          $node->set('field_person_name', $person_name);
          $updated_fields++;
        }
      }

      // Update report year if changed.
      $dt_permit_issued = isset($row[$csv_map['dt_permit_issued']]) ? trim($row[$csv_map['dt_permit_issued']]) : '';
      if (!empty($dt_permit_issued)) {
        $date = strtotime($dt_permit_issued);
        if ($date) {
          $year = date('Y', $date);
          if ((int) $node->get('field_report_year')->value !== (int) $year) {
            $node->set('field_report_year', (int) $year);
            $updated_fields++;
          }
        }
      }

      // Save the node if any fields were updated.
      if ($updated_fields > 0) {
        $node->save();
        $logger->notice('Updated permit node: ' . $permit_no . ' (' . $updated_fields . ' fields)');
        $updated++;
      }
      else {
        $logger->notice('No changes for permit node: ' . $permit_no);
        $skipped++;
      }
    }
    catch (\Exception $e) {
      $logger->error('Error updating permit node: ' . $permit_no . '. Error: ' . $e->getMessage());
      $errors++;
    }
  }
}

// Close the file handle.
fclose($handle);

// Log the final statistics.
$logger->notice('Import complete. Total: ' . $total . ', Created: ' . $created . ', Updated: ' . $updated . ', Skipped: ' . $skipped . ', Errors: ' . $errors);
