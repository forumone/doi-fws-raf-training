#!/usr/bin/env php
<?php

/**
 * @file
 * Imports permit data and optionally all related entities.
 *
 * Usage:
 *   - All permits: ddev drush scr scripts/rcgr/import-permits.php -- --with-all
 *   - Single permit: ddev drush scr scripts/rcgr/import-permits.php -- 00010A
 *   - Multiple permits: ddev drush scr scripts/rcgr/import-permits.php -- 00010A 00019A 00032A
 *   - From CSV: ddev drush scr scripts/rcgr/import-permits.php -- --csv=data/permits.csv
 *   - With options: ddev drush scr scripts/rcgr/import-permits.php -- 00010A --with-all.
 *
 * Note: The -- separator is needed to ensure Drush passes all arguments to the script.
 * If no specific permit numbers are provided, the script will automatically read from
 * the default CSV file: data/rcgr_permit_app_mast_202503031405.csv
 *
 * Options:
 *   --with-locations     Import related locations for each permit (default: FALSE)
 *   --with-reports       Import related reports for each permit (default: FALSE)
 *   --with-users         Import related users from permit fields (default: FALSE)
 *   --with-all           Import all related entities (default: FALSE)
 *   --limit=N            Limit to N permit records
 *   --csv=FILE           Import permits from a CSV file
 *   --dry-run            Show what would be imported without making changes
 */

use Drupal\node\Entity\Node;
use Drush\Drush;

// Include required import scripts based on requested options.
require_once __DIR__ . '/import-users.php';
require_once __DIR__ . '/import-locations.php';

// Include the reports import file if we're importing reports.
if ($import_reports || isset($args['extra']) && in_array('--with-reports', $args['extra']) || isset($args['extra']) && in_array('--with-all', $args['extra'])) {
  if (file_exists(__DIR__ . '/import-reports.php')) {
    require_once __DIR__ . '/import-reports.php';
  }
}

// Configure global settings and variables.
global $_rcgr_import_logger;
global $_rcgr_users_not_found;
global $_rcgr_users_imported;
global $_imported_users;
$_rcgr_users_not_found = 0;
$_rcgr_users_imported = 0;
$_imported_users = [];

// Parse command line options.
$input = Drush::input();
$args = $input->getArguments();

// We'll check for our custom options in the args array.
$extra_args = $args['extra'] ?? [];
$options = [];

// Process options with defaults.
$import_locations = FALSE;
$import_reports = FALSE;
$import_users = FALSE;
$dry_run = FALSE;
$limit = PHP_INT_MAX;
$csv_file = NULL;

// Process args to extract our custom options.
foreach ($extra_args as $arg) {
  // Handle arguments that may have script name as prefix.
  if (strpos($arg, 'scripts/rcgr/import-permits.php') === 0) {
    continue;
  }

  if (strpos($arg, '--with-locations') === 0) {
    $import_locations = TRUE;
  }
  elseif (strpos($arg, '--with-reports') === 0) {
    $import_reports = TRUE;
  }
  elseif (strpos($arg, '--with-users') === 0) {
    $import_users = TRUE;
  }
  elseif (strpos($arg, '--with-all') === 0) {
    $import_locations = TRUE;
    $import_reports = TRUE;
    $import_users = TRUE;
  }
  elseif (strpos($arg, '--dry-run') === 0) {
    $dry_run = TRUE;
  }
  elseif (strpos($arg, '--limit=') === 0) {
    $limit_val = substr($arg, 8);
    if (is_numeric($limit_val)) {
      $limit = (int) $limit_val;
    }
  }
  elseif (strpos($arg, '--csv=') === 0) {
    $csv_file = substr($arg, 6);
  }
}

$logger = Drush::logger();
$_rcgr_import_logger = $logger;

// Get permit numbers to process.
$permit_numbers = [];

