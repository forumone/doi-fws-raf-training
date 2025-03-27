<?php

/**
 * @file
 * Script to import restricted counties terms from a CSV file.
 */

use Drush\Drush;
use Drupal\taxonomy\Entity\Term;

// Get variables from the parent script.
$limit = $GLOBALS['limit'] ?? PHP_INT_MAX;
$update_existing = $GLOBALS['update_existing'] ?? FALSE;

// Load CSV data directly and create each term with error handling.
$data_dir = dirname(dirname(__FILE__)) . '/data/';
$csv_file = 'rcgr_ref_list_of_restricted_counties_202503031405.csv';
$input_file = $data_dir . $csv_file;

// Initialize counters.
$stats = [
  'processed' => 0,
  'created' => 0,
  'updated' => 0,
  'skipped' => 0,
  'errors' => 0,
];

// Get the current count of terms.
$term_count_before = \Drupal::entityTypeManager()
  ->getStorage('taxonomy_term')
  ->getQuery()
  ->accessCheck(FALSE)
  ->condition('vid', 'restricted_counties')
  ->count()
  ->execute();

Drush::logger()->notice("Current restricted_counties terms count: {$term_count_before}");
Drush::logger()->notice("Processing restricted_counties terms from {$csv_file}...");

// Open input file.
$handle = fopen($input_file, 'r');
if (!$handle) {
  Drush::logger()->error("Error: Could not open input file {$input_file}");
  return;
}

// Read header row.
$header = fgetcsv($handle);
if (!$header) {
  Drush::logger()->error("Error: Could not read header row from {$csv_file}");
  fclose($handle);
  return;
}

// Remove quotes from header values.
$header = array_map(function ($value) {
  return trim($value, '"');
}, $header);

Drush::logger()->notice("CSV Header columns: " . implode(', ', $header));

// Create a mapping of CSV column names to their indices.
$column_indices = array_flip($header);

// Load state term ids first for reference.
$stateTerms = [];
$stateTermIds = \Drupal::entityTypeManager()
  ->getStorage('taxonomy_term')
  ->getQuery()
  ->accessCheck(FALSE)
  ->condition('vid', 'states')
  ->execute();

if (!empty($stateTermIds)) {
  $stateTermEntities = \Drupal::entityTypeManager()
    ->getStorage('taxonomy_term')
    ->loadMultiple($stateTermIds);

  foreach ($stateTermEntities as $term) {
    $stateTerms[$term->getName()] = $term->id();
  }
}

