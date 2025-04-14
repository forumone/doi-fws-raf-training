<?php

/**
 * @file
 * Imports report data from CSV file into Drupal report nodes.
 *
 * This script imports Canada Goose reporting data from a CSV file
 * into Drupal report content type nodes. It handles creation of new
 * report nodes and updating existing ones.
 *
 * Usage: ddev drush scr scripts/rcgr/import-reports.php [limit]
 * Where [limit] is an optional number to limit the number of records processed.
 */

use Drupal\node\Entity\Node;
use Drush\Drush;
use Drupal\taxonomy\Entity\Term;

// Include the user import functions.
require_once __DIR__ . '/import-users.php';

// Get the limit parameter from command line arguments if provided.
$input = Drush::input();
$args = $input->getArguments();
$limit = isset($args['extra'][1]) ? (int) $args['extra'][1] : PHP_INT_MAX;

$logger = Drush::logger();

// Log the limit if specified.
if ($limit < PHP_INT_MAX) {
  $logger->warning("Limiting import to {$limit} records");
}
else {
  $logger->warning("No limit specified - will import all records");
}

// Set the batch size for processing.
$batch_size = 50;

// Get the CSV file path.
$csv_file = __DIR__ . '/data/rcgr_report_202503031405.csv';

// Track processed nodes.
$processed_nodes = [];

// Track users not found and imported.
global $_rcgr_users_not_found;
global $_rcgr_users_imported;
$_rcgr_users_not_found = 0;
$_rcgr_users_imported = 0;

// Map CSV columns to field names.
// Only include fields that exist in the report content type.
$field_mapping = [
  'permit_no' => 'field_permit_no',
  'report_year' => 'field_report_year',
  'location_state' => 'field_location_state',
  'location_county' => 'field_location_county',
  'qty_nest_egg_destroyed_mar' => 'field_qty_nest_egg_destroyed_mar',
  'qty_nest_egg_destroyed_apr' => 'field_qty_nest_egg_destroyed_apr',
  'qty_nest_egg_destroyed_may' => 'field_qty_nest_egg_destroyed_may',
  'qty_nest_egg_destroyed_jun' => 'field_qty_nest_egg_destroyed_jun',
  'qty_nest_egg_destroyed_tot' => 'field_qty_nest_egg_destroyed_tot',
  'version_no' => 'field_version_no',
  'hid' => 'field_hid',
];

// Initialize counters.
$total = 0;
$created = 0;
$updated = 0;
$skipped = 0;
$errors = 0;
$processed = 0;

// Initialize taxonomy term cache.
$term_cache = [];

// Define value mappings for taxonomy term values that need translation.
$value_mappings = [
  'U' => 'Unknown',
  'A' => 'Active',
  'C' => 'Complete',
  'I' => 'Inactive',
];

// Define the logger as a properly named global variable.
global $_rcgr_import_logger;
$_rcgr_import_logger = $logger;

/**
 * Get the taxonomy term ID for a given name and vocabulary.
 *
 * @param string $name
 *   The term name.
 * @param string $vocabulary
 *   The vocabulary machine name.
 * @param bool $create_if_missing
 *   Whether to create the term if it doesn't exist.
 * @param array &$term_cache
 *   Reference to the term cache array.
 * @param array $value_mappings
 *   Mappings from special values to proper term names.
 * @param bool $force_new_term
 *   Whether to force creation of a new term even if one exists.
 *
 * @return int|null
 *   The term ID, or NULL if not found and not creating.
 */
function get_taxonomy_term_id($name, $vocabulary, $create_if_missing = TRUE, array &$term_cache = [], array $value_mappings = [], $force_new_term = FALSE) {
  global $_rcgr_import_logger;

  // Skip empty values.
  if (empty($name)) {
    $_rcgr_import_logger->warning("Empty value provided for vocabulary '{$vocabulary}'");
    return NULL;
  }

  // Check if we need to map the value to a proper term name.
  if (isset($value_mappings[$name])) {
    $name = $value_mappings[$name];
  }

  // Normalize the name.
  $name = trim($name, '"');

  // Generate a cache key.
  $cache_key = $vocabulary . ':' . $name;

  // Check cache first, unless we're forcing a new term.
  if (!$force_new_term && isset($term_cache[$cache_key])) {
    return $term_cache[$cache_key];
  }

  // Query for the term, unless we're forcing a new term.
  $tid = NULL;
  if (!$force_new_term) {
    $query = \Drupal::entityQuery('taxonomy_term')
      ->condition('vid', $vocabulary)
      ->condition('name', $name)
      ->accessCheck(FALSE)
      ->range(0, 1);
    $tids = $query->execute();

    if (!empty($tids)) {
      $tid = reset($tids);
      $term_cache[$cache_key] = $tid;
      return $tid;
    }
  }

  // Create the term if it doesn't exist and we're allowed to create it.
  if ($create_if_missing) {
    $term_data = [
      'vid' => $vocabulary,
      'name' => $name,
      'status' => TRUE,
    ];

    $term = Term::create($term_data);
    $term->save();
    $tid = $term->id();
    $term_cache[$cache_key] = $tid;
  }

  return $tid;
}

