<?php

/**
 * @file
 * Script to import registrant type terms from a CSV file.
 */

use Drush\Drush;

// Get variables from the parent script.
$limit = $GLOBALS['limit'] ?? PHP_INT_MAX;
$update_existing = $GLOBALS['update_existing'] ?? FALSE;

// Define the mapping for the registrant_type vocabulary.
$mapping = [
  'vid' => 'registrant_type',
  'csv_file' => 'rcgr_ref_registrant_type_202503031405.csv',
  'name_field' => 'ref_cd',
  'description_field' => 'description',
  'field_mappings' => [],
  'callback' => function ($row, $column_indices) {
    // Skip empty rows or rows with empty request type codes.
    if (empty($row) || empty($row[$column_indices['ref_cd']])) {
      return FALSE;
    }
    return TRUE;
  },
];

// Run the import.
$stats = import_taxonomy_terms($mapping, $limit, $update_existing);

// Display final results.
Drush::logger()->notice("Registrant type taxonomy import completed.");
Drush::logger()->notice("Total rows processed: {$stats['processed']}");
Drush::logger()->notice("Terms created: {$stats['created']}");
Drush::logger()->notice("Terms updated: {$stats['updated']}");
Drush::logger()->notice("Terms skipped: {$stats['skipped']}");
Drush::logger()->notice("Errors: {$stats['errors']}");