// Process each row.
$row_number = 1;
while (($row = fgetcsv($handle)) !== FALSE && $stats['processed'] < $limit) {
  $row_number++;
  $stats['processed']++;

  // Remove quotes from values.
  $row = array_map(function ($value) {
    return trim($value, '"');
  }, $row);

  // Skip rows with empty county names.
  if (empty($row[$column_indices['county_name']])) {
    Drush::logger()->notice("Skipping row {$row_number} because county_name is empty");
    $stats['skipped']++;
    continue;
  }

  $county_name = $row[$column_indices['county_name']];
  Drush::logger()->notice("Attempting to create '{$county_name}'");

  // Check if term already exists.
  $existing_terms = \Drupal::entityTypeManager()
    ->getStorage('taxonomy_term')
    ->loadByProperties([
      'vid' => 'restricted_counties',
      'name' => $county_name,
    ]);

  // Only update if specified or create if it doesn't exist.
  if (!empty($existing_terms) && !$update_existing) {
    Drush::logger()->notice("Term '{$county_name}' already exists - skipping");
    $stats['skipped']++;
    continue;
  }

  try {
    // Create or update the term.
    if (empty($existing_terms)) {
      Drush::logger()->notice("Created term entity. Saving without setting any fields to test if it works.");
      $term = Term::create([
        'vid' => 'restricted_counties',
        'name' => $county_name,
      ]);
      // We'll set fields after successfully creating the term.
    }
    else {
      $term = reset($existing_terms);
      Drush::logger()->notice("Updating existing term '{$county_name}'");
    }

    // Save the term before setting any fields to verify term creation works.
    $term->save();

    if (empty($existing_terms)) {
      Drush::logger()->success("Term '{$county_name}' created successfully!");
      $stats['created']++;
    }

    // Now update the fields once we know the term exists.
    $updated = FALSE;

    // Set the record number field.
    if (isset($row[$column_indices['recno']]) && !empty($row[$column_indices['recno']])) {
      $recno = (int) $row[$column_indices['recno']];
      if ($term->hasField('field_recno')) {
        $term->set('field_recno', $recno);
        Drush::logger()->notice("Set field_recno to {$recno}");
        $updated = TRUE;
      }
    }

    // Set the state code field (entity reference).
    if (isset($row[$column_indices['state_cd']]) && !empty($row[$column_indices['state_cd']])) {
      $state_cd = $row[$column_indices['state_cd']];
      if ($term->hasField('field_state_cd')) {
        // Find state term ID if state code exists in states vocabulary.
        if (isset($stateTerms[$state_cd])) {
          $state_term_id = $stateTerms[$state_cd];
          $term->set('field_state_cd', ['target_id' => $state_term_id]);
          Drush::logger()->notice("Set field_state_cd to reference state: {$state_cd} (ID: {$state_term_id})");
          $updated = TRUE;
        }
        else {
          Drush::logger()->warning("State term '{$state_cd}' not found - skipping field_state_cd");
        }
      }
    }

    // Set the program ID field.
    if (isset($row[$column_indices['program_id']]) && !empty($row[$column_indices['program_id']])) {
      $program_id = $row[$column_indices['program_id']];
      if ($term->hasField('field_program_id')) {
        $term->set('field_program_id', $program_id);
        Drush::logger()->notice("Set field_program_id to {$program_id}");
        $updated = TRUE;
      }
    }

    // Set the county name field.
    if ($term->hasField('field_county_name')) {
      $term->set('field_county_name', $county_name);
      Drush::logger()->notice("Set field_county_name to {$county_name}");
      $updated = TRUE;
    }

    // Set the isCountyRestricted field.
    if (isset($row[$column_indices['isCountyRestricted']])) {
      $is_restricted = strtoupper($row[$column_indices['isCountyRestricted']]);
      // Convert to TRUE/FALSE string value.
      $bool_value = ($is_restricted === 'TRUE' || $is_restricted === 'T' || $is_restricted === '1' || $is_restricted === 'Y') ? 'TRUE' : 'FALSE';

      if ($term->hasField('field_iscountyrestricted')) {
        $term->set('field_iscountyrestricted', $bool_value);
        Drush::logger()->notice("Set field_iscountyrestricted to {$bool_value}");
        $updated = TRUE;
      }
    }

    // Save the term again with all the fields set.
    if ($updated) {
      $term->save();
      Drush::logger()->notice("Updated term '{$county_name}' with all fields");
      if (!empty($existing_terms)) {
        $stats['updated']++;
      }
    }
  }
  catch (\Exception $e) {
    Drush::logger()->error("Error processing term '{$county_name}': " . $e->getMessage());
    $stats['errors']++;
  }
}

// Close the file.
fclose($handle);

// Get the current count of terms after import.
$term_count_after = \Drupal::entityTypeManager()
  ->getStorage('taxonomy_term')
  ->getQuery()
  ->accessCheck(FALSE)
  ->condition('vid', 'restricted_counties')
  ->count()
  ->execute();

// Display final results.
Drush::logger()->notice("Restricted counties taxonomy import completed.");
Drush::logger()->notice("Total rows processed: {$stats['processed']}");
Drush::logger()->notice("Terms count before: {$term_count_before}");
Drush::logger()->notice("Terms count after: {$term_count_after}");
Drush::logger()->notice("Actual terms created: " . ($term_count_after - $term_count_before));
Drush::logger()->notice("Terms created according to script: {$stats['created']}");
Drush::logger()->notice("Terms updated: {$stats['updated']}");
Drush::logger()->notice("Terms skipped: {$stats['skipped']}");
Drush::logger()->notice("Errors: {$stats['errors']}");