/**
 * Find a user by legacy user ID. Imports new user if not found.
 *
 * @param string $legacy_userid
 *   The legacy user ID.
 *
 * @return int|null
 *   The user ID, or NULL if not found or created.
 */
function find_user_by_legacy_id($legacy_userid) {
  global $_rcgr_import_logger, $_rcgr_users_not_found, $_rcgr_users_imported;

  if (empty($legacy_userid)) {
    return NULL;
  }

  // Trim whitespace from the legacy user ID.
  $legacy_userid = trim($legacy_userid);

  if (empty($legacy_userid)) {
    return NULL;
  }

  // Try to find a user with this legacy ID.
  $query = \Drupal::entityQuery('user')
    ->condition('field_legacy_userid', $legacy_userid)
    ->accessCheck(FALSE);
  $uids = $query->execute();

  if (!empty($uids)) {
    $uid = reset($uids);
    $_rcgr_import_logger->debug("Found user {$uid} with legacy ID {$legacy_userid}");
    return $uid;
  }

  // Logger callback function for the import process that suppresses output.
  $log_via_logger = function ($message) {
    // Don't output anything here to reduce verbosity.
  };

  // Try to import the user from the original CSV.
  $user = import_user_by_legacy_id($legacy_userid, NULL, $log_via_logger);

  if ($user) {
    $_rcgr_users_imported++;
    $_rcgr_import_logger->debug("Imported user {$user->id()} for legacy ID {$legacy_userid}");
    return $user->id();
  }

  $_rcgr_users_not_found++;
  return NULL;
}

/**
 * Process a single row of report data.
 *
 * @param array $data
 *   The row data.
 * @param array $field_mapping
 *   The field mapping configuration.
 * @param array &$term_cache
 *   Reference to the term cache.
 * @param array $value_mappings
 *   Value mappings for taxonomy terms.
 *
 * @return array
 *   Array containing success status and any messages.
 */
function process_report_row(
  array $data,
  array $field_mapping,
  array &$term_cache,
  array $value_mappings,
) {
  global $_rcgr_import_logger;

  try {
    // Log the row being processed.
    $_rcgr_import_logger->info('Processing report: Permit #{permit}, Year: {year}', [
      'permit' => $data['permit_no'],
      'year' => $data['report_year'],
    ]);

    // Check if a report already exists for this permit and year.
    $query = \Drupal::entityQuery('node')
      ->condition('type', 'report')
      ->condition('field_permit_no', $data['permit_no'])
      ->condition('field_report_year', $data['report_year'])
      ->accessCheck(FALSE)
      ->range(0, 1);

    $nids = $query->execute();
    $is_update = FALSE;

    if (!empty($nids)) {
      $nid = reset($nids);
      $node = Node::load($nid);
      $is_update = TRUE;

      // If the report exists, update it rather than creating a new one.
      $_rcgr_import_logger->info('Updating existing report (NID: {nid}) for permit #{permit}, year {year}', [
        'nid' => $nid,
        'permit' => $data['permit_no'],
        'year' => $data['report_year'],
      ]);
    }
    else {
      // Generate a title that combines permit number and year.
      $title = sprintf('Report for permit %s - %s', $data['permit_no'], $data['report_year']);

      // Create a new report node.
      $node = Node::create([
        'type' => 'report',
        'title' => $title,
        'status' => 1,
      ]);

      $_rcgr_import_logger->info('Creating new report for permit #{permit}, year {year}', [
        'permit' => $data['permit_no'],
        'year' => $data['report_year'],
      ]);
    }

    // Associate the report entity with a user based on legacy userid.
    if (!empty($data['create_by'])) {
      $uid = find_user_by_legacy_id($data['create_by']);
      if ($uid) {
        // Set the node owner to the user with the matching legacy ID.
        $node->setOwnerId($uid);
      }
    }

    // Map and set field values.
    foreach ($field_mapping as $csv_field => $drupal_field) {
      if (!isset($data[$csv_field])) {
        $_rcgr_import_logger->notice('Field {field} not found in CSV data for permit #{permit}', [
          'field' => $csv_field,
          'permit' => $data['permit_no'],
        ]);
        continue;
      }

      $value = $data[$csv_field];

      // Handle special cases based on field name.
      if (in_array($drupal_field, [
        'field_qty_nest_egg_destroyed_mar',
        'field_qty_nest_egg_destroyed_apr',
        'field_qty_nest_egg_destroyed_may',
        'field_qty_nest_egg_destroyed_jun',
        'field_qty_nest_egg_destroyed_tot',
        'field_report_year',
        'field_version_no',
      ])) {
        // These are integer fields.
        if ($value === '') {
          // If empty, set to 0.
          $node->set($drupal_field, 0);
        }
        else {
          $node->set($drupal_field, (int) $value);
        }
      }
      else {
        // Default handling for text fields.
        $node->set($drupal_field, $value);
      }
    }

    // Save the node.
    $node->save();

    return [
      TRUE,
      sprintf(
        '%s report for permit %s, year %s (NID: %d)',
        $is_update ? 'Updated' : 'Created',
        $data['permit_no'],
        $data['report_year'],
        $node->id()
      ),
    ];
  }
  catch (\Exception $e) {
    return [
      FALSE,
      sprintf(
        'Error processing report for permit %s, year %s: %s',
        $data['permit_no'] ?? 'unknown',
        $data['report_year'] ?? 'unknown',
        $e->getMessage()
      ),
    ];
  }
}

