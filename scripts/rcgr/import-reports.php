<?php

/**
 * @file
 * Imports report data from CSV file into Drupal report nodes.
 *
 * This script imports Canada Goose reporting data from a CSV file
 * into Drupal report content type nodes. It handles creation of new
 * report nodes and updating existing ones. It also imports historical
 * revisions from a separate history CSV file.
 *
 * Usage: ddev drush scr scripts/rcgr/import-reports.php [limit]
 * Where [limit] is an optional number to limit the number of records processed.
 */

use Drupal\node\Entity\Node;
use Drupal\Core\Datetime\DrupalDateTime;
use Drush\Drush;
use Drupal\taxonomy\Entity\Term;

// Include the user import functions.
require_once __DIR__ . '/import-users.php';
// Include the permit import functions.
require_once __DIR__ . '/import-permits.php';
// Include the location import functions.
require_once __DIR__ . '/import-locations.php';

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

// Get the CSV file paths.
$current_csv_file = __DIR__ . '/data/rcgr_report_202503031405.csv';
$history_csv_file = __DIR__ . '/data/rcgr_report_hist_202503031405.csv';

// Track processed nodes to handle revisions.
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

// Additional CSV columns that are used but not mapped to fields.
$additional_columns = [
  'dt_create',
  'dt_update',
  'create_by',
  'update_by',
];

// Initialize counters.
$total = 0;
$created = 0;
$updated = 0;
$skipped = 0;
$errors = 0;
$processed = 0;
$revisions_created = 0;

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
 * @param bool $import_if_not_found
 *   Whether to import the user if not found.
 *
 * @return int|null
 *   The user ID, or NULL if not found or created.
 */
