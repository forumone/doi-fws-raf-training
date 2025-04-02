<?php

/**
 * @file
 * Imports location data from CSV file into Drupal location nodes.
 */

use Drupal\node\Entity\Node;
use Drupal\Core\Datetime\DrupalDateTime;
use Drush\Drush;
use Drupal\taxonomy\Entity\Term;

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
$current_csv_file = __DIR__ . '/data/rcgr_location_202503031405.csv';
$history_csv_file = __DIR__ . '/data/rcgr_location_hist_202503031405.csv';

// Track processed nodes to handle revisions.
$processed_nodes = [];

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

    // Handle combined address fields first.
    $address = $data['location_address_l1'];
    if (!empty($data['location_address_l2'])) {
      $address .= "\n" . $data['location_address_l2'];
    }
    if (!empty($data['location_address_l3'])) {
      $address .= "\n" . $data['location_address_l3'];
    }
    $node->set('field_location_address', $address);

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
            $tid = get_taxonomy_term_id($state_code, $bundle, TRUE, $term_cache, $value_mappings);

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
            $tid = get_taxonomy_term_id($rcf_code, $bundle, TRUE, $term_cache, $value_mappings);

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
          $node->set($drupal_field, $value);
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
    return [FALSE, "Error processing record for permit #{$data['permit_no']}: " . $e->getMessage()];
  }
}

/**
 * Function to load and validate CSV data.
 */
function load_csv_data($file_path, $logger) {
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
  exit(1);
}

$logger->warning('Starting import of location data.');

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
  $logger->notice('Loaded ' . count($historical_records) . ' sets of historical records.');
}

// Process current records and their historical data.
$row_number = 0;

while ($current_data && ($row = fgetcsv($current_data['handle'])) !== FALSE) {
  $row_number++;

  // Skip header row.
  if ($row_number > 1 && $row_number <= $limit + 1) {
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
      if ($processed % $batch_size === 0) {
        $logger->info('Processed @count current records...', ['@count' => $processed]);
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
            if ($revisions_created % $batch_size === 0) {
              $logger->info('Created @count historical revisions...', ['@count' => $revisions_created]);
            }
          }
          else {
            if (strpos($hist_message, 'No existing node found') === FALSE) {
              $logger->error($hist_message);
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
      $logger->error($message);
      $errors++;
    }
  }
}

// Close file handles.
if ($current_data) {
  fclose($current_data['handle']);
}

// Print summary.
echo "\nImport completed:\n";
echo "Current records processed: $processed\n";
echo "Historical revisions created: $revisions_created\n";
echo "Skipped: $skipped\n";
echo "Errors: $errors\n";
