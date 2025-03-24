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
  'skip_row_callback' => function ($row, $row_number, $column_indices) {
    // Skip empty rows or separator rows.
    if (empty($row) || (count($row) === 1 && empty($row[0]))) {
      return TRUE;
    }

    // Skip rows with empty registrant type codes.
    if (empty($row[$column_indices['ref_cd']])) {
      return TRUE;
    }

    return FALSE;
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