// Check if we're importing from a CSV file.
if (isset($csv_file)) {
  $logger->notice("Importing permits from CSV file: $csv_file");

  if (!file_exists($csv_file)) {
    $logger->error("CSV file not found: $csv_file");
    exit(1);
  }

  // Open the CSV file.
  $handle = fopen($csv_file, 'r');
  if ($handle === FALSE) {
    $logger->error("Could not open CSV file: $csv_file");
    exit(1);
  }

  // Process the header row to find permit number column.
  $header = fgetcsv($handle);
  if ($header === FALSE) {
    $logger->error("CSV file is empty or incorrectly formatted");
    fclose($handle);
    exit(1);
  }

  // Look for a column named 'permit_no' or similar.
  $permit_col_index = FALSE;
  $possible_names = ['permit_no', 'permit', 'permit_number', 'permitno'];

  foreach ($possible_names as $name) {
    $index = array_search($name, array_map('strtolower', $header));
    if ($index !== FALSE) {
      $permit_col_index = $index;
      break;
    }
  }

  if ($permit_col_index === FALSE) {
    $logger->error("Could not find permit number column in CSV header. Expected one of: " . implode(', ', $possible_names));
    fclose($handle);
    exit(1);
  }

  // Read permit numbers from the CSV.
  while (($row = fgetcsv($handle)) !== FALSE) {
    if (isset($row[$permit_col_index]) && !empty($row[$permit_col_index])) {
      $permit_numbers[] = trim($row[$permit_col_index]);
    }
  }

  fclose($handle);

  $logger->notice("Found " . count($permit_numbers) . " permit numbers in CSV file");
}
else {
  // Get permit numbers from command line arguments, filtering out option args.
  foreach ($extra_args as $arg) {
    // Handle arguments that may have script name as prefix.
    if (strpos($arg, 'scripts/rcgr/import-permits.php') === 0) {
      continue;
    }

    // Skip options (they start with --)
    if (substr($arg, 0, 2) !== '--') {
      $permit_numbers[] = $arg;
    }
  }

  // If no permit numbers are provided, read them from the default CSV file.
  if (empty($permit_numbers)) {
    $default_csv_file = __DIR__ . '/data/rcgr_permit_app_mast_202503031405.csv';
    $logger->notice("No specific permit numbers provided. Reading from default file: $default_csv_file");

    if (!file_exists($default_csv_file)) {
      $logger->error("Default CSV file not found: $default_csv_file");
      exit(1);
    }

    // Open the CSV file.
    $handle = fopen($default_csv_file, 'r');
    if ($handle === FALSE) {
      $logger->error("Could not open default CSV file: $default_csv_file");
      exit(1);
    }

    // Process the header row.
    $header = fgetcsv($handle);
    if ($header === FALSE) {
      $logger->error("Default CSV file is empty or incorrectly formatted");
      fclose($handle);
      exit(1);
    }

    // For the default file, we know the second column (index 1) is the permit number.
    $permit_col_index = 1;

    // Read permit numbers from the CSV.
    while (($row = fgetcsv($handle)) !== FALSE) {
      if (isset($row[$permit_col_index]) && !empty($row[$permit_col_index])) {
        $permit_numbers[] = trim($row[$permit_col_index]);
      }
    }

    fclose($handle);
    $logger->notice("Found " . count($permit_numbers) . " permit numbers in default CSV file");
  }
  else {
    $logger->notice("Found " . count($permit_numbers) . " permit numbers from command line");
  }
}

// Validate that we have permits to import.
if (empty($permit_numbers)) {
  $logger->error("No permit numbers provided or found in CSV files.");
  echo "Usage:\n";
  echo "  - Single permit: ddev drush scr scripts/rcgr/import-permits.php -- 00010A\n";
  echo "  - Multiple permits: ddev drush scr scripts/rcgr/import-permits.php -- 00010A 00019A 00032A\n";
  echo "  - From CSV: ddev drush scr scripts/rcgr/import-permits.php -- --csv=data/permits.csv\n";
  echo "  - All permits: ddev drush scr scripts/rcgr/import-permits.php -- --with-all\n";
  echo "\n";
  echo "Options:\n";
  echo "  --with-locations     Import related locations for each permit\n";
  echo "  --with-reports       Import related reports for each permit\n";
  echo "  --with-users         Import related users from permit fields\n";
  echo "  --with-all           Import all related entities\n";
  echo "  --limit=N            Limit to N permit records\n";
  echo "  --dry-run            Show what would be imported without making changes\n";
  echo "\n";
  echo "Note: The -- separator is needed to ensure Drush passes all arguments to the script\n";
  exit(1);
}

