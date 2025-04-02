<?php

/**
 * @file
 * Import permits with proper taxonomy term handling.
 *
 * This script fixes issues with taxonomy indexing by properly mapping values.
 */

use Drupal\node\Entity\Node;
use Drush\Drush;
use Drupal\taxonomy\Entity\Term;

/**
 * Formats a datetime string for Drupal storage.
 *
 * @param string $datetime_string
 *   The datetime string to format.
 *
 * @return string|false
 *   The formatted datetime string or FALSE if invalid.
 */
function format_datetime_for_drupal($datetime_string) {
  if (empty($datetime_string)) {
    return FALSE;
  }

  try {
    // Parse the input string to a DateTime object.
    $datetime = new \DateTime($datetime_string);

    // Return in Drupal's preferred format (ISO format).
    return $datetime->format('Y-m-d\TH:i:s');
  }
  catch (\Exception $e) {
    // If parsing fails, return FALSE.
    return FALSE;
  }
}

/**
 * Gets or creates a taxonomy term ID for the given value.
 *
 * @param string $value
 *   The term name to look up.
 * @param string $vocabulary
 *   The vocabulary ID.
 * @param bool $create_if_missing
 *   Whether to create the term if it doesn't exist.
 * @param array &$term_cache
 *   A cache array to store terms we've already looked up.
 * @param array $value_mappings
 *   Optional value mappings to apply.
 * @param bool $force_new
 *   Whether to force creation of a new term.
 *
 * @return int|null
 *   The term ID or NULL if not found and not created.
 */
function get_taxonomy_term_id($value, $vocabulary, $create_if_missing, array &$term_cache, array $value_mappings = [], $force_new = FALSE) {
  $logger = Drush::logger();

  if (empty($value)) {
    return NULL;
  }

  // Apply value mapping if exists.
  if (isset($value_mappings[$value])) {
    $value = $value_mappings[$value];
  }

  // Create a cache key for this term.
  $cache_key = $vocabulary . ':' . $value;

  // Check if we've already looked up this term.
  if (!$force_new && isset($term_cache[$cache_key])) {
    return $term_cache[$cache_key];
  }

  // Look up the term.
  if (!$force_new) {
    // Create an entity query with explicit access checking disabled.
    $query = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->getQuery()
      ->condition('vid', $vocabulary)
      ->condition('name', $value)
      ->accessCheck(FALSE);

    $tids = $query->execute();

    if (!empty($tids)) {
      $tid = reset($tids);
      $term_cache[$cache_key] = $tid;
      return $tid;
    }
  }

  // Create the term if it doesn't exist and we're allowed to create it.
  if ($create_if_missing) {
    try {
      $term = Term::create([
        'vid' => $vocabulary,
        'name' => $value,
      ]);
      $term->save();
      $tid = $term->id();
      $term_cache[$cache_key] = $tid;
      return $tid;
    }
    catch (\Exception $e) {
      $logger->error("Error creating taxonomy term '$value' in $vocabulary vocabulary: " . $e->getMessage());
      return NULL;
    }
  }

  return NULL;
}

/**
 * Checks if a field exists for the permit content type.
 *
 * @param string $field_name
 *   The field name to check.
 *
 * @return bool
 *   TRUE if the field exists, FALSE otherwise.
 */
function field_exists_for_permit($field_name) {
  static $field_definitions = NULL;

  if ($field_definitions === NULL) {
    // Load the field definitions for the permit content type.
    $field_definitions = \Drupal::service('entity_field.manager')
      ->getFieldDefinitions('node', 'permit');
  }

  return isset($field_definitions[$field_name]);
}

// Get the limit parameter from command line arguments if provided.
$input = Drush::input();
$args = $input->getArguments();
$limit = isset($args['extra'][1]) ? (int) $args['extra'][1] : PHP_INT_MAX;

// Initialize log output.
$logger = Drush::logger();
$logger->notice("Starting permit import");

