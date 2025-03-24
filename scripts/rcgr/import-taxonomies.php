<?php

/**
 * @file
 * Drush script to import taxonomies from RCGR reference CSV files.
 *
 * Usage: ddev drush --uri=https://rcgr.ddev.site/ scr scripts/rcgr/import-taxonomies.php [limit] [update]
 * The optional [limit] parameter limits how many terms to successfully import per taxonomy.
 * The optional [update] parameter (1 or 0) determines if existing terms should be updated.
 */

use Drupal\taxonomy\Entity\Term;
use Drush\Drush;

// Get the limit and update parameters from command line arguments if provided.
$input = Drush::input();
$args = $input->getArguments();
$limit = isset($args['extra'][1]) ? (int) $args['extra'][1] : PHP_INT_MAX;
$update_existing = isset($args['extra'][2]) ? (bool) $args['extra'][2] : FALSE;

// Get project root directory.
$project_root = dirname(getcwd());
$data_dir = $project_root . '/scripts/rcgr/data/';

// No log file will be created, only console output.
$log_file = NULL;

/**
 * Set up logging.
 */
function log_message($message, $log_file) {
  $timestamp = date('Y-m-d H:i:s');
  $log_message = "[{$timestamp}] {$message}\n";
  // Only output to console, no log file.
  echo $log_message;
}

// Initialize log output.
echo "=== RCGR Taxonomy Import Log ===\n";
log_message("Starting taxonomy import from: {$data_dir}", $log_file);
log_message("Import limit per taxonomy: " . ($limit === PHP_INT_MAX ? "none" : $limit), $log_file);
log_message("Update existing terms: " . ($update_existing ? "Yes" : "No"), $log_file);

// Define taxonomy mappings (CSV file to taxonomy vocabulary).
$taxonomy_mappings = [
  'rcgr_ref_applicant_request_type_202503031405.csv' => [
    'vid' => 'applicant_request_type',
    'name_field' => 'ref_cd',
    'description_field' => 'description',
    'field_mappings' => [
      'program_id' => 'field_program_id',
      'region' => 'field_region',
      'site_id' => 'field_site_id',
      'control_program_id' => 'field_control_program_id',
      'control_region' => 'field_control_region',
      'control_site_id' => 'field_control_site_id',
      'dt_create' => 'field_dt_create',
      'dt_update' => 'field_dt_update',
      'create_by' => 'field_create_by',
      'update_by' => 'field_update_by',
      'xml_cd' => 'field_xml_cd',
      'rcf_cd' => 'field_rcf_cd',
    ],
  ],
  'rcgr_ref_application_status_202503031405.csv' => [
    'vid' => 'application_status',
    'name_field' => 'ref_cd',
    'description_field' => 'description',
    'field_mappings' => [
      'program_id' => 'field_program_id',
      // Additional fields are the same as applicant_request_type.
    ],
  ],
  'rcgr_ref_country_202503031405.csv' => [
    'vid' => 'country',
    'name_field' => 'ref_cd',
    'description_field' => 'description',
    'field_mappings' => [
      'sort_control' => 'field_sort_control',
    ],
  ],
  'rcgr_ref_flyways_202503031405.csv' => [
    'vid' => 'flyways',
    'name_field' => 'Flyway',
    'description_field' => NULL,
    'field_mappings' => [
      'ST' => 'field_st',
      'program_id' => 'field_program_id',
    ],
  ],
  'rcgr_ref_registrant_type_202503031405.csv' => [
    'vid' => 'registrant_type',
    'name_field' => 'ref_cd',
    'description_field' => 'description',
    'field_mappings' => [
      'program_id' => 'field_program_id',
      // Additional fields are the same as applicant_request_type.
    ],
  ],
  'rcgr_ref_states_202503031405.csv' => [
    'vid' => 'states',
    'name_field' => 'ST',
    'description_field' => NULL,
    'field_mappings' => [
      'State' => 'field_state',
      'Region' => 'field_region',
      'Flyway' => 'field_flyway',
      'tSort' => 'field_tsort',
    ],
  ],
  'rcgr_ref_list_of_restricted_counties_202503031405.csv' => [
    'vid' => 'restricted_counties',
    'name_field' => 'county_name',
    'description_field' => NULL,
    'field_mappings' => [
      'recno' => 'field_recno',
      'state_cd' => 'field_state_cd',
      'isCountyRestricted' => 'field_iscountyrestricted',
      'program_id' => 'field_program_id',
    ],
  ],
];

