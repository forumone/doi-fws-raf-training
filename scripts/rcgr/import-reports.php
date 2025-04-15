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
    $_rcgr_import_logger->debug("Empty legacy_userid provided to find_user_by_legacy_id");
    return NULL;
  }

  // Trim whitespace from the legacy user ID.
  $legacy_userid = trim($legacy_userid);

  if (empty($legacy_userid)) {
    $_rcgr_import_logger->debug("Legacy user ID is empty after trimming whitespace");
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

  $_rcgr_import_logger->notice("Attempting to import user with legacy ID {$legacy_userid}");

  // Logger callback function for the import process that suppresses output.
  $log_via_logger = function ($message) use ($_rcgr_import_logger) {
    $_rcgr_import_logger->debug($message);
  };

  // Try to import the user from the original CSV.
  $user = import_user_by_legacy_id($legacy_userid, NULL, $log_via_logger);

  if ($user) {
    $_rcgr_users_imported++;
    $_rcgr_import_logger->notice("Imported user {$user->id()} for legacy ID {$legacy_userid}");
    return $user->id();
  }

  // Try one more time with a different file if the first attempt failed.
  $_rcgr_import_logger->notice("First import attempt failed. Trying alternate CSV file for legacy ID {$legacy_userid}");
  $alternate_csv = __DIR__ . '/data/rcgr_userprofile_no_passwords_202503031405.csv';

  if (file_exists($alternate_csv)) {
    $user = import_user_by_legacy_id($legacy_userid, $alternate_csv, $log_via_logger);

    if ($user) {
      $_rcgr_users_imported++;
      $_rcgr_import_logger->notice("Imported user {$user->id()} from alternate CSV for legacy ID {$legacy_userid}");
      return $user->id();
    }
  }

  $_rcgr_users_not_found++;
  $_rcgr_import_logger->warning("Could not find or import user with legacy ID {$legacy_userid}");
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
 * Ensure a permit exists, creating a complete one if necessary.
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
  static $permit_csv_data = NULL;

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
  $logger->notice(sprintf('No existing permit found for %s, attempting to create it from CSV...', $permit_no));

  // Load CSV data if not already loaded.
  if ($permit_csv_data === NULL) {
    $csv_file = __DIR__ . '/data/rcgr_permit_app_mast_202503031405.csv';
    if (!file_exists($csv_file)) {
      $logger->error(sprintf('Permit CSV file not found: %s', $csv_file));

      // Fall back to creating a minimal permit if CSV not found.
      return create_minimal_permit($permit_no, $logger);
    }

    $handle = fopen($csv_file, 'r');
    if (!$handle) {
      $logger->error(sprintf('Could not open permit CSV file: %s', $csv_file));
      return create_minimal_permit($permit_no, $logger);
    }

    $header = fgetcsv($handle);
    if (!$header) {
      $logger->error('Could not read header from permit CSV file');
      fclose($handle);
      return create_minimal_permit($permit_no, $logger);
    }

    // Read all permits into memory indexed by permit number.
    $permit_csv_data = [];
    while (($row = fgetcsv($handle)) !== FALSE) {
      if (count($row) > 1) {
        $data = array_combine($header, $row);
        if (!empty($data['permit_no'])) {
          $permit_csv_data[$data['permit_no']] = $data;
        }
      }
    }

    fclose($handle);
    $logger->notice(sprintf('Loaded %d permits from CSV', count($permit_csv_data)));
  }

  // Try to find the permit in the CSV data.
  if (isset($permit_csv_data[$permit_no])) {
    // Create a complete permit with all CSV data.
    try {
      $csv_data = $permit_csv_data[$permit_no];

      // Define field mappings.
      $field_mapping = [
        'permit_no' => 'field_permit_no',
        'version_no' => 'field_version_no',
        'site_id' => 'field_site_id',
        'control_site_id' => 'field_control_site_id',
        'create_by' => 'field_create_by',
        'update_by' => 'field_update_by',
        'dt_create' => 'field_dt_create',
        'dt_update' => 'field_dt_update',
        'dt_applicant_signed' => 'field_dt_applicant_signed',
        'dt_permit_request' => 'field_dt_permit_request',
        'dt_permit_issued' => 'field_dt_permit_issued',
        'dt_effective' => 'field_dt_effective',
        'dt_expired' => 'field_dt_expired',
        'dt_application_received' => 'field_dt_application_received',
        'dt_signed' => 'field_dt_signed',
        'principal_name' => 'field_principal_name',
        'principal_first_name' => 'field_principal_first_name',
        'principal_middle_name' => 'field_principal_middle_name',
        'principal_last_name' => 'field_principal_last_name',
        'principal_suffix' => 'field_principal_suffix',
        'principal_title' => 'field_principal_title',
        'principal_telephone' => 'field_principal_telephone',
        'applicant_state' => 'field_applicant_state',
        'registrant_type_cd' => 'field_registrant_type_cd',
        'permit_status_cd' => 'field_permit_status_cd',
        'rcf_cd' => 'field_rcf_cd',
        'xml_cd' => 'field_xml_cd',
        'hid' => 'field_hid',
      ];

      // Define date fields.
      $date_fields = [
        'field_dt_create',
        'field_dt_update',
        'field_dt_applicant_signed',
        'field_dt_permit_request',
        'field_dt_permit_issued',
        'field_dt_effective',
        'field_dt_expired',
        'field_dt_application_received',
        'field_dt_signed',
      ];

      // Define taxonomy fields.
      $taxonomy_fields = [
        'field_applicant_state' => 'states',
        'field_registrant_type_cd' => 'registrant_type',
        'field_permit_status_cd' => 'application_status',
        'field_rcf_cd' => 'restricted_counties',
      ];

      // Create node.
      $node = Node::create([
        'type' => 'permit',
        'title' => $permit_no,
        'status' => 1,
      ]);

      // Set creation time.
      if (!empty($csv_data['dt_create'])) {
        try {
          $date = new \DateTime($csv_data['dt_create']);
          $node->setCreatedTime($date->getTimestamp());
        }
        catch (\Exception $e) {
          $logger->warning(sprintf('Could not parse creation date: %s', $csv_data['dt_create']));
        }
      }

      // Set owner based on create_by.
      if (!empty($csv_data['create_by'])) {
        $uid = find_user_by_legacy_id($csv_data['create_by'], TRUE);
        if ($uid) {
          $node->setOwnerId($uid);
        }
        else {
          $node->setOwnerId(1);
        }
      }
      else {
        $node->setOwnerId(1);
      }

      // Term cache for taxonomy lookups.
      $term_cache = [];

      // Set field values.
      foreach ($field_mapping as $csv_field => $drupal_field) {
        if (!isset($csv_data[$csv_field]) || !$node->hasField($drupal_field)) {
          continue;
        }

        $value = $csv_data[$csv_field];

        // Skip empty values.
        if (empty($value)) {
          continue;
        }

        // Handle different field types.
        if (in_array($drupal_field, $date_fields)) {
          try {
            $date = new \DateTime($value);
            $formatted_date = $date->format('Y-m-d\TH:i:s');
            $node->set($drupal_field, $formatted_date);
          }
          catch (\Exception $e) {
            $logger->warning(sprintf('Could not parse date %s for field %s', $value, $drupal_field));
          }
        }
        elseif (isset($taxonomy_fields[$drupal_field])) {
          $vocab = $taxonomy_fields[$drupal_field];
          $tid = get_taxonomy_term_id($value, $vocab, TRUE, $term_cache);
          if ($tid) {
            $node->set($drupal_field, $tid);
          }
        }
        else {
          $node->set($drupal_field, $value);
        }
      }

      // Save the node.
      $node->save();

      $logger->notice(sprintf('Successfully created complete permit %s (NID: %d) from CSV data',
        $permit_no, $node->id()));
      $verified_permits[$permit_no] = TRUE;

      return TRUE;
    }
    catch (\Exception $e) {
      $logger->error(sprintf('Error creating permit %s from CSV: %s', $permit_no, $e->getMessage()));
      // Fall back to minimal permit.
      return create_minimal_permit($permit_no, $logger);
    }
  }
  else {
    $logger->warning(sprintf('No data found in CSV for permit %s', $permit_no));
    // Fall back to minimal permit.
    return create_minimal_permit($permit_no, $logger);
  }
}