// Log the limit if specified.
if ($limit < PHP_INT_MAX) {
  $logger->notice("Import limit: $limit");
}

// Initialize counters.
$stats = [
  'total' => 0,
  'created' => 0,
  'updated' => 0,
  'skipped' => 0,
  'errors' => 0,
  'processed' => 0,
];

// Initialize taxonomy term cache.
$term_cache = [];

// Open the CSV file.
$csv_file = dirname(__FILE__) . '/data/rcgr_permit_app_mast_202503031405.csv';
$hist_csv_file = dirname(__FILE__) . '/data/rcgr_permit_app_mast_hist_202503031405.csv';

// Load historical records into memory for faster lookup.
$historical_records = [];
$hist_handle = fopen($hist_csv_file, 'r');
if ($hist_handle === FALSE) {
  $logger->error('Could not open historical CSV file: ' . $hist_csv_file);
}
else {
  // Skip header row but store it for column mapping.
  $hist_header = fgetcsv($hist_handle);

  // Read all historical records into memory.
  while (($row = fgetcsv($hist_handle)) !== FALSE) {
    $permit_no = trim($row[$_rcgr_import_csv_map['permit_no']], '"');
    if (!isset($historical_records[$permit_no])) {
      $historical_records[$permit_no] = [];
    }
    $historical_records[$permit_no][] = $row;
  }
  fclose($hist_handle);
  $logger->notice("Loaded " . count($historical_records) . " historical permits into memory.");
}

$handle = fopen($csv_file, 'r');

if ($handle === FALSE) {
  $logger->error('Could not open CSV file: ' . $csv_file);
  return;
}

// Read the header row and map column names to indices.
$header = fgetcsv($handle);
global $_rcgr_import_csv_map;
$_rcgr_import_csv_map = [
  'recno' => array_search('recno', $header),
  'isRemoved' => array_search('isRemoved', $header),
  'permit_no' => array_search('permit_no', $header),
  'report_year' => array_search('report_year', $header),
  'person_name' => array_search('person_name', $header),
  'version_no' => array_search('version_no', $header),
  'hid' => array_search('hid', $header),
  'site_id' => array_search('site_id', $header),
  'control_site_id' => array_search('control_site_id', $header),
  'registrant_type_cd' => array_search('registrant_type_cd', $header),
  'permit_status_cd' => array_search('permit_status_cd', $header),
  'applicant_state' => array_search('applicant_state', $header),
  'applicant_email_address' => array_search('applicant_email_address', $header),
  'applicant_business_name' => array_search('applicant_business_name', $header),
  'applicant_address_l1' => array_search('applicant_address_l1', $header),
  'applicant_address_l2' => array_search('applicant_address_l2', $header),
  'applicant_address_l3' => array_search('applicant_address_l3', $header),
  'applicant_city' => array_search('applicant_city', $header),
  'applicant_zip' => array_search('applicant_zip', $header),
  'applicant_home_phone' => array_search('applicant_home_phone', $header),
  'applicant_work_phone' => array_search('applicant_work_phone', $header),
  'dt_create' => array_search('dt_create', $header),
  'dt_update' => array_search('dt_update', $header),
  'dt_signed' => array_search('dt_signed', $header),
  'dt_permit_request' => array_search('dt_permit_request', $header),
  'dt_permit_issued' => array_search('dt_permit_issued', $header),
  'dt_effective' => array_search('dt_effective', $header),
  'dt_expired' => array_search('dt_expired', $header),
  'dt_applicant_signed' => array_search('dt_applicant_signed', $header),
  'dt_application_received' => array_search('dt_application_received', $header),
  'create_by' => array_search('create_by', $header),
  'update_by' => array_search('update_by', $header),
  'xml_cd' => array_search('xml_cd', $header),
  'rcf_cd' => array_search('rcf_cd', $header),
  'applicant_agreement1' => array_search('applicant_agreement1', $header),
  'applicant_agreement2' => array_search('applicant_agreement2', $header),
  'applicant_agreement3' => array_search('applicant_agreement3', $header),
  'applicant_signed' => array_search('applicant_signed', $header),
];