// Display configuration.
$logger->notice("Starting permit import" . ($dry_run ? " (DRY RUN)" : ""));
$logger->notice("Import limit: " . ($limit < PHP_INT_MAX ? $limit : "unlimited"));
$logger->notice("Including related entities: " .
  ($import_locations ? "locations, " : "") .
  ($import_reports ? "reports, " : "") .
  ($import_users ? "users" : "") .
  (!$import_locations && !$import_reports && !$import_users ? "none" : ""));

// Limit the number of permits to process if requested.
if ($limit < count($permit_numbers)) {
  $permit_numbers = array_slice($permit_numbers, 0, $limit);
}

// Deduplicate the list.
$permit_numbers = array_unique($permit_numbers);

// Initialize counters for tracking results.
$results = [
  'total' => count($permit_numbers),
  'permits_success' => 0,
  'permits_failed' => 0,
  'locations_found' => 0,
  'locations_imported' => 0,
  'locations_failed' => 0,
  'reports_found' => 0,
  'reports_imported' => 0,
  'reports_failed' => 0,
  'users_imported' => 0,
  'errors' => [],
];

// Map CSV fields to Drupal fields for permits.
$_rcgr_permit_field_mapping = [
  'permit_no' => 'field_permit_no',
  'registrant_type_cd' => 'field_registrant_type_cd',
  'permit_status_cd' => 'field_permit_status_cd',
  'version_no' => 'field_version_no',
  'principal_name' => 'field_principal_name',
  'principal_first_name' => 'field_principal_first_name',
  'principal_middle_name' => 'field_principal_middle_name',
  'principal_last_name' => 'field_principal_last_name',
  'principal_suffix' => 'field_principal_suffix',
  'principal_title' => 'field_principal_title',
  'principal_telephone' => 'field_principal_telephone',
  'dt_signed' => 'field_dt_signed',
  'dt_permit_request' => 'field_dt_permit_request',
  'dt_permit_issued' => 'field_dt_permit_issued',
  'dt_effective' => 'field_dt_effective',
  'dt_expired' => 'field_dt_expired',
  'dt_applicant_signed' => 'field_dt_applicant_signed',
  'dt_application_received' => 'field_dt_application_received',
  'create_by' => 'field_create_by',
  'update_by' => 'field_update_by',
  'dt_create' => 'field_dt_create',
  'dt_update' => 'field_dt_update',
  'xml_cd' => 'field_xml_cd',
  'rcf_cd' => 'field_rcf_cd',
  'hid' => 'field_hid',
  'site_id' => 'field_site_id',
  'control_site_id' => 'field_control_site_id',
];

/**
 * Tracks a user that has been imported to avoid duplicates.
 *
 * @param object $user
 *   The user entity that was imported.
 *
 * @return bool
 *   TRUE if the user was added to tracking, FALSE if already tracked.
 */
function track_imported_user($user) {
  global $_imported_users;
  if ($user && !isset($_imported_users[$user->id()])) {
    $_imported_users[$user->id()] = $user;
    return TRUE;
  }
  return FALSE;
}

/**
 * Import reports for a given permit number.
 *
 * @param string $permit_no
 *   The permit number to import reports for.
 * @param string $csv_file
 *   Path to the CSV file containing report data.
 * @param object $logger
 *   Logger object for messages.
 * @param bool $dry_run
 *   If TRUE, only show what would be imported without making changes.
 *
 * @return array
 *   Results array with counts of found, imported, and failed reports.
 */
