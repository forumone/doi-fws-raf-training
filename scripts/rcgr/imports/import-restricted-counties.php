<?php

/**
 * @file
 * Script to import restricted counties terms from a CSV file.
 */

use Drush\Drush;

// Get variables from the parent script.
$limit = $GLOBALS['limit'] ?? PHP_INT_MAX;
$update_existing = $GLOBALS['update_existing'] ?? FALSE;

// Define the mapping configuration for the restricted counties vocabulary.
$mapping = [
  'vid' => 'restricted_counties',
  'csv_file' => 'rcgr_ref_list_of_restricted_counties_202503031405.csv',
// Use county_name instead of ref_cd.
  'name_field' => 'county_name',
  'description_field' => 'description',
  'field_mappings' => [
    'state_cd' => 'field_state',
    'isCountyRestricted' => 'field_iscountyrestricted',
    'recno' => 'field_recno',
  ],
];

// Import the terms using the common function.
$stats = import_taxonomy_terms($mapping, $limit, $update_existing);

// Display final results.
Drush::logger()->notice("Restricted counties taxonomy import completed.");
Drush::logger()->notice("Total rows processed: {$stats['processed']}");
Drush::logger()->notice("Terms created: {$stats['created']}");
Drush::logger()->notice("Terms updated: {$stats['updated']}");
Drush::logger()->notice("Terms skipped: {$stats['skipped']}");
Drush::logger()->notice("Errors: {$stats['errors']}");