// Define field mappings.
$field_mappings = [
  'permit_no' => 'field_permit_no',
  'version_no' => 'field_version_no',
  'create_by' => 'field_create_by',
  'update_by' => 'field_update_by',
  'xml_cd' => 'field_xml_cd',
  'rcf_cd' => 'field_rcf_cd',
  'hid' => 'field_hid',
  'site_id' => 'field_site_id',
  'control_site_id' => 'field_control_site_id',
  'dt_create' => 'field_dt_create',
  'dt_update' => 'field_dt_update',
];

// Define special field mappings that need to be validated.
$special_field_mappings = [
  'applicant_address_l1' => 'field_location_address',
  'applicant_address_l2' => 'field_location_address',
  'applicant_address_l3' => 'field_location_address',
// Using this as a proxy for certification.
  'applicant_signed' => 'field_is_location_certified',
];

// Define date field mappings.
$date_field_mappings = [
  'dt_create' => 'field_dt_create',
  'dt_update' => 'field_dt_update',
  'dt_signed' => 'field_dt_signed',
  'dt_permit_request' => 'field_dt_permit_request',
  'dt_permit_issued' => 'field_dt_permit_issued',
  'dt_effective' => 'field_dt_effective',
  'dt_expired' => 'field_dt_expired',
  'dt_applicant_signed' => 'field_dt_applicant_signed',
  'dt_application_received' => 'field_dt_application_received',
];

// Define value mappings for known values like 'U' and 'A'.
$taxonomy_value_mappings = [
  'U' => 'Unknown',
  'A' => 'Active',
  'I' => 'Inactive',
];

// Define taxonomy field mappings.
$taxonomy_field_mappings = [
  'applicant_state' => [
    'field' => 'field_applicant_state',
    'vocabulary' => 'state',
  ],
  'registrant_type_cd' => [
    'field' => 'field_registrant_type_cd',
    'vocabulary' => 'registrant_type',
  ],
  'permit_status_cd' => [
    'field' => 'field_permit_status_cd',
    'vocabulary' => 'application_status',
  ],
  'rcf_cd' => [
    'field' => 'field_rcf_cd',
    'vocabulary' => 'rcf',
  ],
];

// Log the fields being used.
$logger->notice(
  'Using the following field mappings: ' . implode(', ', array_values($field_mappings))
);
$logger->notice(
  'Using the following date field mappings: ' .
  implode(', ', array_values($date_field_mappings))
);
$logger->notice(
  'Using the following taxonomy field mappings: ' .
  implode(', ', array_column($taxonomy_field_mappings, 'field'))
);

// Disable the autoindex flag during the import process.
$previous_autoindex = NULL;
try {
  $config = \Drupal::configFactory()->getEditable('taxonomy.settings');
  $previous_autoindex = $config->get('maintain_index_table');
  $config->set('maintain_index_table', FALSE)->save();
  $logger->notice('Temporarily disabled taxonomy index maintenance during import.');
}
catch (\Exception $e) {
  $logger->warning('Could not disable taxonomy index maintenance: ' . $e->getMessage());
}