function import_reports_for_permit($permit_no, $csv_file, $logger, $dry_run = FALSE) {
  $results = [
    'found' => 0,
    'imported' => 0,
    'failed' => 0,
  ];

  if (!file_exists($csv_file)) {
    $logger->error("Reports CSV file not found: $csv_file");
    return $results;
  }

  // Open the CSV file.
  $handle = fopen($csv_file, 'r');
  if ($handle === FALSE) {
    $logger->error("Could not open reports CSV file: $csv_file");
    return $results;
  }

  // Process the header row.
  $header = fgetcsv($handle);
  if ($header === FALSE) {
    $logger->error("CSV file is empty or incorrectly formatted: $csv_file");
    fclose($handle);
    return $results;
  }

  // Find the permit number column index.
  $permit_col_index = array_search('permit_no', $header);
  if ($permit_col_index === FALSE) {
    $logger->error("Could not find 'permit_no' column in reports CSV header");
    fclose($handle);
    return $results;
  }

  // Search for all reports with the given permit number.
  $found_rows = [];
  while (($row = fgetcsv($handle)) !== FALSE) {
    if (isset($row[$permit_col_index]) && trim($row[$permit_col_index]) === $permit_no) {
      $found_rows[] = array_combine($header, $row);
    }
  }

  fclose($handle);

  if (empty($found_rows)) {
    $logger->notice("No reports found for permit #$permit_no in CSV file");
    return $results;
  }

  $results['found'] = count($found_rows);
  $logger->notice("Found " . count($found_rows) . " reports for permit #$permit_no in CSV");

  if ($dry_run) {
    $logger->notice("[DRY RUN] Would import " . count($found_rows) . " reports for permit #$permit_no");
    return $results;
  }

  // Process each found report.
  foreach ($found_rows as $data) {
    // Check if the report already exists.
    $query = \Drupal::entityQuery('node')
      ->condition('type', 'report')
      ->condition('field_permit_no', $permit_no)
      ->condition('field_report_year', $data['report_year'])
      ->accessCheck(FALSE)
      ->range(0, 1);

    $nids = $query->execute();

    if (!empty($nids)) {
      $nid = reset($nids);
      $logger->info("Report for permit #$permit_no, year {$data['report_year']} already exists (NID: $nid)");
      // Count as successful even though we didn't create it.
      $results['imported']++;
      continue;
    }

    // Create a new report.
    try {
      // Generate a title that combines permit number and year.
      $title = sprintf('Report for permit %s - %s', $data['permit_no'], $data['report_year']);

      $node = Node::create([
        'type' => 'report',
        'title' => $title,
        'field_permit_no' => $data['permit_no'],
        'field_report_year' => $data['report_year'],
        'status' => 1,
      ]);

      // Set other fields if they exist in the data.
      if (isset($data['qty_nest_egg_destroyed_mar'])) {
        $node->set('field_qty_nest_egg_destroyed_mar', (int) $data['qty_nest_egg_destroyed_mar']);
      }

      if (isset($data['qty_nest_egg_destroyed_apr'])) {
        $node->set('field_qty_nest_egg_destroyed_apr', (int) $data['qty_nest_egg_destroyed_apr']);
      }

      if (isset($data['qty_nest_egg_destroyed_may'])) {
        $node->set('field_qty_nest_egg_destroyed_may', (int) $data['qty_nest_egg_destroyed_may']);
      }

      if (isset($data['qty_nest_egg_destroyed_jun'])) {
        $node->set('field_qty_nest_egg_destroyed_jun', (int) $data['qty_nest_egg_destroyed_jun']);
      }

      if (isset($data['qty_nest_egg_destroyed_tot'])) {
        $node->set('field_qty_nest_egg_destroyed_tot', (int) $data['qty_nest_egg_destroyed_tot']);
      }

      if (isset($data['location_state'])) {
        $node->set('field_location_state', $data['location_state']);
      }

      if (isset($data['location_county'])) {
        $node->set('field_location_county', $data['location_county']);
      }

      if (isset($data['version_no'])) {
        $node->set('field_version_no', (int) $data['version_no']);
      }

      if (isset($data['hid'])) {
        $node->set('field_hid', $data['hid']);
      }

      // Set the creator if exists and import_users is enabled.
      if (isset($data['create_by']) && $import_users) {
        $user = import_user_by_legacy_id($data['create_by'], NULL, function ($message) {
          // Empty callback function.
        });
        if ($user) {
          $node->setOwnerId($user->id());
          track_imported_user($user);
        }
      }

      $node->save();
      $logger->notice("Created report for permit #$permit_no, year {$data['report_year']} (NID: {$node->id()})");
      $results['imported']++;
    }
    catch (\Exception $e) {
      $logger->error("Error creating report for permit #$permit_no, year {$data['report_year']}: " . $e->getMessage());
      $results['failed']++;
    }
  }

  return $results;
}

/**
 * Function to import a permit by ID from the CSV.
 *
 * @param string $permit_no
 *   The permit number to look for.
 * @param string|null $csv_file
 *   Optional path to the CSV file. If NULL, uses the default.
 * @param callable|null $logger
 *   Optional logger callback function.
 *
 * @return \Drupal\node\NodeInterface|null
 *   The node if found and imported successfully, NULL otherwise.
 */
