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

// Initialize log output.
Drush::logger()->notice("Starting taxonomy import");
if ($limit !== PHP_INT_MAX) {
  Drush::logger()->notice("Import limit: $limit");
}
if ($update_existing) {
  Drush::logger()->notice("Update mode: enabled");
}

// Define taxonomy mappings (CSV file to taxonomy vocabulary).
$taxonomy_mappings = [
  'rcgr_ref_applicant_request_type_202503031405.csv' => [
    'vid' => 'applicant_request_type',
    'name_field' => 'ref_cd',
    'description_field' => 'description',
    'field_mappings' => [
      'site_id' => 'field_site_id',
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
    ],
  ],
  'rcgr_ref_registrant_type_202503031405.csv' => [
    'vid' => 'registrant_type',
    'name_field' => 'ref_cd',
    'description_field' => 'description',
    'field_mappings' => [
      // Additional fields are the same as applicant_request_type.
    ],
  ],
  'rcgr_ref_states_202503031405.csv' => [
    'vid' => 'states',
    'name_field' => 'ST',
    'description_field' => 'State',
    'field_mappings' => [
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
    ],
  ],
];

// Iterate through each taxonomy mapping and import the terms.
foreach ($taxonomy_mappings as $csv_file => $mapping) {
  $input_file = $data_dir . $csv_file;
  $stats = [
    'created' => 0,
    'updated' => 0,
    'skipped' => 0,
    'errors' => 0,
  ];

  Drush::logger()->notice("Processing {$mapping['vid']} terms from {$csv_file}");

  // Open input file.
  $handle = fopen($input_file, 'r');
  if (!$handle) {
    Drush::logger()->error("Could not open input file {$input_file}");
    continue;
  }

  // Read header row.
  $header = fgetcsv($handle);
  if (!$header) {
    Drush::logger()->error("Could not read header row from {$csv_file}");
    fclose($handle);
    continue;
  }

  // Remove quotes from header values.
  $header = array_map(function ($value) {
    return trim($value, '"');
  }, $header);

  // Find the index of the name field in the header.
  $name_field_index = array_search($mapping['name_field'], $header);
  if ($name_field_index === FALSE) {
    Drush::logger()->error("Required name field '{$mapping['name_field']}' not found in CSV header");
    fclose($handle);
    continue;
  }

  // Create a mapping of CSV column names to their indices.
  $column_indices = array_flip($header);

  // Process each row.
  $row_number = 1;
  while (($row = fgetcsv($handle)) !== FALSE && $stats['created'] < $limit) {
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
      $stats['skipped']++;
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
      $stats['skipped']++;
      continue;
    }

    // Check if term already exists.
    $existing_term = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadByProperties([
        'vid' => $mapping['vid'],
        'name' => $name,
      ]);

    if (!empty($existing_term)) {
      $term = reset($existing_term);

      if (!$update_existing) {
        $stats['skipped']++;
        continue;
      }
    }
    else {
      // Create new term.
      $term = Term::create([
        'vid' => $mapping['vid'],
        'name' => $name,
      ]);
    }

    // Set field values.
    foreach ($mapping['field_mappings'] as $csv_column => $field_name) {
      if (isset($column_indices[$csv_column])) {
        $value = isset($row[$column_indices[$csv_column]]) ? trim($row[$column_indices[$csv_column]]) : '';
        if (!empty($value)) {
          $term->set($field_name, $value);
        }
      }
    }

    try {
      $term->save();
      if (!empty($existing_term)) {
        $stats['updated']++;
      }
      else {
        $stats['created']++;
      }
    }
    catch (\Exception $e) {
      Drush::logger()->error("Error saving term '$name': " . $e->getMessage());
      $stats['errors']++;
    }
  }

  fclose($handle);

  // Log final statistics for this vocabulary.
  Drush::logger()->notice("Import complete for {$mapping['vid']}:");
  Drush::logger()->notice("- Created: {$stats['created']}");
  Drush::logger()->notice("- Updated: {$stats['updated']}");
  Drush::logger()->notice("- Skipped: {$stats['skipped']}");
  Drush::logger()->notice("- Errors: {$stats['errors']}");
}