// Process each row in the CSV.
while (($row = fgetcsv($handle)) !== FALSE && $stats['processed'] < $limit) {
  try {
    $permit_no = trim($row[$_rcgr_import_csv_map['permit_no']], '"');
    $title = $permit_no;

    // Skip if required fields are missing.
    if (empty($permit_no)) {
      $logger->warning("Skipping row {$stats['total']}: Missing permit number");
      $stats['skipped']++;
      continue;
    }

    // Look for existing node.
    $query = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->getQuery()
      ->condition('type', 'permit')
      ->condition('field_permit_no', $permit_no)
      ->accessCheck(FALSE);

    $nids = $query->execute();
    $node = NULL;
    $is_new = TRUE;

    if (!empty($nids)) {
      $nid = reset($nids);
      $node = Node::load($nid);
      $is_new = FALSE;
    }

    if ($node === NULL) {
      $node = Node::create([
        'type' => 'permit',
        'title' => $title,
      ]);
      $stats['created']++;
    }
    else {
      $stats['updated']++;
    }

    // Set basic fields.
    foreach ($field_mappings as $csv_field => $drupal_field) {
      if (isset($_rcgr_import_csv_map[$csv_field]) && field_exists_for_permit($drupal_field)) {
        $value = trim($row[$_rcgr_import_csv_map[$csv_field]], '"');

        // Handle date fields.
        if (isset($date_field_mappings[$csv_field])) {
          $value = format_datetime_for_drupal($value);
          if ($value === FALSE) {
            continue;
          }
        }

        $node->set($drupal_field, $value);
      }
    }

    // Save the current version.
    $node->setNewRevision(TRUE);
    $node->revision_log = 'Imported current record from CSV.';
    $node->save();

    // Process historical records for this permit.
    if (isset($historical_records[$permit_no])) {
      // Sort historical records by dt_update to ensure proper chronological order.
      usort($historical_records[$permit_no], function ($a, $b) use ($_rcgr_import_csv_map) {
        $a_date = strtotime(trim($a[$_rcgr_import_csv_map['dt_update']], '"'));
        $b_date = strtotime(trim($b[$_rcgr_import_csv_map['dt_update']], '"'));
        return $a_date - $b_date;
      });

      foreach ($historical_records[$permit_no] as $hist_row) {
        // Create a new revision.
        $node->setNewRevision(TRUE);

        // Set fields from historical record.
        foreach ($field_mappings as $csv_field => $drupal_field) {
          if (isset($_rcgr_import_csv_map[$csv_field]) && field_exists_for_permit($drupal_field)) {
            $value = trim($hist_row[$_rcgr_import_csv_map[$csv_field]], '"');

            // Handle date fields.
            if (isset($date_field_mappings[$csv_field])) {
              $value = format_datetime_for_drupal($value);
              if ($value === FALSE) {
                continue;
              }
            }

            $node->set($drupal_field, $value);
          }
        }

        // Set revision timestamp from historical record's update date.
        if (isset($_rcgr_import_csv_map['dt_update'])) {
          $update_date = trim($hist_row[$_rcgr_import_csv_map['dt_update']], '"');
          if ($update_date) {
            $timestamp = strtotime($update_date);
            if ($timestamp !== FALSE) {
              $node->setRevisionCreationTime($timestamp);
            }
          }
        }

        $node->revision_log = 'Imported historical record from CSV.';
        $node->save();
      }

      // Remove processed historical records to free memory.
      unset($historical_records[$permit_no]);
    }

    $stats['processed']++;

    // Log progress every 100 records.
    if ($stats['processed'] % 100 === 0) {
      $logger->notice("Processed {$stats['processed']} permits...");
    }
  }
  catch (\Exception $e) {
    $logger->error("Error processing row {$stats['total']}: " . $e->getMessage());
    $stats['errors']++;
  }

  $stats['total']++;
}

fclose($handle);

// Restore the autoindex setting.
if ($previous_autoindex !== NULL) {
  try {
    $config = \Drupal::configFactory()->getEditable('taxonomy.settings');
    $config->set('maintain_index_table', $previous_autoindex)->save();
    $logger->notice('Restored taxonomy index maintenance setting.');
  }
  catch (\Exception $e) {
    $logger->warning('Could not restore taxonomy index maintenance: ' . $e->getMessage());
  }
}

// Display final results.
$logger->notice("Permit import completed.");
$logger->notice("Total rows processed: {$stats['processed']}");
$logger->notice("Permits created: {$stats['created']}");
$logger->notice("Permits updated: {$stats['updated']}");
$logger->notice("Rows skipped: {$stats['skipped']}");
$logger->notice("Errors: {$stats['errors']}");
