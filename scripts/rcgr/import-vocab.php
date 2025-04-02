<?php

/**
 * @file
 * Main script to run individual vocabulary imports.
 *
 * Usage: ddev drush --uri=https://rcgr.ddev.site/ scr scripts/rcgr/import-vocab.php [vocabulary] [limit] [update]
 * Parameters:
 * - [vocabulary]: The vocabulary ID to import (e.g., 'states', 'country', etc.)
 * - [limit]: Optional. Maximum number of terms to import.
 * - [update]: Optional. Whether to update existing terms (1=yes, 0=no).
 */

use Drush\Drush;

// Get the parameters from command line arguments.
$input = Drush::input();
$args = $input->getArguments();

// Get the vocabulary to import.
if (!isset($args['extra'][1])) {
  Drush::logger()->error("Error: No vocabulary specified. Usage: drush scr scripts/rcgr/import-vocab.php [vocabulary] [limit] [update]");
  exit(1);
}

$vocabulary = $args['extra'][1];
$limit = isset($args['extra'][2]) ? (int) $args['extra'][2] : PHP_INT_MAX;
$update_existing = isset($args['extra'][3]) ? (bool) $args['extra'][3] : FALSE;

// Make variables available to the individual import scripts.
$GLOBALS['vocabulary'] = $vocabulary;
$GLOBALS['limit'] = $limit;
$GLOBALS['update_existing'] = $update_existing;

// Load the base import functionality.
require_once __DIR__ . '/imports/import-base.php';

// Map of available vocabulary IDs to their import script files.
$import_scripts = [
  'application_status' => 'import-application-status.php',
  'applicant_request_type' => 'import-applicant-request-type.php',
  'california_access_key' => 'import-california-access-key.php',
  'country' => 'import-country.php',
  'flyways' => 'import-flyways.php',
  'registrant_type' => 'import-registrant-type.php',
  'restricted_counties' => 'import-restricted-counties.php',
  'states' => 'import-states.php',
];

// Check if the requested vocabulary is available.
if (!isset($import_scripts[$vocabulary])) {
  $available_vocabs = implode(', ', array_keys($import_scripts));
  Drush::logger()->error("Error: Vocabulary '$vocabulary' not found. Available vocabularies: $available_vocabs");
  exit(1);
}

// Load and run the specific import script.
$import_script = __DIR__ . '/imports/' . $import_scripts[$vocabulary];

if (!file_exists($import_script)) {
  Drush::logger()->error("Error: Import script for '$vocabulary' not found at $import_script");
  exit(1);
}

// Initialize log output.
Drush::logger()->notice("Starting import for vocabulary: $vocabulary");
if ($limit !== PHP_INT_MAX) {
  Drush::logger()->notice("Import limit: $limit");
}
if ($update_existing) {
  Drush::logger()->notice("Update mode: enabled");
}

// Run the import.
require_once $import_script;