/**
 * Create a minimal permit node with just the permit number.
 */
function create_minimal_permit($permit_no, $logger) {
  try {
    $node = Node::create([
      'type' => 'permit',
      'title' => $permit_no,
      'field_permit_no' => $permit_no,
      'status' => 1,
    ]);

    $node->save();
    $logger->notice(sprintf('Successfully created basic permit %s (NID: %d)', $permit_no, $node->id()));
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
      $uid = find_user_by_legacy_id($data['create_by'], TRUE);
      if ($uid) {
        // Set the node owner to the user with the matching legacy ID.
        $node->setOwnerId($uid);
        $_rcgr_import_logger->info(sprintf('Set owner for report to user %d (legacy ID: %s)',
          $uid,
          $data['create_by']
        ));
      }
      else {
        // If we couldn't find the user, default to user 1 (admin) instead of leaving it unattributed.
        $_rcgr_import_logger->warning(sprintf('Could not find user with legacy ID %s. Setting owner to admin user.',
          $data['create_by']
        ));
        $node->setOwnerId(1);
      }
    }
    else {
      // If no create_by is specified, set to admin (user 1)
      $_rcgr_import_logger->notice('No create_by specified for report. Setting owner to admin user.');
      $node->setOwnerId(1);
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
      $uid = find_user_by_legacy_id($data['update_by'], TRUE);
      if ($uid) {
        $node->setRevisionUserId($uid);
      }
    }

    // Save the node.
    $node->save();

    // Also update the related permit with the same owner.
    if (!$is_revision && !empty($data['permit_no'])) {
      try {
        // Find the permit node.
        $permit_query = \Drupal::entityQuery('node')
          ->condition('type', 'permit')
          ->condition('field_permit_no', $data['permit_no'])
          ->accessCheck(FALSE)
          ->range(0, 1);

        $permit_nids = $permit_query->execute();

        if (!empty($permit_nids)) {
          $permit_nid = reset($permit_nids);
          $permit = Node::load($permit_nid);

          if ($permit) {
            // Set the permit owner to the same as the report if the current owner is anonymous or admin.
            $current_permit_owner = $permit->getOwnerId();
            if ($current_permit_owner == 0 || $current_permit_owner == 1) {
              $report_owner = $node->getOwnerId();
              if ($report_owner > 0) {
                $permit->setOwnerId($report_owner);
                $permit->save();
                $_rcgr_import_logger->notice(sprintf('Updated permit %s (NID: %d) owner to match report owner (UID: %d)',
                  $data['permit_no'],
                  $permit_nid,
                  $report_owner
                ));
              }
            }
          }
        }
      }
      catch (\Exception $e) {
        $_rcgr_import_logger->warning(sprintf('Error updating permit owner for %s: %s',
          $data['permit_no'],
          $e->getMessage()
        ));
      }
    }

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