function import_permit_by_id($permit_no, $csv_file = NULL, ?callable $logger = NULL) {
  // Setup logger - either use the one provided or create a simple one.
  if ($logger === NULL) {
    // Use Drush logger or define a simple closure that outputs messages.
    if (class_exists('\Drush\Drush')) {
      $drush_logger = Drush::logger();
      $logger = function ($message, $variables = []) use ($drush_logger) {
        $drush_logger->notice($message, $variables);
      };
    }
    else {
      $logger = function ($message, $variables = []) {
        // Replace variables in message.
        foreach ($variables as $key => $value) {
          $message = str_replace($key, $value, $message);
        }
        echo $message . "\n";
      };
    }
  }

  // Set default CSV file if not provided.
  if ($csv_file === NULL) {
    $csv_file = __DIR__ . '/data/rcgr_permit_app_mast_202503031405.csv';
  }

  $logger("Looking for permit with ID $permit_no in CSV: $csv_file");

  // Check if the file exists.
  if (!file_exists($csv_file)) {
    $error_msg = "CSV file not found: $csv_file";
    $logger($error_msg);
    if (class_exists('\Drush\Drush')) {
      Drush::logger()->error($error_msg);
    }
    return NULL;
  }

  // Open the CSV file.
  $handle = fopen($csv_file, 'r');
  if ($handle === FALSE) {
    $error_msg = "Could not open CSV file: $csv_file";
    $logger($error_msg);
    if (class_exists('\Drush\Drush')) {
      Drush::logger()->error($error_msg);
    }
    return NULL;
  }

  // Process the header row.
  $header = fgetcsv($handle);
  if ($header === FALSE) {
    $error_msg = "CSV file is empty or incorrectly formatted: $csv_file";
    $logger($error_msg);
    if (class_exists('\Drush\Drush')) {
      Drush::logger()->error($error_msg);
    }
    fclose($handle);
    return NULL;
  }

  // Find the permit number column index.
  $permit_col_index = array_search('permit_no', $header);
  if ($permit_col_index === FALSE) {
    // Check for second column (index 1) which is typically the permit number.
    $permit_col_index = 1;
    $logger("Could not find 'permit_no' column in CSV header, using column index 1 instead.");
  }

  // Search for the permit with the given ID.
  $found_row = NULL;
  while (($row = fgetcsv($handle)) !== FALSE) {
    if (isset($row[$permit_col_index]) && trim($row[$permit_col_index]) === $permit_no) {
      // Make sure the row has enough elements to match the header.
      if (count($row) >= count($header)) {
        $found_row = array_combine($header, $row);
      }
      else {
        $logger("Warning: Row for permit $permit_no has fewer columns than header. Attempting to map available data.");
        // Create array with available data.
        $mapped_row = [];
        foreach ($header as $index => $column_name) {
          $mapped_row[$column_name] = $row[$index] ?? '';
        }
        $found_row = $mapped_row;
      }
      break;
    }
  }

  fclose($handle);

  // If not found in CSV, return NULL.
  if ($found_row === NULL) {
    $error_msg = "No permit found with ID $permit_no in CSV file";
    $logger($error_msg);
    if (class_exists('\Drush\Drush')) {
      Drush::logger()->error($error_msg);
    }
    return NULL;
  }

  $logger("Found permit for ID $permit_no in CSV, importing...");

  // Check if the permit already exists (to avoid duplicates).
  $query = \Drupal::entityQuery('node')
    ->condition('type', 'permit')
    ->condition('field_permit_no', $permit_no)
    ->accessCheck(FALSE)
    ->range(0, 1);
  $nids = $query->execute();

  if (!empty($nids)) {
    $nid = reset($nids);
    $logger("Permit for ID $permit_no already exists (NID: $nid)");
    return Node::load($nid);
  }

  // Create a new permit node.
  try {
    // Check if we have the principal_name field for the title.
    $has_principal_name = isset($found_row['principal_name']) && !empty($found_row['principal_name']);

    $title = $has_principal_name ?
      sprintf('Permit %s - %s', $permit_no, $found_row['principal_name']) :
      "Permit $permit_no";

    $node = Node::create([
      'type' => 'permit',
      'title' => $title,
      'field_permit_no' => $permit_no,
      'status' => 1,
    ]);

    // Map and set field values based on the permit_field_mapping.
    global $_rcgr_permit_field_mapping;
    foreach ($_rcgr_permit_field_mapping as $csv_field => $drupal_field) {
      if (isset($found_row[$csv_field]) && $found_row[$csv_field] !== '') {
        try {
          $node->set($drupal_field, $found_row[$csv_field]);
        }
        catch (\Exception $field_error) {
          $logger("Warning: Could not set field '$drupal_field' for permit $permit_no: " . $field_error->getMessage());
          // Continue with other fields.
        }
      }
    }

    $node->save();
    $logger("Successfully imported permit for ID $permit_no (NID: {$node->id()})");
    return $node;
  }
  catch (\Exception $e) {
    $error_msg = "Error creating permit for ID $permit_no: " . $e->getMessage();
    $logger($error_msg);
    if (class_exists('\Drush\Drush')) {
      Drush::logger()->error($error_msg);
    }
    return NULL;
  }
}

