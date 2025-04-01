<?php

/**
 * @file
 * Script to import flyway terms from a CSV file.
 */

use Drupal\taxonomy\Entity\Term;
use Drush\Drush;

// Get variables from the parent script.
$limit = $GLOBALS['limit'] ?? PHP_INT_MAX;
$update_existing = $GLOBALS['update_existing'] ?? FALSE;

// Define the mapping for the flyways vocabulary.
$mapping = [
  'vid' => 'flyways',
  'csv_file' => 'rcgr_ref_flyways_202503031405.csv',
  'name_field' => 'Flyway',
  'description_field' => '',
  'field_mappings' => [],
  'callback' => function ($row, $column_indices) {
    // Skip empty rows or rows with empty flyway codes.
    if (empty($row) || empty($row[$column_indices['Flyway']])) {
      return FALSE;
    }
    return TRUE;
  },
  'skip_row_callback' => function ($row, $row_number, $column_indices) {
    // Skip empty rows or separator rows.
    if (empty($row) || (count($row) === 1 && empty($row[0]))) {
      return TRUE;
    }

    // Skip rows with empty flyway names.
    if (empty($row[$column_indices['Flyway']])) {
      return TRUE;
    }

    return FALSE;
  },
  // Custom callback to process entity references after the term is created.
  'post_save_callback' => 'post_save_callback',
];

// Run the import.
$stats = import_taxonomy_terms($mapping, $limit, $update_existing);

// Display final results.
Drush::logger()->notice("Flyways taxonomy import completed.");
Drush::logger()->notice("Total rows processed: {$stats['processed']}");
Drush::logger()->notice("Terms created: {$stats['created']}");
Drush::logger()->notice("Terms updated: {$stats['updated']}");
Drush::logger()->notice("Terms skipped: {$stats['skipped']}");
Drush::logger()->notice("Errors: {$stats['errors']}");

/**
 * Custom post-save callback for flyways import.
 *
 * Processes the ST column to create references to state terms.
 *
 * @param \Drupal\taxonomy\Entity\Term $term
 *   The term entity that was saved.
 * @param array $row
 *   The CSV row data.
 * @param array $column_indices
 *   The column indices from the CSV header.
 */
function post_save_callback(Term $term, array $row, array $column_indices) {
  // Process state references only if we have states data.
  if (isset($column_indices['ST']) && !empty($row[$column_indices['ST']])) {
    $state_codes = trim($row[$column_indices['ST']]);

    // Skip if empty.
    if (empty($state_codes)) {
      return;
    }

    // Parse the comma-separated list of state codes.
    $codes = explode(',', $state_codes);
    $state_refs = [];
    $states_count = 0;

    foreach ($codes as $code) {
      $code = trim($code);

      // Skip empty codes.
      if (empty($code)) {
        continue;
      }

      // Look up the state term.
      $states = \Drupal::entityTypeManager()
        ->getStorage('taxonomy_term')
        ->loadByProperties([
          'vid' => 'states',
          'name' => $code,
        ]);

      if (!empty($states)) {
        $state = reset($states);
        $state_refs[] = ['target_id' => $state->id()];
        Drush::logger()->notice("Adding state reference to '$code' (id: {$state->id()})");
        $states_count++;
      }
      else {
        Drush::logger()->warning("State '$code' not found in states vocabulary - skipping reference");
      }
    }

    // Set the references.
    if (!empty($state_refs)) {
      $term->set('field_states_ref', $state_refs);
      $term->save();
      Drush::logger()->notice("Saved term with $states_count state references");
    }
  }
}