// Main import execution.
$_rcgr_import_logger->notice('Starting report import from {file}', ['file' => $csv_file]);

// Check if the file exists.
if (!file_exists($csv_file)) {
  $_rcgr_import_logger->error('CSV file not found: {file}', ['file' => $csv_file]);
  exit(1);
}

// Open the CSV file.
$handle = fopen($csv_file, 'r');
if ($handle === FALSE) {
  $_rcgr_import_logger->error('Could not open CSV file: {file}', ['file' => $csv_file]);
  exit(1);
}

// Process the header row to get field names.
$header = fgetcsv($handle);
if ($header === FALSE) {
  $_rcgr_import_logger->error('CSV file is empty or improperly formatted: {file}', ['file' => $csv_file]);
  fclose($handle);
  exit(1);
}

// Main processing loop.
$batch = [];
$batch_number = 1;

while (($row = fgetcsv($handle)) !== FALSE && $processed < $limit) {
  $total++;

  // Skip empty rows.
  if (count($row) <= 1 && empty($row[0])) {
    $skipped++;
    continue;
  }

  // Combine header with row data to create an associative array.
  $data = array_combine($header, $row);

  // Add to current batch.
  $batch[] = $data;

  // Process the batch if we've reached the batch size.
  if (count($batch) >= $batch_size) {
    $_rcgr_import_logger->notice('Processing batch {num} ({start}-{end} of {total} records)...', [
      'num' => $batch_number,
      'start' => ($batch_number - 1) * $batch_size + 1,
      'end' => min($batch_number * $batch_size, $total),
      'total' => $total,
    ]);

    foreach ($batch as $item_data) {
      $processed++;

      [$success, $message] = process_report_row(
        $item_data,
        $field_mapping,
        $term_cache,
        $value_mappings
      );

      if ($success) {
        $created++;
        $_rcgr_import_logger->info('{message}', ['message' => $message]);
      }
      else {
        $errors++;
        $_rcgr_import_logger->error('{message}', ['message' => $message]);
      }

      // Break if we've reached the limit.
      if ($processed >= $limit) {
        break;
      }
    }

    // Clear the batch for the next round.
    $batch = [];
    $batch_number++;
  }
}

// Process any remaining items in the final batch.
if (!empty($batch) && $processed < $limit) {
  $_rcgr_import_logger->notice('Processing final batch ({start}-{end} of {total} records)...', [
    'start' => ($batch_number - 1) * $batch_size + 1,
    'end' => min(($batch_number - 1) * $batch_size + count($batch), $total),
    'total' => $total,
  ]);

  foreach ($batch as $item_data) {
    $processed++;

    [$success, $message] = process_report_row(
      $item_data,
      $field_mapping,
      $term_cache,
      $value_mappings
    );

    if ($success) {
      $created++;
      $_rcgr_import_logger->info('{message}', ['message' => $message]);
    }
    else {
      $errors++;
      $_rcgr_import_logger->error('{message}', ['message' => $message]);
    }

    // Break if we've reached the limit.
    if ($processed >= $limit) {
      break;
    }
  }
}

// Close the CSV file.
fclose($handle);

// Log the final statistics.
$_rcgr_import_logger->notice('Import complete. Processed {total} records: {created} created/updated, {skipped} skipped, {errors} errors.', [
  'total' => $total,
  'created' => $created,
  'skipped' => $skipped,
  'errors' => $errors,
]);

if ($_rcgr_users_imported > 0 || $_rcgr_users_not_found > 0) {
  $_rcgr_import_logger->notice('User statistics: {imported} imported, {not_found} not found.', [
    'imported' => $_rcgr_users_imported,
    'not_found' => $_rcgr_users_not_found,
  ]);
}
