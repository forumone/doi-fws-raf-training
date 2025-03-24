<?php

/**
 * @file
 * Script to import state terms from a CSV file.
 */

use Drush\Drush;

// Get variables from the parent script.
$limit = $GLOBALS['limit'] ?? PHP_INT_MAX;
$update_existing = $GLOBALS['update_existing'] ?? FALSE;

// Define the mapping for the states vocabulary.
$mapping = [
  'vid' => 'states',
  'csv_file' => 'rcgr_ref_states_202503031405.csv',
  'name_field' => 'ST',
  'description_field' => 'State',
  'field_mappings' => [
    'Region' => 'field_region',
    'Flyway' => 'field_flyway',
    'tSort' => 'field_tsort',
  ],
  'skip_row_callback' => function ($row, $row_number, $column_indices) {
    // Skip specific rows in the states CSV (rows 2, 3, and 6 are separator rows).
    if (in_array($row_number, [2, 3, 6])) {
      return TRUE;
    }

    // Skip empty rows or rows with '---' or 'All' in the first column.
    if (empty($row) || empty($row[0]) || $row[0] === '---' || $row[0] === 'All') {
      return TRUE;
    }

    return FALSE;
  },
];

// Run the import.
$stats = import_taxonomy_terms($mapping, $limit, $update_existing);

// Display final results.
Drush::logger()->notice("States taxonomy import completed.");
Drush::logger()->notice("Total rows processed: {$stats['processed']}");
Drush::logger()->notice("Terms created: {$stats['created']}");
Drush::logger()->notice("Terms updated: {$stats['updated']}");
Drush::logger()->notice("Terms skipped: {$stats['skipped']}");
Drush::logger()->notice("Errors: {$stats['errors']}");
