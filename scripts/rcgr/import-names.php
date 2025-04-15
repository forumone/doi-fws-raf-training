<?php

/**
 * @file
 * Imports name data from CSV file into Drupal name nodes.
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

// Log the limit if specified.
if ($limit < PHP_INT_MAX) {
  $logger->notice("Limiting import to {$limit} records");
}
else {
  $logger->notice("No limit specified - will import all records");
}

// Set the batch size for processing.
$batch_size = 50;

// Get the CSV file paths.
$current_csv_file = __DIR__ . '/data/rcgr_name_202503031405.csv';
$history_csv_file = __DIR__ . '/data/rcgr_name_hist_202503031405.csv';

$logger->warning("Starting import of name data.");

// Track users not found.
global $_rcgr_users_not_found;
global $_rcgr_users_imported;
$_rcgr_users_not_found = 0;
$_rcgr_users_imported = 0;

// Load historical records into memory for faster lookup.
$historical_records = [];
if (file_exists($history_csv_file)) {
  $logger->warning("Loading historical records from {$history_csv_file}");
  $history_handle = fopen($history_csv_file, 'r');
  if ($history_handle === FALSE) {
    $logger->error('Could not open historical CSV file');
    exit(1);
  }

  // Read header row.
  $history_header = fgetcsv($history_handle);
  if ($history_header === FALSE) {
    $logger->error('Could not read historical CSV header');
    fclose($history_handle);
    exit(1);
  }

  // Load all historical records into memory, grouped by permit number.
  while (($data = fgetcsv($history_handle)) !== FALSE) {
    $row = array_combine($history_header, $data);
    if (!empty($row['permit_no'])) {
      if (!isset($historical_records[$row['permit_no']])) {
        $historical_records[$row['permit_no']] = [];
      }
      $historical_records[$row['permit_no']][] = $row;
    }
  }
  fclose($history_handle);

  $logger->warning("Loaded " . count($historical_records) . " sets of historical records.");
}

// Check if current data file exists.
if (!file_exists($current_csv_file)) {
  $logger->error('Current CSV file not found at @file', ['@file' => $current_csv_file]);
  exit(1);
}

// Open the current CSV file.
$handle = fopen($current_csv_file, 'r');
if ($handle === FALSE) {
  $logger->error('Could not open current CSV file');
  exit(1);
}

// Read the header row.
$header = fgetcsv($handle);
if ($header === FALSE) {
  \Drupal::logger('rcgr')->error('Could not read CSV header');
  fclose($handle);
  exit(1);
}

// Map CSV columns to field names.
$field_mappings = [
  'recno' => 'field_recno',
  'isRemoved' => 'field_is_removed',
  'permit_no' => 'field_permit_no',
  'report_year' => 'field_report_year',
  'person_name' => 'field_person_name',
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
$processed = 0;
$skipped = 0;
$errors = 0;
$revisions_created = 0;

// Initialize taxonomy term cache.
$term_cache = [];

// Define value mappings for taxonomy term values that need translation.
$taxonomy_value_mappings = [
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

  // Check the cache first.
  $cache_key = "{$vocabulary}:{$name}";
  if (!$force_new_term && isset($term_cache[$cache_key])) {
    return $term_cache[$cache_key];
  }

  // Check if term already exists.
  $query = \Drupal::entityQuery('taxonomy_term')
    ->condition('vid', $vocabulary)
    ->condition('name', $name)
    ->accessCheck(FALSE);

  $tids = $query->execute();

  if (!empty($tids)) {
    $tid = reset($tids);
    $term_cache[$cache_key] = $tid;
    return $tid;
  }

  // Create the term if it doesn't exist and we're allowed to.
  if ($create_if_missing) {
    try {
      $term = Term::create([
        'vid' => $vocabulary,
        'name' => $name,
      ]);
      $term->save();
      $term_cache[$cache_key] = $term->id();
      $_rcgr_import_logger->warning("Created new {$vocabulary} term: {$name}");
      return $term->id();
    }
    catch (\Exception $e) {
      $_rcgr_import_logger->error("Failed to create term '{$name}' in vocabulary '{$vocabulary}': " . $e->getMessage());
      return NULL;
    }
  }

  return NULL;
}

/**
 * Import names from CSV for a specific permit number.
 *
 * @param string $permit_no
 *   The permit number to import names for.
 * @param string $report_year
 *   The report year.
 * @param object $logger
 *   The logger object.
 *
 * @return array
 *   Array with count of names created/updated.
 */
