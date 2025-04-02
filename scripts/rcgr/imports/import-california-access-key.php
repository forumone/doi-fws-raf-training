<?php

/**
 * @file
 * Script to import California access key terms from a CSV file.
 */

use Drush\Drush;

// Get variables from the parent script.
$limit = $GLOBALS['limit'] ?? PHP_INT_MAX;
$update_existing = $GLOBALS['update_existing'] ?? FALSE;

// Define the mapping for the California access key vocabulary.
$mapping = [
  'vid' => 'california_access_key',
  'csv_file' => 'rcgr_sys_california_access_key_202503031405.csv',
  'name_field' => 'ca_access_key',
  'description_field' => 'comment',
  'field_mappings' => [
    'location_county' => 'field_location_county',
    'hid' => 'field_hid',
  ],
  'callback' => function ($row, $column_indices) {
    // Skip empty rows or rows with empty access keys.
    if (empty($row) || empty($row[$column_indices['ca_access_key']])) {
      return FALSE;
    }
    return TRUE;
  },
];

// Run the import.
$stats = import_taxonomy_terms($mapping, $limit, $update_existing);

// Display final results.
Drush::logger()->notice("California access key taxonomy import completed.");
Drush::logger()->notice("Total rows processed: {$stats['processed']}");
Drush::logger()->notice("Terms created: {$stats['created']}");
Drush::logger()->notice("Terms updated: {$stats['updated']}");
Drush::logger()->notice("Terms skipped: {$stats['skipped']}");
Drush::logger()->notice("Errors: {$stats['errors']}");
