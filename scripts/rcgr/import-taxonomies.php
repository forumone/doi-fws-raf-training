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
    'description_field' => 'State',
    'field_mappings' => [
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
      // Commenting out state_cd reference field temporarily
      // 'state_cd' => 'field_state_cd',.
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

  // Read header row.
  $header = fgetcsv($handle);
  if (!$header) {
    log_message("Error: Could not read header row from {$csv_file}", $log_file);
    continue;
  }

  // Remove quotes from header values.
  $header = array_map(function ($value) {
    return trim($value, '"');
  }, $header);

  log_message("CSV Header columns: " . implode(', ', $header), $log_file);

  // Find the index of the name field in the header.
  $name_field_index = array_search($mapping['name_field'], $header);
  if ($name_field_index === FALSE) {
    log_message("Error: Required name field '{$mapping['name_field']}' not found in CSV header", $log_file);
    continue;
  }

  // Create a mapping of CSV column names to their indices.
  $column_indices = array_flip($header);

  // Initialize counters.
  $created = 0;
  $skipped = 0;
  $errors = 0;

  // Process each row.
  $row_number = 1;
  while (($row = fgetcsv($handle)) !== FALSE) {
    $row_number++;

    // Remove quotes from values.
    $row = array_map(function ($value) {
      return trim($value, '"');
    }, $row);

    // Skip empty rows, separator rows, or rows with empty names.
    if (empty($row) || (count($row) === 1 && empty($row[0])) ||
        (isset($row[0]) && strpos($row[0], '---') !== FALSE) ||
        (isset($row[0]) && strpos($row[0], 'All') !== FALSE) ||
        ($mapping['vid'] === 'states' && in_array($row_number, [2, 3, 6]))) {
      Drush::logger()->warning("Warning: Skipping empty, separator, or excluded row");
      continue;
    }

    // Determine the name value based on the vocabulary.
    switch ($mapping['vid']) {
      case 'states':
        $name = $row[$column_indices['ST']];
        break;

      case 'flyways':
        $name = $row[$column_indices['Flyway']];
        break;

      case 'restricted_counties':
        $name = $row[$column_indices['county_name']];
        break;

      default:
        $name = $row[$column_indices[$mapping['name_field']]];
    }

    // Skip empty rows, separator rows, or rows with empty names.
    if (empty($name) || $name === '---' || $name === 'All') {
      Drush::logger()->warning("Warning: Skipping empty, separator, or excluded row");
      continue;
    }

    // Log field values being processed.
    Drush::logger()->notice("Processing field values for term '$name':");
    foreach ($mapping['field_mappings'] as $csv_column => $field_name) {
      if (isset($column_indices[$csv_column])) {
        $value = isset($row[$column_indices[$csv_column]]) ? trim($row[$column_indices[$csv_column]]) : '';
        Drush::logger()->notice("  $csv_column => $field_name: '$value'");
      }
    }

    // Check if term already exists.
    $existing_term = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadByProperties([
        'vid' => $mapping['vid'],
        'name' => $name,
      ]);

    if (!empty($existing_term)) {
      Drush::logger()->warning("Term '$name' already exists in {$mapping['vid']} - skipping");

      // Show field values for existing term.
      $term = reset($existing_term);
      Drush::logger()->notice("Field values for term '$name':");
      foreach ($mapping['field_mappings'] as $csv_column => $field_name) {
        if (isset($column_indices[$csv_column])) {
          $value = isset($row[$column_indices[$csv_column]]) ? trim($row[$column_indices[$csv_column]]) : '';
          Drush::logger()->notice("  $csv_column => $field_name: '$value'");
        }
      }
      continue;
    }

    // Create new term.
    $term = Term::create([
      'vid' => $mapping['vid'],
      'name' => $name,
      'langcode' => 'en',
    ]);

    // Set description if available.
    if (isset($column_indices[$mapping['description_field']]) && !empty($row[$column_indices[$mapping['description_field']]])) {
      $term->setDescription(trim($row[$column_indices[$mapping['description_field']]]));
    }

    // Set field values.
    foreach ($mapping['field_mappings'] as $csv_column => $field_name) {
      if (isset($column_indices[$csv_column])) {
        // Get the field value based on the field name and CSV column.
        $value = isset($row[$column_indices[$csv_column]]) ? trim($row[$column_indices[$csv_column]]) : '';

        // Skip empty values.
        if (empty($value)) {
          continue;
        }

        // Debug logs for values.
        Drush::logger()->notice("Setting field $field_name with value '$value'");

        // Handle field values based on field type.
        try {
          // Get the field definition.
          $field_definition = $term->getFieldDefinition($field_name);
          if (!$field_definition) {
            Drush::logger()->warning("Warning: Field definition not found for $field_name");
            continue;
          }

          // Debug the field type.
          $field_type = $field_definition->getType();
          Drush::logger()->notice("Field $field_name is of type: $field_type");

          // Set the field value based on its type.
          switch ($field_type) {
            case 'string':
              // Ensure string values are properly formatted.
              $value = (string) $value;
              Drush::logger()->notice("Setting $field_name to string value: '$value'");
              $term->set($field_name, $value);
              break;

            case 'integer':
              // Handle integer fields.
              $int_value = (int) $value;
              Drush::logger()->notice("Setting $field_name integer value: '$int_value'");
              $term->set($field_name, $int_value);
              break;

            case 'entity_reference':
              // Handle entity references.
              $handler_settings = $field_definition->getSetting('handler_settings');
              $target_bundles = $handler_settings['target_bundles'] ?? [];
              $vocabulary = !empty($target_bundles) ? key($target_bundles) : '';

              if (empty($vocabulary)) {
                Drush::logger()->warning("Warning: Could not determine target vocabulary for $field_name");
                continue;
              }

              Drush::logger()->notice("Looking for referenced term '$value' in vocabulary '$vocabulary'");

              // Special handling for field_state_cd to debug.
              if ($field_name === 'field_state_cd') {
                Drush::logger()->notice("Debug: state_cd reference lookup for '$value'");

                // Try to load and display all matching terms.
                $query = \Drupal::entityQuery('taxonomy_term')
                  ->condition('vid', $vocabulary);
                $tids = $query->execute();

                if (empty($tids)) {
                  Drush::logger()->warning("Debug: No terms found in '$vocabulary' vocabulary");
                }
                else {
                  Drush::logger()->notice("Debug: Found " . count($tids) . " terms in '$vocabulary' vocabulary");

                  // Load a few sample terms to check.
                  $terms = \Drupal::entityTypeManager()
                    ->getStorage('taxonomy_term')
                    ->loadMultiple(array_slice($tids, 0, 5));

                  foreach ($terms as $term) {
                    Drush::logger()->notice("Debug: Sample term: '" . $term->getName() . "' (id: " . $term->id() . ")");
                  }
                }
              }

              $referenced_term = \Drupal::entityTypeManager()
                ->getStorage('taxonomy_term')
                ->loadByProperties([
                  'vid' => $vocabulary,
                  'name' => $value,
                ]);

              if (!empty($referenced_term)) {
                $referenced_term = reset($referenced_term);
                $term->set($field_name, ['target_id' => $referenced_term->id()]);
                Drush::logger()->notice("Set $field_name reference to term '{$referenced_term->getName()}' (id: {$referenced_term->id()})");
              }
              else {
                Drush::logger()->warning("Referenced term '$value' not found in $vocabulary vocabulary - skipping field");
                continue;
              }
              break;

            default:
              Drush::logger()->warning("Warning: Unsupported field type {$field_definition->getType()} for $field_name");
              continue;
          }
        }
        catch (\Exception $e) {
          Drush::logger()->warning("Warning: Could not set value '$value' for field $field_name: " . $e->getMessage());
          continue;
        }
      }
    }

    try {
      $term->save();
      Drush::logger()->success("Created term '$name' in {$mapping['vid']} vocabulary");
      $created++;
    }
    catch (\Exception $e) {
      Drush::logger()->error("Error creating term '$name' in {$mapping['vid']} vocabulary: " . $e->getMessage());
      $errors++;
    }
  }

  fclose($handle);

  // Report statistics for this taxonomy.
  log_message("Completed import for {$mapping['vid']}:", $log_file);
  log_message("  Total rows processed: {$row_number}", $log_file);
  log_message("  Terms created: {$created}", $log_file);
  log_message("  Terms skipped: {$skipped}", $log_file);
  log_message("  Errors: {$errors}", $log_file);
  log_message("", $log_file);
}

log_message("Taxonomy import completed.", $log_file);