function import_names_for_permit($permit_no, $report_year, $logger) {
  // Track which permits we've already processed to avoid duplicates.
  static $_rcgr_imported_permits = [];

  // If we've already imported names for this permit, skip.
  if (isset($_rcgr_imported_permits[$permit_no])) {
    return ['created' => 0, 'updated' => 0, 'skipped' => 0];
  }

  $result = [
    'created' => 0,
    'updated' => 0,
    'skipped' => 0,
  ];

  $logger->notice(sprintf('Importing names for permit %s, year %s', $permit_no, $report_year));

  // Set the path to the names CSV file.
  $names_csv_file = __DIR__ . '/data/rcgr_name_202503031405.csv';

  // Check if names CSV file exists.
  if (!file_exists($names_csv_file)) {
    $logger->error(sprintf('Names CSV file not found: %s', $names_csv_file));
    return $result;
  }

  // Open CSV file.
  $handle = fopen($names_csv_file, 'r');
  if (!$handle) {
    $logger->error(sprintf('Could not open names CSV file: %s', $names_csv_file));
    return $result;
  }

  // Read header row.
  $header = fgetcsv($handle);
  if (!$header) {
    $logger->error('Could not read header row from names CSV');
    fclose($handle);
    return $result;
  }

  // Map CSV columns to field names.
  $field_mappings = [
    'recno' => 'field_recno',
    'isRemoved' => 'field_is_removed',
    'permit_no' => 'field_permit_no',
    'report_year' => 'field_report_year',
    'person_name' => 'field_person_name',
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

  // Define value mappings for taxonomy term values.
  $taxonomy_value_mappings = [
    'U' => 'Unknown',
    'A' => 'Active',
    'C' => 'Complete',
    'I' => 'Inactive',
  ];

  // Term cache for taxonomy lookups.
  $term_cache = [];

  // Process CSV rows.
  while (($row = fgetcsv($handle)) !== FALSE) {
    // Skip empty rows.
    if (count($row) <= 1 && empty($row[0])) {
      continue;
    }

    // Create associative array.
    $data = array_combine($header, $row);

    // Skip if not matching permit number.
    if (empty($data['permit_no']) || $data['permit_no'] !== $permit_no) {
      continue;
    }

    try {
      // Check if a node with this recno already exists.
      $query = \Drupal::entityQuery('node')
        ->condition('type', 'name')
        ->condition('field_recno', $data['recno'])
        ->accessCheck(FALSE);
      $nids = $query->execute();

      // Load or create the node.
      if (!empty($nids)) {
        $nid = reset($nids);
        $node = Node::load($nid);
        $is_new = FALSE;
        $result['updated']++;
      }
      else {
        $node = Node::create(['type' => 'name']);
        $is_new = TRUE;
        $result['created']++;
      }

      // Set the title to the person's name.
      $node->setTitle($data['person_name']);

      // Set simple field values.
      foreach ($field_mappings as $csv_column => $field_name) {
        if (isset($data[$csv_column]) && $data[$csv_column] !== '') {
          $value = $data[$csv_column];

          // Handle boolean fields.
          if (in_array($field_name, ['field_is_removed'])) {
            $value = strtolower($value) === 'true' || $value === '1';
          }

          // Handle date fields.
          if (in_array($field_name, ['field_dt_create', 'field_dt_update'])) {
            if (!empty($value)) {
              try {
                $date = new DrupalDateTime($value);
                $value = $date->format('Y-m-d\TH:i:s');
              }
              catch (\Exception $e) {
                $logger->warning(sprintf('Invalid date format for %s: %s', $field_name, $value));
                continue;
              }
            }
            else {
              continue;
            }
          }

          // Handle taxonomy reference fields.
          if (in_array($field_name, ['field_rcf_cd'])) {
            $term_id = get_taxonomy_term_id($value, str_replace('field_', '', $field_name), TRUE, $term_cache, $taxonomy_value_mappings);
            if ($term_id) {
              $node->set($field_name, ['target_id' => $term_id]);
            }
            continue;
          }

          // Set the field value.
          $node->set($field_name, $value);
        }
      }

      // Associate the name entity with a user based on legacy userid.
      if (!empty($data['create_by'])) {
        $uid = find_user_by_legacy_id($data['create_by'], TRUE);
        if ($uid > 0) {
          // Set the node owner to the user with the matching legacy ID.
          $node->setOwnerId($uid);
        }
        else {
          // If we couldn't find the user, default to anonymous user (0).
          $node->setOwnerId(0);
        }
      }
      else {
        // If no create_by is specified, set to anonymous user (0).
        $node->setOwnerId(0);
      }

      // Set creation time if dt_create is available.
      if (!empty($data['dt_create'])) {
        try {
          $date = new DrupalDateTime($data['dt_create']);
          $node->setCreatedTime($date->getTimestamp());
        }
        catch (\Exception $e) {
          $logger->warning(sprintf('Could not parse creation date from %s', $data['dt_create']));
        }
      }

      // Set changed time if dt_update is available.
      if (!empty($data['dt_update'])) {
        try {
          $date = new DrupalDateTime($data['dt_update']);
          $node->setChangedTime($date->getTimestamp());
        }
        catch (\Exception $e) {
          $logger->warning(sprintf('Could not parse update date from %s', $data['dt_update']));
        }
      }

      // Save the node.
      $node->save();

      $logger->notice(sprintf(
        '%s name node for permit %s: %s (NID: %d)',
        $is_new ? 'Created' : 'Updated',
        $permit_no,
        $data['person_name'],
        $node->id()
      ));
    }
    catch (\Exception $e) {
      $logger->error(sprintf(
        'Error processing name for permit %s, person %s: %s',
        $permit_no,
        $data['person_name'] ?? 'unknown',
        $e->getMessage()
      ));
      $result['skipped']++;
    }
  }

  fclose($handle);

  // Mark this permit as processed.
  $_rcgr_imported_permits[$permit_no] = TRUE;

  $logger->notice(sprintf(
    'Finished importing names for permit %s. Created: %d, Updated: %d, Skipped: %d',
    $permit_no,
    $result['created'],
    $result['updated'],
    $result['skipped']
  ));

  return $result;
}

/**
 * Find a user by legacy user ID. Creates a new user if not found.
 *
 * @param string $legacy_userid
 *   The legacy user ID.
 * @param bool $import_if_not_found
 *   Whether to import the user if they're not found.
 *
 * @return int|null
 *   The user ID, or NULL if not found or created.
 */
function find_user_by_legacy_id($legacy_userid, $import_if_not_found = FALSE) {
  global $_rcgr_import_logger, $_rcgr_users_not_found, $_rcgr_users_imported;

  // Cache of failed lookups to avoid repeatedly trying to import the same non-existent user.
  static $failed_lookups = [];

  if (empty($legacy_userid)) {
    $_rcgr_import_logger->debug("Empty legacy_userid provided to find_user_by_legacy_id");
    // Return anonymous user (0) for empty IDs.
    return 0;
  }

  // Trim whitespace from the legacy user ID.
  $legacy_userid = trim($legacy_userid);

  if (empty($legacy_userid)) {
    $_rcgr_import_logger->debug("Legacy user ID is empty after trimming whitespace");
    // Return anonymous user (0) for empty IDs after trimming.
    return 0;
  }

  // If we've already tried and failed to import this user, don't try again.
  if (isset($failed_lookups[$legacy_userid])) {
    // Return anonymous user (0) for previously failed lookups.
    return 0;
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

  // If we shouldn't import users, just log that we didn't find it and return anonymous.
  if (!$import_if_not_found) {
    $_rcgr_users_not_found++;
    $_rcgr_import_logger->debug("User with legacy ID {$legacy_userid} not found and auto-import disabled");
    $failed_lookups[$legacy_userid] = TRUE;
    return 0;
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

  // Mark this user as a failed lookup so we don't keep trying.
  $failed_lookups[$legacy_userid] = TRUE;

  $_rcgr_users_not_found++;
  $_rcgr_import_logger->warning("Could not find or import user with legacy ID {$legacy_userid}, using anonymous user instead");
  // Return anonymous user (0) for failed imports.
  return 0;
}

// Process the CSV file.
while (($data = fgetcsv($handle)) !== FALSE) {
  // Check if we've hit the limit.
  if ($processed >= $limit) {
    $logger->notice("Reached import limit of {$limit} records");
    break;
  }

  // Create an associative array of the row data.
  $row = array_combine($header, $data);

  try {
    // Check if a node with this recno already exists.
    $query = \Drupal::entityQuery('node')
      ->condition('type', 'name')
      ->condition('field_recno', $row['recno'])
      ->accessCheck(FALSE);
    $nids = $query->execute();

    // Load or create the node.
    if (!empty($nids)) {
      $nid = reset($nids);
      $node = Node::load($nid);
    }
    else {
      $node = Node::create(['type' => 'name']);
    }

    $node->setTitle($row['person_name']);

    // Set simple field values.
    foreach ($field_mappings as $csv_column => $field_name) {
      if (isset($row[$csv_column]) && $row[$csv_column] !== '') {
        $value = $row[$csv_column];

        // Handle boolean fields.
        if (in_array($field_name, ['field_is_removed'])) {
          $value = strtolower($value) === 'true' || $value === '1';
        }

        // Handle date fields.
        if (in_array($field_name, ['field_dt_create', 'field_dt_update'])) {
          if (!empty($value)) {
            try {
              $date = new DrupalDateTime($value);
              $value = $date->format('Y-m-d\TH:i:s');
            }
            catch (\Exception $e) {
              $logger->warning("Invalid date format for {$field_name}: {$value}");
              continue;
            }
          }
          else {
            continue;
          }
        }

        // Handle taxonomy reference fields.
        if (in_array($field_name, ['field_rcf_cd'])) {
          $term_id = get_taxonomy_term_id($value, str_replace('field_', '', $field_name), TRUE, $term_cache, $taxonomy_value_mappings);
          if ($term_id) {
            $node->set($field_name, ['target_id' => $term_id]);
          }
          continue;
        }

        // Set the field value.
        $node->set($field_name, $value);
      }
    }

    // Associate the name entity with a user based on legacy userid.
    if (!empty($row['create_by'])) {
      $uid = find_user_by_legacy_id($row['create_by'], TRUE);
      if ($uid > 0) {
        // Set the node owner to the user with the matching legacy ID.
        $node->setOwnerId($uid);
      }
    }

    // Save the current version of the node.
    $node->save();
    $processed++;

    // Process historical records for this name if they exist.
    if (!empty($row['permit_no']) && isset($historical_records[$row['permit_no']])) {
      $historical_set = $historical_records[$row['permit_no']];

      // Sort historical records by report year (oldest first), then version number (lowest first).
      usort($historical_set, function ($a, $b) {
        // First sort by report_year (oldest first)
        $year_a = isset($a['report_year']) ? (int) $a['report_year'] : 0;
        $year_b = isset($b['report_year']) ? (int) $b['report_year'] : 0;

        if ($year_a !== $year_b) {
          return $year_a - $year_b;
        }

        // Then sort by version_no (lowest first)
        $version_a = isset($a['version_no']) ? (int) $a['version_no'] : 0;
        $version_b = isset($b['version_no']) ? (int) $b['version_no'] : 0;

        return $version_a - $version_b;
      });

      foreach ($historical_set as $hist_row) {
        // Skip if this is the current version (already saved above)
        $is_duplicate = isset($hist_row['recno']) && isset($row['recno']) &&
            $hist_row['recno'] === $row['recno'] &&
            isset($hist_row['version_no']) && isset($row['version_no']) &&
            $hist_row['version_no'] === $row['version_no'];

        if ($is_duplicate) {
          continue;
        }

        // Create a new revision.
        $node->setNewRevision(TRUE);
        $node->revision_log = "Historical record from " . ($hist_row['dt_update'] ?? $hist_row['dt_create']);

        // Update fields with historical data.
        foreach ($field_mappings as $csv_column => $field_name) {
          if (isset($hist_row[$csv_column]) && $hist_row[$csv_column] !== '') {
            $value = $hist_row[$csv_column];

            // Handle boolean fields.
            if (in_array($field_name, ['field_is_removed'])) {
              $value = strtolower($value) === 'true' || $value === '1';
            }

            // Handle date fields.
            if (in_array($field_name, ['field_dt_create', 'field_dt_update'])) {
              if (!empty($value)) {
                try {
                  $date = new DrupalDateTime($value);
                  $value = $date->format('Y-m-d\TH:i:s');
                }
                catch (\Exception $e) {
                  $logger->warning("Invalid date format for {$field_name}: {$value}");
                  continue;
                }
              }
              else {
                continue;
              }
            }

            // Handle taxonomy reference fields.
            if (in_array($field_name, ['field_rcf_cd'])) {
              $term_id = get_taxonomy_term_id($value, str_replace('field_', '', $field_name), TRUE, $term_cache, $taxonomy_value_mappings);
              if ($term_id) {
                $node->set($field_name, ['target_id' => $term_id]);
              }
              continue;
            }

            // Set the field value.
            $node->set($field_name, $value);
          }
        }

        // Associate the historical revision with a user based on legacy userid.
        if (!empty($hist_row['create_by'])) {
          $uid = find_user_by_legacy_id($hist_row['create_by'], TRUE);
          if ($uid > 0) {
            // Set the revision owner to the user with the matching legacy ID.
            $node->setOwnerId($uid);
            $node->setRevisionUserId($uid);
          }
        }

        // Set the revision timestamp to match the historical record's update time.
        if (!empty($hist_row['dt_update'])) {
          $node->setRevisionCreationTime(strtotime($hist_row['dt_update']));
        }
        elseif (!empty($hist_row['dt_create'])) {
          $node->setRevisionCreationTime(strtotime($hist_row['dt_create']));
        }

        // Save the historical revision.
        $node->save();
        $revisions_created++;
      }

      // Find the latest revision with the latest report year.
      $latest_revision_id = NULL;
      $latest_revision_year = 0;

      // Get all revisions for this node.
      $revisions = \Drupal::database()->query("
        SELECT r.vid, r.revision_timestamp, ry.field_report_year_value
        FROM node_revision r
        JOIN node_revision__field_report_year ry ON r.vid = ry.revision_id
        WHERE r.nid = :nid
        ORDER BY ry.field_report_year_value DESC, r.revision_timestamp DESC
      ", [':nid' => $node->id()])->fetchAll();

      if (!empty($revisions)) {
        // The first revision in our sorted list should be the latest year with latest timestamp.
        $latest = reset($revisions);
        $latest_revision_id = $latest->vid;
        $latest_revision_year = $latest->field_report_year_value;

        // Check if we need to update the default revision.
        $current_default_revision_id = \Drupal::database()->query("SELECT vid FROM node_field_data WHERE nid = :nid", [':nid' => $node->id()])->fetchField();
        $current_default_revision_year = \Drupal::database()->query("SELECT field_report_year_value FROM node_revision__field_report_year WHERE revision_id = :vid", [':vid' => $current_default_revision_id])->fetchField();

        if ($latest_revision_year > $current_default_revision_year) {
          // Update the node_field_data table to point to the latest revision.
          \Drupal::database()->update('node_field_data')
            ->fields(['vid' => $latest_revision_id])
            ->condition('nid', $node->id())
            ->execute();

          // Also ensure the field tables are updated:
          // First, delete any existing values in node__field_* tables.
          foreach ($field_mappings as $csv_column => $field_name) {
            $table_name = 'node__' . $field_name;
            \Drupal::database()->delete($table_name)
              ->condition('entity_id', $node->id())
              ->execute();

            // Now copy values from the revision table.
            $revision_table = 'node_revision__' . $field_name;
            $field_column_name = $field_name . '_value';

            // Check if the table exists before attempting to query it.
            $table_exists = \Drupal::database()->schema()->tableExists($revision_table);

            if ($table_exists) {
              // Get the fields for this revision.
              $fields = \Drupal::database()->select($revision_table, 'r')
                ->fields('r')
                ->condition('entity_id', $node->id())
                ->condition('revision_id', $latest_revision_id)
                ->execute()
                ->fetchAssoc();

              // If fields exist, insert them into the main table.
              if (!empty($fields)) {
                // Replace revision_id with the latest.
                $fields['revision_id'] = $latest_revision_id;

                // Insert into the main table.
                \Drupal::database()->insert($table_name)
                  ->fields($fields)
                  ->execute();
              }
            }
          }

          // Clear any caches for this node.
          \Drupal::entityTypeManager()->getStorage('node')->resetCache([$node->id()]);
        }
      }

      // Remove processed historical records to free up memory.
      unset($historical_records[$row['permit_no']]);
    }

    // Log progress every batch_size records.
    if ($processed % $batch_size === 0) {
      $logger->notice("Processed {$processed} records");
    }
  }
  catch (\Exception $e) {
    $logger->error("Error processing record {$row['recno']}: " . $e->getMessage());
    $errors++;
  }
}

// Close the CSV file.
fclose($handle);

// Log final statistics.
$logger->notice("Import completed:");
$logger->notice("- Processed: {$processed}");
$logger->notice("- Historical revisions created: {$revisions_created}");
$logger->notice("- Skipped: {$skipped}");
$logger->notice("- Errors: {$errors}");
$logger->notice("- Users not found for name records: {$_rcgr_users_not_found}");
$logger->notice("- Users imported on-demand: {$_rcgr_users_imported}");

// Only exit explicitly if there were actual errors.
if ($errors > 0) {
  exit(1);
}