/**
 * Process a single permit with all its related entities.
 *
 * @param string $permit_no
 *   The permit number to process.
 * @param array $options
 *   Import options.
 * @param object $logger
 *   The logger object.
 * @param bool $dry_run
 *   Whether this is a dry run.
 *
 * @return array
 *   Result array with success/failure indicators for each entity type.
 */
function process_permit($permit_no, array $options, $logger, $dry_run = FALSE) {
  $result = [
    'permit' => FALSE,
    'location' => FALSE,
    'users' => 0,
    'reports' => 0,
    'error' => NULL,
  ];

  // Skip processing in dry run mode except logging.
  if ($dry_run) {
    $logger->notice("[DRY RUN] Would process permit: $permit_no");

    if ($options['import_locations']) {
      $logger->notice("[DRY RUN] Would import location for permit: $permit_no");
    }

    if ($options['import_reports']) {
      // Just check if reports exist.
      $reports_csv_file = __DIR__ . '/data/rcgr_report_202503031405.csv';
      if (function_exists('import_reports_for_permit')) {
        $report_count = import_reports_for_permit($permit_no, $reports_csv_file, $logger, TRUE);
        $logger->notice("[DRY RUN] Would import $report_count reports for permit: $permit_no");
      }
      else {
        $logger->warning("[DRY RUN] Report import function not available");
      }
    }

    if ($options['import_users']) {
      $logger->notice("[DRY RUN] Would import related users for permit: $permit_no");
    }

    // Simulate success for dry run.
    return ['permit' => TRUE];
  }

  // Check if the permit already exists.
  $existing_permit = NULL;
  try {
    $query = \Drupal::entityQuery('node')
      ->condition('type', 'permit')
      ->condition('field_permit_no', $permit_no)
      ->accessCheck(FALSE)
      ->range(0, 1);

    $nids = $query->execute();
    if (!empty($nids)) {
      $nid = reset($nids);
      $existing_permit = Node::load($nid);
      if ($existing_permit) {
        $logger->notice("Found existing permit $permit_no with node ID {$existing_permit->id()}");
        $result['permit'] = TRUE;
      }
      else {
        $logger->error("Could not load existing permit node $nid for permit $permit_no");
        $result['error'] = "Could not load existing permit node";
      }
    }
  }
  catch (\Exception $e) {
    $logger->error("Error looking up existing permit $permit_no: " . $e->getMessage());
    $result['error'] = "Error looking up existing permit: " . $e->getMessage();
    return $result;
  }

  // If permit doesn't exist, try to import it.
  if (!$existing_permit) {
    // Try to import permit using our import function.
    if (function_exists('import_permit_by_id')) {
      try {
        $permit = import_permit_by_id($permit_no, NULL, function ($message) use ($logger) {
          $logger->info($message);
        });

        if ($permit) {
          $logger->notice("Successfully imported permit $permit_no (NID: {$permit->id()})");
          $result['permit'] = TRUE;
          $existing_permit = $permit;
        }
        else {
          $logger->error("Failed to import permit $permit_no - permit not found in CSV or could not be created");
          $result['error'] = "Failed to import permit - permit not found in CSV or could not be created";
          // Return early if permit import failed - we need a permit for related entities.
          return $result;
        }
      }
      catch (\Exception $e) {
        $logger->error("Exception during import of permit $permit_no: " . $e->getMessage());
        $result['error'] = "Exception during import: " . $e->getMessage();
        return $result;
      }
    }
    else {
      $logger->error("Import function 'import_permit_by_id' not available");
      $result['error'] = "Import function not available";
      return $result;
    }
  }

  // Import users from the permit if enabled.
  if ($options['import_users'] && $existing_permit) {
    try {
      // Check create_by and update_by fields on the permit if they exist.
      if ($existing_permit->hasField('field_create_by') && !$existing_permit->get('field_create_by')->isEmpty()) {
        $legacy_userid = $existing_permit->get('field_create_by')->value;
        if (!empty($legacy_userid)) {
          $user = import_user_by_legacy_id($legacy_userid, NULL, function ($message) use ($logger) {
            $logger->info($message);
          });

          if ($user && track_imported_user($user)) {
            $logger->notice("Imported user for permit creator: {$user->getAccountName()} (Legacy ID: $legacy_userid)");
            $result['users']++;
          }
        }
      }

      if ($existing_permit->hasField('field_update_by') && !$existing_permit->get('field_update_by')->isEmpty()) {
        $legacy_userid = $existing_permit->get('field_update_by')->value;
        if (!empty($legacy_userid) &&
            (!$existing_permit->hasField('field_create_by') ||
             $legacy_userid != $existing_permit->get('field_create_by')->value)) {
          $user = import_user_by_legacy_id($legacy_userid, NULL, function ($message) use ($logger) {
            $logger->info($message);
          });

          if ($user && track_imported_user($user)) {
            $logger->notice("Imported user for permit updater: {$user->getAccountName()} (Legacy ID: $legacy_userid)");
            $result['users']++;
          }
        }
      }
    }
    catch (\Exception $e) {
      $logger->warning("Error importing users for permit $permit_no: " . $e->getMessage());
      // Continue with other entities even if user import fails.
    }
  }

  // Import location if enabled.
  if ($options['import_locations']) {
    try {
      $existing_location = NULL;
      $query = \Drupal::entityQuery('node')
        ->condition('type', 'location')
        ->condition('field_permit_no', $permit_no)
        ->accessCheck(FALSE)
        ->range(0, 1);

      $nids = $query->execute();
      if (!empty($nids)) {
        $nid = reset($nids);
        $existing_location = Node::load($nid);
        $logger->notice("Found existing location for permit $permit_no (NID: {$existing_location->id()})");
        $result['location'] = TRUE;
      }
      else {
        $logger->notice("Importing location for permit: $permit_no");

        // Import location using the existing function.
        if (function_exists('import_location_by_permit_id')) {
          $location = import_location_by_permit_id($permit_no, NULL, function ($message) use ($logger) {
            $logger->info($message);
          });

          if ($location) {
            $logger->notice("Successfully imported location for permit $permit_no (NID: {$location->id()})");
            $result['location'] = TRUE;
          }
          else {
            $logger->warning("Could not find or import location for permit $permit_no");
          }
        }
        else {
          $logger->warning("Location import function 'import_location_by_permit_id' not available");
        }
      }
    }
    catch (\Exception $e) {
      $logger->warning("Error importing location for permit $permit_no: " . $e->getMessage());
      // Continue with other entities even if location import fails.
    }
  }

  // Import reports if enabled.
  if ($options['import_reports']) {
    try {
      $reports_csv_file = __DIR__ . '/data/rcgr_report_202503031405.csv';

      // Use import_reports_for_permit if available, otherwise try the unique version.
      if (function_exists('import_reports_for_permit')) {
        $report_results = import_reports_for_permit($permit_no, $reports_csv_file, $logger);
        $result['reports'] = $report_results['imported'] ?? 0;
      }
      elseif (function_exists('import_reports_for_permit_unique')) {
        // Use the alternative function if available (from import-permit-and-related.php)
        $report_count = import_reports_for_permit_unique($permit_no, $reports_csv_file, $logger);
        $result['reports'] = $report_count;
      }
      else {
        $logger->warning("Report import functions not available. Please ensure import-reports.php is included.");
      }

      $logger->notice("Processed reports for permit $permit_no: {$result['reports']} imported/found");
    }
    catch (\Exception $e) {
      $logger->warning("Error importing reports for permit $permit_no: " . $e->getMessage());
      // Continue even if report import fails.
    }
  }

  return $result;
}

