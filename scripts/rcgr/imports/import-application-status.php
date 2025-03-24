<?php

/**
 * @file
 * Script to import application status terms from a CSV file.
 */

use Drush\Drush;

// Get variables from the parent script.
$limit = $GLOBALS['limit'] ?? PHP_INT_MAX;
$update_existing = $GLOBALS['update_existing'] ?? FALSE;

// Define the mapping for the application_status vocabulary.
$mapping = [
  'vid' => 'application_status',
  'csv_file' => 'rcgr_ref_application_status_202503031405.csv',
  'name_field' => 'ref_cd',
  'description_field' => 'description',
  'field_mappings' => [
    'program_id' => 'field_program_id',
  ],
  'skip_row_callback' => function ($row, $row_number, $column_indices) {
    // Skip empty rows or separator rows.
    if (empty($row) || (count($row) === 1 && empty($row[0]))) {
      return TRUE;
    }

    // Skip rows with empty status codes.
    if (empty($row[$column_indices['ref_cd']])) {
      return TRUE;
    }

    return FALSE;
  },
];

// Run the import.
$stats = import_taxonomy_terms($mapping, $limit, $update_existing);

// Display final results.
Drush::logger()->notice("Application status taxonomy import completed.");
Drush::logger()->notice("Total rows processed: {$stats['processed']}");
Drush::logger()->notice("Terms created: {$stats['created']}");
Drush::logger()->notice("Terms updated: {$stats['updated']}");
Drush::logger()->notice("Terms skipped: {$stats['skipped']}");
Drush::logger()->notice("Errors: {$stats['errors']}");
