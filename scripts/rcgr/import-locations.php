<?php

/**
 * @file
 * Imports location data from CSV file into Drupal location nodes.
 */

use Drupal\node\Entity\Node;
use Drupal\Core\Datetime\DrupalDateTime;
use Drush\Drush;
use Drupal\taxonomy\Entity\Term;

// Include the user import functions.
require_once __DIR__ . '/import-users.php';

// Get the limit parameter from command line arguments if provided.
$input = Drush::input();
$args = $input->getArguments();
$limit = isset($args['extra'][1]) ? (int) $args['extra'][1] : PHP_INT_MAX;

$logger = Drush::logger();

// Check if this script is being directly executed (not included).
// If debug_backtrace() has only one item in the array, then this script
// is the entry point. Otherwise it was included by another script.
$is_main_script = count(debug_backtrace()) <= 1;

// Only run the main import code if this is being executed directly.
if ($is_main_script) {
  // Log the limit if specified.
  if ($limit < PHP_INT_MAX) {
    $logger->warning("Limiting import to {$limit} records");
  }
  else {
    $logger->warning("No limit specified - will import all records");
  }

  // Load both CSV files.
  [$current_success, $current_data] = locations_load_csv_data($current_csv_file, $logger);
  [$history_success, $history_data] = locations_load_csv_data($history_csv_file, $logger);

  if (!$current_success) {
    $_rcgr_import_logger->error(sprintf('Failed to load current CSV file: %s', $current_csv_file));
    exit(1);
  }

  $_rcgr_import_logger->notice('Starting import of location data.');

  // Read all historical data into memory for faster lookup.
  $historical_records = [];
  if ($history_success) {
    while (($row = fgetcsv($history_data['handle'])) !== FALSE) {
      // Skip empty rows.
      if (count($row) > 0) {
        $data = array_combine($history_data['header'], $row);
        $key = $data['permit_no'] . ':' . $data['location_address_l1'];
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

    // Skip empty rows.
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
    [$success, $message] = process_location_row(
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

      // Look for and process historical records for this location.
      $key = $data['permit_no'] . ':' . $data['location_address_l1'];
      if (isset($historical_records[$key])) {
        foreach ($historical_records[$key] as $hist_data) {
          [$hist_success, $hist_message] = process_location_row(
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
}

// Set the batch size for processing.
$batch_size = 50;

// Get the CSV file paths.
$current_csv_file = __DIR__ . '/data/rcgr_location_202503031405.csv';
$history_csv_file = __DIR__ . '/data/rcgr_location_hist_202503031405.csv';

// Track processed nodes to handle revisions.
$processed_nodes = [];

// Track users not found and imported.
global $_rcgr_users_not_found;
global $_rcgr_users_imported;
$_rcgr_users_not_found = 0;
$_rcgr_users_imported = 0;

// Map CSV columns to field names.
$field_mapping = [
  'recno' => 'field_recno',
  'isRemoved' => 'field_location_is_removed',
  'permit_no' => 'field_permit_no',
  'bi_cd' => 'field_bi_cd',
  'location_address_l1' => 'field_location_address',
  'location_county' => 'field_location_county',
  'location_city' => 'field_location_city',
  'location_state' => 'field_location_state_ref',
  'report_year' => 'field_location_report_year',
  'qty_nest_egg_destroyed_mar' => 'field_location_qty_nest_egg_mar',
  'qty_nest_egg_destroyed_apr' => 'field_location_qty_nest_egg_apr',
  'qty_nest_egg_destroyed_may' => 'field_location_qty_nest_egg_may',
  'qty_nest_egg_destroyed_jun' => 'field_location_qty_nest_egg_jun',
  'qty_nest_egg_destroyed_tot' => 'field_location_qty_nest_egg_tot',
  'isLocationCertified' => 'field_location_is_certified',
  'ca_access_key' => 'field_ca_access_key',
  'version_no' => 'field_version_no',
  'hid' => 'field_hid',
  'site_id' => 'field_site_id',
  'control_site_id' => 'field_control_site_id',
  'dt_create' => 'field_dt_create',
  'dt_update' => 'field_dt_update',
  'create_by' => 'field_create_by',
  'update_by' => 'field_update_by',
  'xml_cd' => 'field_xml_cd',
  'rcf_cd' => 'field_rcf_cd',
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
function locations_get_taxonomy_term_id($name, $vocabulary, $create_if_missing = TRUE, array &$term_cache = [], array $value_mappings = [], $force_new_term = FALSE) {
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
 * Find existing node for a given permit number and location.
 *
 * @param string $permit_no
 *   The permit number.
 * @param string $address
 *   The location address.
 *
 * @return \Drupal\node\NodeInterface|null
 *   The node if found, null otherwise.
 */
function find_existing_node($permit_no, $address) {
  $query = \Drupal::entityQuery('node')
    ->condition('type', 'location')
    ->condition('field_permit_no', $permit_no)
    ->condition('field_location_address', $address)
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
function locations_find_user_by_legacy_id($legacy_userid, $import_if_not_found = FALSE) {
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
 * Process a single row of location data.
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
 *   Whether this is a historical revision.
 * @param array &$processed_nodes
 *   Reference to array of processed nodes.
 *
 * @return array
 *   Array containing success status and any messages.
 */
function process_location_row(
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
    $_rcgr_import_logger->info('Processing @type record: Permit #@permit, Address: @address', [
      '@type' => $is_revision ? 'historical' : 'current',
      '@permit' => $data['permit_no'],
      '@address' => $data['location_address_l1'] ?: '[empty]',
    ]);

    // For revisions, try to find existing node.
    $existing_node = NULL;
    if ($is_revision) {
      $existing_node = find_existing_node($data['permit_no'], $data['location_address_l1']);
      if (!$existing_node) {
        return [
          FALSE,
          sprintf(
            'No existing node found for permit %s at %s - skipping revision',
            $data['permit_no'],
            $data['location_address_l1']
          ),
        ];
      }
      $node = $existing_node;
      $node->setNewRevision(TRUE);
      $node->revision_log = sprintf(
        'Historical revision imported from year %s. Created by %s, Updated by %s',
        $data['report_year'],
        $data['create_by'],
        $data['update_by']
      );
    }
    else {
      // Create new node for current data.
      // Generate a title that combines permit number and timestamp if address is empty.
      $title = !empty($data['location_address_l1']) ? $data['location_address_l1'] :
        sprintf('Location %s (%s)', $data['permit_no'], date('Y-m-d H:i:s'));

      $node = Node::create([
        'type' => 'location',
        'title' => $title,
        'status' => 1,
      ]);
    }

    // Associate the location entity with a user based on legacy userid.
    if (!empty($data['create_by'])) {
      $uid = locations_find_user_by_legacy_id($data['create_by'], FALSE);
      if ($uid) {
        // Set the node owner to the user with the matching legacy ID.
        $node->setOwnerId($uid);
      }
    }

    // Handle combined address fields first.
    $address = $data['location_address_l1'];
    if (!empty($data['location_address_l2'])) {
      $address .= "\n" . $data['location_address_l2'];
    }
    if (!empty($data['location_address_l3'])) {
      $address .= "\n" . $data['location_address_l3'];
    }
    $node->set('field_location_address', $address);

    // Handle California county selection if this is a CA location.
    if (!empty($data['location_state']) && $data['location_state'] === 'CA' && !empty($data['location_county'])) {
      $county_name = trim($data['location_county']);
      $tid = locations_get_taxonomy_term_id($county_name, 'restricted_counties', FALSE, $term_cache);
      if ($tid) {
        $node->set(
          'field_ca_county_select',
          ['target_id' => $tid]
        );
      }
      else {
        $_rcgr_import_logger->warning(
          'Could not find county term "@county" in restricted_counties vocabulary (Permit #@permit)',
          [
            '@county' => $county_name,
            '@permit' => $data['permit_no'],
          ]
        );
      }
    }

    // Map and set field values.
    foreach ($field_mapping as $csv_field => $drupal_field) {
      // Skip address fields as we've already handled them.
      if ($drupal_field === 'field_location_address') {
        continue;
      }

      if (!isset($data[$csv_field])) {
        $_rcgr_import_logger->notice('Field @field not found in CSV data for permit #@permit', [
          '@field' => $csv_field,
          '@permit' => $data['permit_no'],
        ]);
        continue;
      }

      $value = $data[$csv_field];

      // Handle special cases.
      switch ($drupal_field) {
        case 'field_location_is_removed':
        case 'field_location_is_certified':
          $node->set($drupal_field, (bool) $value);
          break;

        case 'field_location_state_ref':
          // These are entity references - we'll need to look up the target ID.
          if (!empty($value)) {
            $entity_type = 'taxonomy_term';
            $bundle = 'states';

            $state_code = trim(strtoupper($value));
            $tid = locations_get_taxonomy_term_id($state_code, $bundle, TRUE, $term_cache, $value_mappings);

            if ($tid) {
              $node->set($drupal_field, ['target_id' => $tid]);
            }
            else {
              $_rcgr_import_logger->warning('Could not find or create state term for @state (Permit #@permit)', [
                '@state' => $value,
                '@permit' => $data['permit_no'],
              ]);
            }
          }
          break;

        case 'field_dt_create':
        case 'field_dt_update':
          if (!empty($value)) {
            $date = new DrupalDateTime($value);
            $node->set($drupal_field, $date->format('Y-m-d\TH:i:s'));

            // For revisions, also set the revision timestamp.
            if ($is_revision && $drupal_field === 'field_dt_update') {
              $node->setRevisionCreationTime($date->getTimestamp());
            }
          }
          break;

        case 'field_recno':
        case 'field_location_report_year':
        case 'field_version_no':
          $node->set($drupal_field, (int) $value);
          break;

        case 'field_location_qty_nest_egg_mar':
        case 'field_location_qty_nest_egg_apr':
        case 'field_location_qty_nest_egg_may':
        case 'field_location_qty_nest_egg_jun':
        case 'field_location_qty_nest_egg_tot':
          $node->set($drupal_field, (int) $value);
          break;

        case 'field_rcf_cd':
          if (!empty($value)) {
            $entity_type = 'taxonomy_term';
            $bundle = 'rcf_cd';

            $rcf_code = trim(strtoupper($value));
            $tid = locations_get_taxonomy_term_id($rcf_code, $bundle, TRUE, $term_cache, $value_mappings);

            if ($tid) {
              $node->set($drupal_field, ['target_id' => $tid]);
            }
            else {
              $_rcgr_import_logger->warning('Could not find or create RCF code term for @code (Permit #@permit)', [
                '@code' => $value,
                '@permit' => $data['permit_no'],
              ]);
            }
          }
          break;

        default:
          // Handle array of field mappings (for fields that map to multiple destinations)
          if (is_array($drupal_field)) {
            foreach ($drupal_field as $target_field) {
              if ($target_field === 'field_ca_county_select' && !empty($value) && $data['location_state'] === 'CA') {
                // Look up the county in the restricted_counties vocabulary.
                $tid = locations_get_taxonomy_term_id($value, 'restricted_counties', FALSE, $term_cache);
                if ($tid) {
                  $node->set($target_field, ['target_id' => $tid]);
                }
                else {
                  $_rcgr_import_logger->warning('Could not find county term for @county in restricted_counties (Permit #@permit)', [
                    '@county' => $value,
                    '@permit' => $data['permit_no'],
                  ]);
                }
              }
              else {
                $node->set($target_field, $value);
              }
            }
          }
          else {
            $node->set($drupal_field, $value);
          }
          break;
      }
    }

    // For revisions, set the revision author if we can find a matching user.
    if ($is_revision && !empty($data['update_by'])) {
      $users = \Drupal::entityTypeManager()
        ->getStorage('user')
        ->loadByProperties(['name' => $data['update_by']]);
      if (!empty($users)) {
        $user = reset($users);
        $node->setRevisionUserId($user->id());
      }
    }

    // Save the node.
    $node->save();

    // Track processed nodes.
    $key = $data['permit_no'] . ':' . $data['location_address_l1'];
    $processed_nodes[$key] = $node->id();

    $_rcgr_import_logger->info('Successfully saved @type node @nid for permit #@permit', [
      '@type' => $is_revision ? 'historical' : 'current',
      '@nid' => $node->id(),
      '@permit' => $data['permit_no'],
    ]);

    return [TRUE, $is_revision ? "Created revision" : "Created/updated node"];
  }
  catch (Exception $e) {
    return [
      FALSE,
      "Error processing record for permit #{$data['permit_no']}: " . $e->getMessage(),
    ];
  }
}

/**
 * Function to load and validate CSV data.
 */
function locations_load_csv_data($file_path, $logger) {
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

  return [
    TRUE,
    [
      'handle' => $handle,
      'header' => $header,
    ],
  ];
}

/**
 * Function to import a location by permit ID from the CSV.
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
function import_location_by_permit_id($permit_no, $csv_file = NULL, ?callable $logger = NULL) {
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
    $csv_file = __DIR__ . '/data/rcgr_location_202503031405.csv';
  }

  $logger("Looking for location with permit #$permit_no in CSV: $csv_file");

  // Check if the file exists.
  if (!file_exists($csv_file)) {
    $logger("CSV file not found: $csv_file");
    return NULL;
  }

  // Open the CSV file.
  $handle = fopen($csv_file, 'r');
  if ($handle === FALSE) {
    $logger("Could not open CSV file: $csv_file");
    return NULL;
  }

  // Process the header row.
  $header = fgetcsv($handle);
  if ($header === FALSE) {
    $logger("CSV file is empty or incorrectly formatted: $csv_file");
    fclose($handle);
    return NULL;
  }

  // Find the permit number column index.
  $permit_col_index = array_search('permit_no', $header);
  if ($permit_col_index === FALSE) {
    $logger("Could not find 'permit_no' column in CSV header");
    fclose($handle);
    return NULL;
  }

  // Search for the location with the given permit number.
  $found_row = NULL;
  while (($row = fgetcsv($handle)) !== FALSE) {
    if (isset($row[$permit_col_index]) && trim($row[$permit_col_index]) === $permit_no) {
      $found_row = array_combine($header, $row);
      break;
    }
  }

  fclose($handle);

  // If not found in CSV, return NULL.
  if ($found_row === NULL) {
    $logger("No location found with permit #$permit_no in CSV file");
    return NULL;
  }

  $logger("Found location for permit #$permit_no in CSV, importing...");

  // Setup necessary components for processing.
  $term_cache = [];
  $processed_nodes = [];
  $value_mappings = [
    'U' => 'Unknown',
    'A' => 'Active',
    'C' => 'Complete',
    'I' => 'Inactive',
  ];

  // Define the field mapping.
  $field_mapping = [
    'recno' => 'field_recno',
    'isRemoved' => 'field_location_is_removed',
    'permit_no' => 'field_permit_no',
    'bi_cd' => 'field_bi_cd',
    'location_address_l1' => 'field_location_address',
    'location_county' => 'field_location_county',
    'location_city' => 'field_location_city',
    'location_state' => 'field_location_state_ref',
    'report_year' => 'field_location_report_year',
    'qty_nest_egg_destroyed_mar' => 'field_location_qty_nest_egg_mar',
    'qty_nest_egg_destroyed_apr' => 'field_location_qty_nest_egg_apr',
    'qty_nest_egg_destroyed_may' => 'field_location_qty_nest_egg_may',
    'qty_nest_egg_destroyed_jun' => 'field_location_qty_nest_egg_jun',
    'qty_nest_egg_destroyed_tot' => 'field_location_qty_nest_egg_tot',
    'isLocationCertified' => 'field_location_is_certified',
    'ca_access_key' => 'field_ca_access_key',
    'version_no' => 'field_version_no',
    'hid' => 'field_hid',
    'site_id' => 'field_site_id',
    'control_site_id' => 'field_control_site_id',
    'dt_create' => 'field_dt_create',
    'dt_update' => 'field_dt_update',
    'create_by' => 'field_create_by',
    'update_by' => 'field_update_by',
    'xml_cd' => 'field_xml_cd',
    'rcf_cd' => 'field_rcf_cd',
  ];

  // Save the logger in the global variable for process_location_row.
  global $_rcgr_import_logger;
  $_rcgr_import_logger = new class($logger) {
    /**
     * The logger callback function.
     *
     * @var callable
     */
    private $logger;

    /**
     * Constructor.
     *
     * @param callable $logger
     *   The logger callback function.
     */
    public function __construct(callable $logger) {
      $this->logger = $logger;
    }

    /**
     * Log a notice message.
     *
     * @param string $message
     *   The message to log.
     * @param array $context
     *   Context variables for the message.
     *
     * @return mixed
     *   The result of the logger call.
     */
    public function notice(string $message, array $context = []) {
      return call_user_func($this->logger, $message, $context);
    }

    /**
     * Log an info message.
     *
     * @param string $message
     *   The message to log.
     * @param array $context
     *   Context variables for the message.
     *
     * @return mixed
     *   The result of the logger call.
     */
    public function info(string $message, array $context = []) {
      return call_user_func($this->logger, $message, $context);
    }

    /**
     * Log a warning message.
     *
     * @param string $message
     *   The message to log.
     * @param array $context
     *   Context variables for the message.
     *
     * @return mixed
     *   The result of the logger call.
     */
    public function warning(string $message, array $context = []) {
      return call_user_func($this->logger, $message, $context);
    }

    /**
     * Log an error message.
     *
     * @param string $message
     *   The message to log.
     * @param array $context
     *   Context variables for the message.
     *
     * @return mixed
     *   The result of the logger call.
     */
    public function error(string $message, array $context = []) {
      return call_user_func($this->logger, $message, $context);
    }

    /**
     * Log a debug message.
     *
     * @param string $message
     *   The message to log.
     * @param array $context
     *   Context variables for the message.
     *
     * @return mixed
     *   The result of the logger call.
     */
    public function debug(string $message, array $context = []) {
      return call_user_func($this->logger, $message, $context);
    }

  };

  // Check if the location already exists (to avoid duplicates).
  $existing_node = find_existing_node($permit_no, $found_row['location_address_l1']);
  if ($existing_node) {
    $logger("Location for permit #$permit_no already exists (NID: {$existing_node->id()})");
    return $existing_node;
  }

  // Process the location row.
  [$success, $message] = process_location_row(
    $found_row,
    $field_mapping,
    $term_cache,
    $value_mappings,
    FALSE,
    $processed_nodes
  );

  if (!$success) {
    $logger("Failed to import location for permit #$permit_no: $message");
    return NULL;
  }

  // Get the node ID from the processed nodes array.
  $key = $permit_no . ':' . $found_row['location_address_l1'];
  if (!isset($processed_nodes[$key])) {
    $logger("Location was processed but not recorded in processed nodes array");
    return NULL;
  }

  $nid = $processed_nodes[$key];
  $node = \Drupal::entityTypeManager()->getStorage('node')->load($nid);

  if (!$node) {
    $logger("Could not load the newly created location node with NID: $nid");
    return NULL;
  }

  $logger("Successfully imported location for permit #$permit_no (NID: {$node->id()})");
  return $node;
}