// Process each permit.
$processed = 0;
$total_permits = count($permit_numbers);
$total_start_time = microtime(TRUE);

// Build options array.
$options = [
  'import_locations' => $import_locations,
  'import_reports' => $import_reports,
  'import_users' => $import_users,
];

foreach ($permit_numbers as $permit_no) {
  $permit_start_time = microtime(TRUE);
  $processed++;

  $logger->notice("Processing permit {$processed}/{$total_permits}: $permit_no");

  // Process the permit and its related entities.
  $permit_results = process_permit($permit_no, $options, $logger, $dry_run);

  // Update overall results.
  if ($permit_results['permit']) {
    $results['permits_success']++;
  }
  else {
    $results['permits_failed']++;
    // Track the specific error for this permit.
    if (isset($permit_results['error'])) {
      if (!isset($results['errors'][$permit_results['error']])) {
        $results['errors'][$permit_results['error']] = 0;
      }
      $results['errors'][$permit_results['error']]++;
    }
  }

  if ($permit_results['location']) {
    $results['locations_found']++;
  }

  $results['reports_imported'] += $permit_results['reports'];
  $results['users_imported'] += $permit_results['users'];

  // Calculate elapsed time for this permit.
  $permit_elapsed = microtime(TRUE) - $permit_start_time;

  // Log progress for the current batch.
  if ($processed % 5 === 0 || $processed === $total_permits) {
    $percent_complete = round(($processed / $total_permits) * 100);
    $total_elapsed = microtime(TRUE) - $total_start_time;
    $avg_time_per_permit = $total_elapsed / $processed;
    $estimated_remaining = ($total_permits - $processed) * $avg_time_per_permit;

    $logger->notice(
      "Progress: {$processed}/{$total_permits} permits processed ({$percent_complete}% complete). " .
      "Time elapsed: " . round($total_elapsed, 1) . "s, Est. remaining: " . round($estimated_remaining, 1) . "s"
    );
  }
}