function find_user_by_legacy_id($legacy_userid, $import_if_not_found = FALSE) {
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

  // If we shouldn't import users, just log that we didn't find it and return NULL.
  if (!$import_if_not_found) {
    $_rcgr_users_not_found++;
    $_rcgr_import_logger->debug("User with legacy ID {$legacy_userid} not found and auto-import disabled");
    return NULL;
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
 * Find existing report node for a permit and year.
 *
 * @param string $permit_no
 *   The permit number.
 * @param string $report_year
 *   The report year.
 *
 * @return \Drupal\node\NodeInterface|null
 *   The node if found, null otherwise.
 */
function find_existing_report($permit_no, $report_year) {
  $query = \Drupal::entityQuery('node')
    ->condition('type', 'report')
    ->condition('field_permit_no', $permit_no)
    ->condition('field_report_year', $report_year)
    ->accessCheck(FALSE)
    ->range(0, 1);

  $nids = $query->execute();

  if (!empty($nids)) {
    $nid = reset($nids);
    return Node::load($nid);
  }

  return NULL;
}

/**
 * Ensure a permit exists, creating a minimal one if necessary.
 *
 * @param string $permit_no
 *   The permit number.
 * @param mixed $logger
 *   Logger object for messages.
 *
 * @return bool
 *   TRUE if permit exists or was created, FALSE otherwise.
 */
function ensure_permit_exists(string $permit_no, $logger): bool {
  if (empty($permit_no)) {
    return FALSE;
  }

  // Track permits we've already verified to avoid duplicate lookups.
  static $verified_permits = [];

  if (isset($verified_permits[$permit_no])) {
    return TRUE;
  }

  // First, check if the permit already exists.
  $query = \Drupal::entityQuery('node')
    ->condition('type', 'permit')
    ->condition('field_permit_no', $permit_no)
    ->accessCheck(FALSE)
    ->range(0, 1);

  $nids = $query->execute();

  if (!empty($nids)) {
    $nid = reset($nids);
    $logger->notice(sprintf('Found existing permit %s with node ID %d', $permit_no, $nid));
    $verified_permits[$permit_no] = TRUE;
    return TRUE;
  }

  // If we get here, no existing permit was found, so we need to import it.
  $logger->notice(sprintf('No existing permit found for %s, attempting to create it directly...', $permit_no));

  // Create a minimal permit node.
  try {
    $node = Node::create([
      'type' => 'permit',
      'title' => $permit_no,
      'field_permit_no' => $permit_no,
      'status' => 1,
    ]);

    $node->save();
    $logger->notice(sprintf('Successfully created basic permit %s (NID: %d)', $permit_no, $node->id()));
    $verified_permits[$permit_no] = TRUE;
    return TRUE;
  }
  catch (\Exception $e) {
    $logger->error(sprintf('Error creating basic permit %s: %s', $permit_no, $e->getMessage()));
    return FALSE;
  }
}

/**
 * Ensure location exists and import it if necessary.
 *
 * @param string $permit_no
 *   The permit number associated with the location.
 * @param mixed $logger
 *   Logger object for messages.
 *
 * @return bool
 *   TRUE if location exists or was created, FALSE otherwise.
 */
function ensure_location_exists(string $permit_no, $logger): bool {
  if (empty($permit_no)) {
    return FALSE;
  }

  // Track locations we've already verified to avoid duplicate lookups.
  static $verified_locations = [];

  if (isset($verified_locations[$permit_no])) {
    return TRUE;
  }

  // First, check if a location already exists for this permit.
  $query = \Drupal::entityQuery('node')
    ->condition('type', 'location')
    ->condition('field_permit_no', $permit_no)
    ->accessCheck(FALSE)
    ->range(0, 1);

  $nids = $query->execute();

  if (!empty($nids)) {
    $nid = reset($nids);
    $logger->notice(sprintf('Found existing location for permit %s with node ID %d', $permit_no, $nid));
    $verified_locations[$permit_no] = TRUE;
    return TRUE;
  }

  // If we get here, no existing location was found, so we need to import it.
  $logger->notice(sprintf('No existing location found for permit %s, attempting to import it...', $permit_no));

  // Use the import_location_by_permit_id function to import the location.
  $log_via_logger = function ($message) use ($logger) {
    $logger->info($message);
  };

  $node = import_location_by_permit_id($permit_no, NULL, $log_via_logger);

  if ($node) {
    $logger->notice(sprintf('Successfully imported location for permit %s (NID: %d)', $permit_no, $node->id()));
    $verified_locations[$permit_no] = TRUE;
    return TRUE;
  }

  $logger->warning(sprintf('Could not import location for permit %s', $permit_no));
  return FALSE;
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
 * @param bool $is_revision
 *   Whether this row is for a historical revision.
 * @param array &$processed_nodes
 *   Reference to the array of processed nodes.
 *
 * @return array
 *   Array containing success status and any messages.
 */
function process_report_row(
  array $data,
  array $field_mapping,
  array &$term_cache,
  array $value_mappings,
  bool $is_revision,
  array &$processed_nodes,
) {
  global $_rcgr_import_logger;

  try {
    // Log the row being processed.
    $_rcgr_import_logger->info(sprintf('Processing %s report: Permit #%s, Year: %s',
      $is_revision ? 'historical' : 'current',
      $data['permit_no'],
      $data['report_year']
    ));

    // Check if the referenced permit exists, import it if not.
    if (!empty($data['permit_no']) && !$is_revision) {
      if (!ensure_permit_exists($data['permit_no'], $_rcgr_import_logger)) {
        $_rcgr_import_logger->warning(sprintf('Missing permit %s for report, but continuing with import',
          $data['permit_no']
        ));
      }

      // Now check if the location exists for this permit, import it if not.
      if (!ensure_location_exists($data['permit_no'], $_rcgr_import_logger)) {
        $_rcgr_import_logger->warning(sprintf('Missing location for permit %s, but continuing with import',
          $data['permit_no']
        ));
      }
    }

    // For revisions, try to find existing node.
    $existing_node = NULL;
    if ($is_revision) {
      $existing_node = find_existing_report($data['permit_no'], $data['report_year']);
      if (!$existing_node) {
        return [
          FALSE,
          sprintf(
            'No existing node found for permit %s, year %s - skipping revision',
            $data['permit_no'],
            $data['report_year']
          ),
        ];
      }
      $node = $existing_node;
      $node->setNewRevision(TRUE);
      $node->revision_log = sprintf(
        'Historical revision imported from year %s. Created by %s, Updated by %s',
        $data['report_year'],
        $data['create_by'] ?? 'unknown',
        $data['update_by'] ?? 'unknown'
      );

      // For revisions, set the revision timestamp if dt_update is available.
      if (!empty($data['dt_update'])) {
        try {
          $date = new DrupalDateTime($data['dt_update']);
          $node->setRevisionCreationTime($date->getTimestamp());
        }
        catch (\Exception $e) {
          $_rcgr_import_logger->warning(sprintf('Could not parse date from %s for revision',
            $data['dt_update']
          ));
        }
      }
    }
    else {
      // Check if a report already exists for this permit and year.
      $existing_node = find_existing_report($data['permit_no'], $data['report_year']);
      $is_update = FALSE;

      if ($existing_node) {
        $node = $existing_node;
        $is_update = TRUE;

        // If the report exists, update it rather than creating a new one.
        $_rcgr_import_logger->info(sprintf('Updating existing report (NID: %d) for permit #%s, year %s',
          $node->id(),
          $data['permit_no'],
          $data['report_year']
        ));
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

        $_rcgr_import_logger->info(sprintf('Creating new report for permit #%s, year %s',
          $data['permit_no'],
          $data['report_year']
        ));

        // Set creation time if dt_create is available.
        if (!empty($data['dt_create'])) {
          try {
            $date = new DrupalDateTime($data['dt_create']);
            $node->setCreatedTime($date->getTimestamp());
          }
          catch (\Exception $e) {
            $_rcgr_import_logger->warning(sprintf('Could not parse creation date from %s',
              $data['dt_create']
            ));
          }
        }
      }

      // Set changed time if dt_update is available.
      if (!empty($data['dt_update'])) {
        try {
          $date = new DrupalDateTime($data['dt_update']);
          $node->setChangedTime($date->getTimestamp());
        }
        catch (\Exception $e) {
          $_rcgr_import_logger->warning(sprintf('Could not parse update date from %s',
            $data['dt_update']
          ));
        }
      }
    }

    // Associate the report entity with a user based on legacy userid.
    if (!empty($data['create_by'])) {
      $uid = find_user_by_legacy_id($data['create_by'], FALSE);
      if ($uid) {
        // Set the node owner to the user with the matching legacy ID.
        $node->setOwnerId($uid);
      }
    }

    // Map and set field values.
    foreach ($field_mapping as $csv_field => $drupal_field) {
      if (!isset($data[$csv_field])) {
        // Skip non-existent fields without logging for historical revisions.
        if (!$is_revision) {
          $_rcgr_import_logger->notice(sprintf('Field %s not found in CSV data for permit #%s',
            $csv_field,
            $data['permit_no']
          ));
        }
        continue;
      }

      $value = $data[$csv_field];

      // Handle special cases based on field name.
      switch ($drupal_field) {
        case 'field_qty_nest_egg_destroyed_mar':
        case 'field_qty_nest_egg_destroyed_apr':
        case 'field_qty_nest_egg_destroyed_may':
        case 'field_qty_nest_egg_destroyed_jun':
        case 'field_qty_nest_egg_destroyed_tot':
        case 'field_report_year':
        case 'field_version_no':
          // These are integer fields.
          if ($value === '') {
            // If empty, set to 0.
            $node->set($drupal_field, 0);
          }
          else {
            $node->set($drupal_field, (int) $value);
          }
          break;

        default:
          // Default handling for text fields.
          $node->set($drupal_field, $value);
          break;
      }
    }

    // For revisions, set the revision author if we can find a matching user.
    if ($is_revision && !empty($data['update_by'])) {
      $uid = find_user_by_legacy_id($data['update_by'], FALSE);
      if ($uid) {
        $node->setRevisionUserId($uid);
      }
    }

    // Save the node.
    $node->save();

    // Track processed nodes.
    $key = $data['permit_no'] . ':' . $data['report_year'];
    $processed_nodes[$key] = $node->id();

    return [
      TRUE,
      sprintf(
        '%s report for permit %s, year %s (NID: %d)',
        ($is_revision ? 'Created revision for' : ($existing_node ? 'Updated' : 'Created')),
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

/**
 * Function to load and validate CSV data.
 *
 * @param string $file_path
 *   The path to the CSV file.
 * @param object $logger
 *   The logger object.
 *
 * @return array
 *   Array containing success status and file data.
 */
function load_csv_data($file_path, $logger) {
  // Check if the file exists.
  if (!file_exists($file_path)) {
    $logger->error('CSV file not found at @file', ['@file' => $file_path]);
    return [FALSE, NULL];
  }

  $handle = fopen($file_path, 'r');
  if ($handle === FALSE) {
    $logger->error('Could not open CSV file @file', ['@file' => $file_path]);
    return [FALSE, NULL];
  }

  $header = fgetcsv($handle);
  if ($header === FALSE) {
    $logger->error('Could not read CSV header from @file', ['@file' => $file_path]);
    fclose($handle);
    return [FALSE, NULL];
  }

  return [TRUE, ['handle' => $handle, 'header' => $header]];
}

// Load both CSV files.
[$current_success, $current_data] = load_csv_data($current_csv_file, $logger);
[$history_success, $history_data] = load_csv_data($history_csv_file, $logger);

if (!$current_success) {
  $_rcgr_import_logger->error(sprintf('Failed to load current CSV file: %s', $current_csv_file));
  exit(1);
}

$_rcgr_import_logger->notice('Starting import of report data.');

// Read all historical data into memory for faster lookup.
$historical_records = [];
if ($history_success) {
  while (($row = fgetcsv($history_data['handle'])) !== FALSE) {
    // Skip empty rows.
    if (count($row) > 0) {
      $data = array_combine($history_data['header'], $row);
      $key = $data['permit_no'] . ':' . $data['report_year'];
      if (!isset($historical_records[$key])) {
        $historical_records[$key] = [];
      }
      $historical_records[$key][] = $data;
    }
  }
  fclose($history_data['handle']);
  $_rcgr_import_logger->notice(sprintf('Loaded %d sets of historical records.', count($historical_records)));
}
else {
  $_rcgr_import_logger->warning('History CSV file not found or could not be read. Only current records will be imported.');
}

// Process current records and their historical data.
$row_number = 0;

while ($current_data && ($row = fgetcsv($current_data['handle'])) !== FALSE) {
  $row_number++;
  $total++;

  // Skip header row and empty rows.
  if (count($row) <= 1 && empty($row[0])) {
    $skipped++;
    continue;
  }

  // Only process up to the limit.
  if ($processed >= $limit) {
    break;
  }

  // Combine header with row data to create an associative array.
  $data = array_combine($current_data['header'], $row);

  // Process current record.
  [$success, $message] = process_report_row(
    $data,
    $field_mapping,
    $term_cache,
    $value_mappings,
    FALSE,
    $processed_nodes
  );

  if ($success) {
    $processed++;
    $created++;
    $_rcgr_import_logger->info($message);

    if ($processed % $batch_size === 0) {
      $_rcgr_import_logger->notice('Processed @count current records...', ['@count' => $processed]);
    }

    // Look for and process historical records for this report.
    $key = $data['permit_no'] . ':' . $data['report_year'];
    if (isset($historical_records[$key])) {
      foreach ($historical_records[$key] as $hist_data) {
        [$hist_success, $hist_message] = process_report_row(
          $hist_data,
          $field_mapping,
          $term_cache,
          $value_mappings,
          TRUE,
          $processed_nodes
        );

        if ($hist_success) {
          $revisions_created++;
          $_rcgr_import_logger->info($hist_message);

          if ($revisions_created % $batch_size === 0) {
            $_rcgr_import_logger->notice('Created @count historical revisions...', ['@count' => $revisions_created]);
          }
        }
        else {
          if (strpos($hist_message, 'No existing node found') === FALSE) {
            $_rcgr_import_logger->error($hist_message);
            $errors++;
          }
          else {
            $skipped++;
          }
        }
      }
      // Remove processed historical records to free memory.
      unset($historical_records[$key]);
    }
  }
  else {
    $_rcgr_import_logger->error($message);
    $errors++;
  }
}

// Close file handles.
if ($current_data) {
  fclose($current_data['handle']);
}

// Log the final statistics.
$_rcgr_import_logger->notice(sprintf(
  'Import complete. Processed %d records: %d created/updated, %d revisions, %d skipped, %d errors.',
  $total,
  $created,
  $revisions_created,
  $skipped,
  $errors
));

if ($_rcgr_users_imported > 0 || $_rcgr_users_not_found > 0) {
  $_rcgr_import_logger->notice(sprintf(
    'User statistics: %d imported, %d not found.',
    $_rcgr_users_imported,
    $_rcgr_users_not_found
  ));
}

/**
 * Performs a data audit to ensure that node data matches the source CSV.
 *
 * @param array $processed_nodes
 *   The array of processed node IDs.
 * @param string $csv_file
 *   The path to the source CSV file.
 * @param array $field_mapping
 *   The field mapping configuration.
 * @param bool $suppress_not_found_warnings
 *   Whether to suppress "Node not found" warnings.
 *
 * @return array
 *   An array containing audit results.
 */
function perform_data_audit(array $processed_nodes, string $csv_file, array $field_mapping, bool $suppress_not_found_warnings = FALSE): array {
  global $_rcgr_import_logger;

  $audit_results = [
    'total' => 0,
    'matched' => 0,
    'mismatched' => 0,
    'errors' => 0,
    'mismatches' => [],
    'not_found' => 0,
  ];

  $_rcgr_import_logger->notice('Starting data audit to verify imported data against source CSV.');

  // Load CSV data.
  if (!file_exists($csv_file)) {
    $_rcgr_import_logger->error(sprintf('CSV file not found: %s', $csv_file));
    return $audit_results;
  }

  $handle = fopen($csv_file, 'r');
  if ($handle === FALSE) {
    $_rcgr_import_logger->error(sprintf('Could not open CSV file: %s', $csv_file));
    return $audit_results;
  }

  // Process the header row.
  $header = fgetcsv($handle);
  if ($header === FALSE) {
    $_rcgr_import_logger->error(sprintf('CSV file is empty or improperly formatted: %s', $csv_file));
    fclose($handle);
    return $audit_results;
  }

  // Compare each processed node with its source data.
  while (($row = fgetcsv($handle)) !== FALSE) {
    $audit_results['total']++;

    // Create associative array from CSV row.
    $data = array_combine($header, $row);

    // Skip empty rows.
    if (count($row) <= 1 && empty($row[0])) {
      continue;
    }

    // Generate the key to look up in processed nodes.
    $key = $data['permit_no'] . ':' . $data['report_year'];

    // Check if this node was processed.
    if (!isset($processed_nodes[$key])) {
      if (!$suppress_not_found_warnings) {
        $_rcgr_import_logger->warning(sprintf('Node not found for permit %s, year %s',
          $data['permit_no'],
          $data['report_year']
        ));
      }
      $audit_results['not_found']++;
      continue;
    }

    // Load the node.
    $nid = $processed_nodes[$key];
    $node = \Drupal::entityTypeManager()->getStorage('node')->load($nid);

    if (!$node) {
      $_rcgr_import_logger->error(sprintf('Could not load node %d for permit %s, year %s',
        $nid,
        $data['permit_no'],
        $data['report_year']
      ));
      $audit_results['errors']++;
      continue;
    }

    // Compare node values with CSV data.
    $field_mismatches = [];
    foreach ($field_mapping as $csv_field => $drupal_field) {
      // Skip fields that might not be in the CSV.
      if (!isset($data[$csv_field])) {
        continue;
      }

      // Handle special case date fields.
      if ($drupal_field === 'field_dt_create' || $drupal_field === 'field_dt_update') {
        continue;
      }

      // Get the CSV value.
      $csv_value = $data[$csv_field];

      // Get the node value.
      if (!$node->hasField($drupal_field)) {
        $_rcgr_import_logger->warning(sprintf('Field %s does not exist on node %d',
          $drupal_field,
          $nid
        ));
        continue;
      }

      if ($node->get($drupal_field)->isEmpty()) {
        $node_value = '';
      }
      else {
        $node_value = $node->get($drupal_field)->value;
      }

      // For integer fields, convert CSV value to integer for comparison.
      if (in_array($drupal_field, [
        'field_qty_nest_egg_destroyed_mar',
        'field_qty_nest_egg_destroyed_apr',
        'field_qty_nest_egg_destroyed_may',
        'field_qty_nest_egg_destroyed_jun',
        'field_qty_nest_egg_destroyed_tot',
        'field_report_year',
        'field_version_no',
      ])) {
        if ($csv_value === '') {
          $csv_value = '0';
        }
        $csv_value = (int) $csv_value;
        $node_value = (int) $node_value;
      }

      // Compare values.
      if ($csv_value != $node_value) {
        $field_mismatches[$drupal_field] = [
          'csv' => $csv_value,
          'node' => $node_value,
        ];
      }
    }

    // Record audit results.
    if (empty($field_mismatches)) {
      $audit_results['matched']++;
    }
    else {
      $audit_results['mismatched']++;
      $audit_results['mismatches'][] = [
        'nid' => $nid,
        'permit' => $data['permit_no'],
        'year' => $data['report_year'],
        'mismatches' => $field_mismatches,
      ];
    }
  }

  fclose($handle);

  // Log audit results.
  $_rcgr_import_logger->notice(sprintf(
    'Data audit complete. Processed %d records: %d matched, %d mismatched, %d errors.',
    $audit_results['total'],
    $audit_results['matched'],
    $audit_results['mismatched'],
    $audit_results['errors']
  ));

  // Log details of mismatches for troubleshooting.
  if ($audit_results['mismatched'] > 0) {
    $_rcgr_import_logger->warning('Data mismatches found. See details below:');
    foreach ($audit_results['mismatches'] as $mismatch) {
      $_rcgr_import_logger->warning(sprintf('Mismatch for node %d (Permit: %s, Year: %s):',
        $mismatch['nid'],
        $mismatch['permit'],
        $mismatch['year']
      ));

      foreach ($mismatch['mismatches'] as $field => $values) {
        $_rcgr_import_logger->warning(sprintf('  - Field %s: CSV=\'%s\', Node=\'%s\'',
          $field,
          $values['csv'],
          $values['node']
        ));
      }
    }
  }

  return $audit_results;
}

// At the end of the file, after importing is complete, add:
// Perform data audit if records were successfully processed.
if ($processed > 0) {
  $_rcgr_import_logger->notice('Starting data audit to verify imported data.');
  $audit_results = perform_data_audit($processed_nodes, $current_csv_file, $field_mapping, TRUE);

  // Output audit summary.
  echo "\nData Audit Results:\n";
  echo "Total records checked: {$audit_results['total']}\n";
  echo "Records matched: {$audit_results['matched']}\n";
  echo "Records with mismatches: {$audit_results['mismatched']}\n";
  echo "Errors: {$audit_results['errors']}\n";
  echo "Not found: {$audit_results['not_found']}\n";
}