// Iterate through each taxonomy mapping and import the terms.
foreach ($taxonomy_mappings as $csv_file => $mapping) {
  $input_file = $data_dir . $csv_file;

  log_message("Processing {$mapping['vid']} terms from {$csv_file}...", $log_file);

  // Open input file.
  $handle = fopen($input_file, 'r');
  if (!$handle) {
    log_message("Error: Could not open input file {$input_file}", $log_file);
    continue;
  }

  // Get header row.
  $header = fgetcsv($handle);
  if (!$header) {
    log_message("Error: Could not read header row from CSV", $log_file);
    fclose($handle);
    continue;
  }

  // Check if name field exists in header.
  if (!in_array($mapping['name_field'], $header)) {
    log_message("Error: Required column '{$mapping['name_field']}' not found in CSV header", $log_file);
    fclose($handle);
    continue;
  }

  // Initialize counters.
  $row_count = 0;
  $success_count = 0;
  $updated_count = 0;
  $error_count = 0;
  $skipped_count = 0;

  // Process data rows.
  while (($data = fgetcsv($handle)) !== FALSE) {
    $row_count++;

    // Check if we've hit our limit of successful imports + updates before processing more.
    if (($success_count + $updated_count) >= $limit) {
      log_message("Import limit of {$limit} for {$mapping['vid']} reached. Stopping.", $log_file);
      break;
    }

    // Create associative array of row data.
    $row = array_combine($header, $data);

    // Check if we have the required name field.
    if (empty($row[$mapping['name_field']])) {
      log_message("Warning: Row {$row_count} missing required name field - skipping", $log_file);
      $skipped_count++;
      continue;
    }

    // Clean and prepare term data.
    $name = trim($row[$mapping['name_field']]);

    // Skip rows with empty names after trimming.
    if (empty($name)) {
      $skipped_count++;
      continue;
    }

    // Set description if applicable.
    $description = '';
    if (!empty($mapping['description_field']) && isset($row[$mapping['description_field']])) {
      $description = trim($row[$mapping['description_field']]);
    }

    // Check if term already exists.
    $existing_terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadByProperties([
        'name' => $name,
        'vid' => $mapping['vid'],
      ]);

    if ($existing_terms) {
      $term = reset($existing_terms);

      if ($update_existing) {
        log_message("Updating existing term '{$name}' in {$mapping['vid']}", $log_file);
        $updated_count++;
      }
      else {
        log_message("Term '{$name}' already exists in {$mapping['vid']} - skipping", $log_file);
        $skipped_count++;
        continue;
      }
    }
    else {
      // Create new term.
      $term = Term::create([
        'name' => $name,
        'vid' => $mapping['vid'],
      ]);
      $success_count++;
    }

    try {
      // Set description.
      if (!empty($description)) {
        $term->setDescription($description);
      }

      // Set field values.
      foreach ($mapping['field_mappings'] as $csv_field => $drupal_field) {
        if (isset($row[$csv_field]) && $row[$csv_field] !== '') {
          $value = $row[$csv_field];

          // Handle special field types.
          if (strpos($drupal_field, 'field_dt_') === 0) {
            // Convert date strings to the format Drupal expects.
            $date = new DateTime($value);
            $term->set($drupal_field, $date->format('Y-m-d\TH:i:s'));
          }
          elseif ($drupal_field === 'field_recno') {
            // Convert numeric values to integers.
            $term->set($drupal_field, (int) $value);
          }
          else {
            // Default handling for string fields.
            $term->set($drupal_field, $value);
          }
        }
      }

      // Save the term.
      $term->save();

    }
    catch (Exception $e) {
      log_message("Error saving term '{$name}' in {$mapping['vid']}: " . $e->getMessage(), $log_file);
      $error_count++;

      if ($existing_terms) {
        $updated_count--;
      }
      else {
        $success_count--;
      }
    }
  }

  fclose($handle);

  // Report statistics for this taxonomy.
  log_message("Completed import for {$mapping['vid']}:", $log_file);
  log_message("  Total rows processed: {$row_count}", $log_file);
  log_message("  Terms created: {$success_count}", $log_file);
  log_message("  Terms updated: {$updated_count}", $log_file);
  log_message("  Terms skipped: {$skipped_count}", $log_file);
  log_message("  Errors: {$error_count}", $log_file);
  log_message("", $log_file);
}

log_message("Taxonomy import completed.", $log_file);