// Output summary.
$total_elapsed = microtime(TRUE) - $total_start_time;

$logger->notice("Import complete in " . round($total_elapsed, 1) . " seconds.");
$logger->notice("Total permits processed: {$results['total']}");
$logger->notice("Successfully imported/found: {$results['permits_success']}");
$logger->notice("Failed to import: {$results['permits_failed']}");

// If there were any errors, show a summary.
if (!empty($results['errors'])) {
  $logger->notice("Error summary:");
  foreach ($results['errors'] as $error => $count) {
    $logger->notice("  - $error: $count permits");
  }
}

if ($import_locations) {
  $logger->notice("Locations: {$results['locations_found']} found, " .
    "{$results['locations_imported']} imported, {$results['locations_failed']} failed");
}

if ($import_reports) {
  $logger->notice("Reports: {$results['reports_found']} found, " .
    "{$results['reports_imported']} imported, {$results['reports_failed']} failed");
}

if ($import_users) {
  $logger->notice("Users imported: {$results['users_imported']}");
}

echo "\n=== Import Summary ===\n";
echo "Total permits: {$results['total']}\n";
echo "Successfully imported/found: {$results['permits_success']}\n";
echo "Failed to import: {$results['permits_failed']}\n";

// Show error summary in console output too.
if (!empty($results['errors'])) {
  echo "\nError summary:\n";
  foreach ($results['errors'] as $error => $count) {
    echo "  - $error: $count permits\n";
  }
}

if ($import_locations) {
  echo "Locations: {$results['locations_found']} found/imported, {$results['locations_failed']} failed\n";
}

if ($import_reports) {
  echo "Reports: {$results['reports_imported']} imported/found\n";
}

if ($import_users) {
  echo "Users imported: {$results['users_imported']}\n";
}

echo "\nTotal time: " . round($total_elapsed, 1) . " seconds\n";
echo "Average time per permit: " . round($total_elapsed / max(1, $processed), 1) . " seconds\n";
