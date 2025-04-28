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

// Include the user import functions.
require_once __DIR__ . '/import-users.php';

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
function get_permit_taxonomy_term_id($name, $vocabulary, $create_if_missing = TRUE, array &$term_cache = [], array $value_mappings = [], $force_new_term = FALSE) {
  $logger = Drush::logger();

  if (empty($name)) {
    return NULL;
  }

  // Apply value mapping if exists.
  if (isset($value_mappings[$name])) {
    $name = $value_mappings[$name];
  }

  // Create a cache key for this term.
  $cache_key = $vocabulary . ':' . $name;

  // Check if we've already looked up this term.
  if (!$force_new_term && isset($term_cache[$cache_key])) {
    return $term_cache[$cache_key];
  }

  // Look up the term.
  if (!$force_new_term) {
    // Create an entity query with explicit access checking disabled.
    $query = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->getQuery()
      ->condition('vid', $vocabulary)
      ->condition('name', $name)
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
        'name' => $name,
      ]);
      $term->save();
      $tid = $term->id();
      $term_cache[$cache_key] = $tid;
      return $tid;
    }
    catch (\Exception $e) {
      $logger->error("Error creating taxonomy term '$name' in $vocabulary vocabulary: " . $e->getMessage());
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

/**
 * Find a user by legacy user ID. Imports new user if not found.
 *
 * @param string $legacy_userid
 *   The legacy user ID.
 *
 * @return int|null
 *   The user ID, or NULL if not found or created.
 */
function permits_find_user_by_legacy_id($legacy_userid) {
  global $_rcgr_users_not_found, $_rcgr_users_imported;
  $logger = Drush::logger();

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
    $logger->debug("Found user {$uid} with legacy ID {$legacy_userid}");
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
    $logger->debug("Imported user {$user->id()} for legacy ID {$legacy_userid}");
    return $user->id();
  }

  $_rcgr_users_not_found++;
  return NULL;
}

/**
 * Find a permit by permit number. Imports new permit if not found.
 *
 * @param string $permit_no
 *   The permit number.
 * @param string $csv_file
 *   Optional CSV file path. If NULL, uses the default CSV file.
 * @param callable|null $logger
 *   Optional logger callback function.
 *
 * @return \Drupal\node\NodeInterface|null
 *   The node if found or created, NULL otherwise.
 */
function find_permit_by_permit_no($permit_no, $csv_file = NULL, ?callable $logger = NULL) {
  global $_rcgr_import_logger;

  // Use the global logger if none provided.
  if ($logger === NULL && isset($_rcgr_import_logger)) {
    $logger = $_rcgr_import_logger;
  }
  elseif ($logger === NULL) {
    // Create a simple logger that writes to the Drush log.
    $logger = function ($message, $vars = []) {
      if (!empty($vars)) {
        $message = strtr($message, $vars);
      }
      Drush::logger()->notice($message);
    };
  }

  if (empty($permit_no)) {
    $logger("Empty permit number provided");
    return NULL;
  }

  // Trim whitespace from the permit number.
  $permit_no = trim($permit_no);

  if (empty($permit_no)) {
    $logger("Permit number is empty after trimming");
    return NULL;
  }

  // Try to find a node with this permit number.
  $query = \Drupal::entityTypeManager()
    ->getStorage('node')
    ->getQuery()
    ->condition('type', 'permit')
    ->condition('field_permit_no', $permit_no)
    ->accessCheck(FALSE);
  $nids = $query->execute();

  if (!empty($nids)) {
    $nid = reset($nids);
    $logger("Found permit node {$nid} with permit number {$permit_no}");
    return Node::load($nid);
  }

  // If no CSV file is provided, try to use the default one.
  if ($csv_file === NULL) {
    $csv_file = __DIR__ . '/data/rcgr_permit_app_mast_202503031405.csv';
    $logger("Using default permit CSV file: {$csv_file}");

    if (!file_exists($csv_file)) {
      $logger("Default permit CSV file not found at {$csv_file}");
      return NULL;
    }
  }

  // Try to import the permit from the CSV.
  $logger("Attempting to import permit {$permit_no} from CSV");

  // Open the CSV file.
  $handle = fopen($csv_file, 'r');
  if ($handle === FALSE) {
    $logger("Could not open CSV file {$csv_file}");
    return NULL;
  }

  // Get the header row.
  $header = fgetcsv($handle);
  if ($header === FALSE) {
    $logger("Could not read header from CSV file");
    fclose($handle);
    return NULL;
  }

  $logger("CSV header has " . count($header) . " columns");

  // Map column names to indices.
  $column_map = [];
  foreach ($header as $index => $name) {
    $column_map[$name] = $index;
  }

  // Check if permit_no column exists.
  if (!isset($column_map['permit_no'])) {
    $logger("CSV file does not contain 'permit_no' column");
    $logger("Available columns: " . implode(", ", $header));
    fclose($handle);
    return NULL;
  }

  $logger("Searching for permit {$permit_no} in CSV file");
  $logger("permit_no column index: " . $column_map['permit_no']);

  // Search for the permit in the CSV.
  $permit_row = NULL;
  $row_count = 0;
  while (($row = fgetcsv($handle)) !== FALSE) {
    $row_count++;
    if (isset($row[$column_map['permit_no']])) {
      $current_permit_no = trim($row[$column_map['permit_no']], '"');

      if ($row_count <= 5 || $row_count % 1000 === 0) {
        $logger("Row {$row_count}: Checking permit '{$current_permit_no}' against '{$permit_no}'");
      }

      if ($current_permit_no === $permit_no) {
        $permit_row = $row;
        $logger("Found permit {$permit_no} at row {$row_count}");
        break;
      }
    }
    else {
      $logger("Row {$row_count} doesn't have enough columns to access permit_no index");
    }
  }

  fclose($handle);

  if ($permit_row === NULL) {
    $logger("Permit {$permit_no} not found in CSV file after checking {$row_count} rows");
    return NULL;
  }

  // Create the permit node.
  $data = array_combine($header, $permit_row);

  $node = Node::create([
    'type' => 'permit',
    'title' => $permit_no,
  ]);

  // Define field mappings based on the global mapping if available.
  $field_mappings = [
    'permit_no' => 'field_permit_no',
    // Add other field mappings that are essential for a permit.
    'status' => 'field_status',
    'permit_type' => 'field_permit_type',
    'issued_date' => 'field_issued_date',
    'expiry_date' => 'field_expiry_date',
  ];

  // Set basic fields.
  foreach ($field_mappings as $csv_field => $drupal_field) {
    if (isset($data[$csv_field]) && field_exists_for_permit($drupal_field)) {
      $value = trim($data[$csv_field], '"');

      // Handle date fields.
      if ($drupal_field === 'field_issued_date' || $drupal_field === 'field_expiry_date') {
        $value = format_datetime_for_drupal($value);
        if ($value === FALSE) {
          continue;
        }
      }

      $node->set($drupal_field, $value);
    }
  }

  // Associate the permit with a user if possible.
  if (isset($data['create_by'])) {
    $legacy_userid = trim($data['create_by'], '"');
    if (!empty($legacy_userid)) {
      $uid = permits_find_user_by_legacy_id($legacy_userid);
      if ($uid) {
        $node->setOwnerId($uid);
      }
    }
  }

  try {
    $node->save();
    $logger("Created new permit node {$node->id()} for permit number {$permit_no}");
    return $node;
  }
  catch (\Exception $e) {
    $logger("Error creating permit node for {$permit_no}: " . $e->getMessage());
    return NULL;
  }
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

// Track users not found and imported.
global $_rcgr_users_not_found;
global $_rcgr_users_imported;
$_rcgr_users_not_found = 0;
$_rcgr_users_imported = 0;

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
  'principal_name' => array_search('principal_name', $header),
  'principal_first_name' => array_search('principal_first_name', $header),
  'principal_middle_name' => array_search('principal_middle_name', $header),
  'principal_last_name' => array_search('principal_last_name', $header),
  'principal_suffix' => array_search('principal_suffix', $header),
  'principal_title' => array_search('principal_title', $header),
  'principal_telephone' => array_search('principal_telephone', $header),
  'agency_tracking_id' => array_search('agency_tracking_id', $header),
  'ep_formid' => array_search('ep_formid', $header),
  'ep_form_no' => array_search('ep_form_no', $header),
  'ep_form_title' => array_search('ep_form_title', $header),
  'applicant_last_name' => array_search('applicant_last_name', $header),
  'applicant_first_name' => array_search('applicant_first_name', $header),
  'applicant_middle_name' => array_search('applicant_middle_name', $header),
  'applicant_prefix' => array_search('applicant_prefix', $header),
  'applicant_suffix' => array_search('applicant_suffix', $header),
  'applicant_business_desc' => array_search('applicant_business_desc', $header),
  'applicant_county' => array_search('applicant_county', $header),
  'primary_contact_name' => array_search('primary_contact_name', $header),
  'primary_contact_telephone' => array_search('primary_contact_telephone', $header),
  'primary_contact_email_address' => array_search('primary_contact_email_address', $header),
  'applicant_agreement1' => array_search('applicant_agreement1', $header),
  'applicant_agreement2' => array_search('applicant_agreement2', $header),
  'applicant_agreement3' => array_search('applicant_agreement3', $header),
  'applicant_signed' => array_search('applicant_signed', $header),
  'offloc_id' => array_search('offloc_id', $header),
  'permit_type_cd' => array_search('permit_type_cd', $header),
  'applicant_request_type' => array_search('applicant_request_type', $header),
  'issuer_action_cd' => array_search('issuer_action_cd', $header),
  'issued_by' => array_search('issued_by', $header),
  'issuing_officer_title' => array_search('issuing_officer_title', $header),
  'biologist_initial' => array_search('biologist_initial', $header),
  'amend_seq_number' => array_search('amend_seq_number', $header),
  'wildcard_search_option' => array_search('wildcard_search_option', $header),
  'program_id' => array_search('program_id', $header),
  'region' => array_search('region', $header),
  'control_program_id' => array_search('control_program_id', $header),
  'control_region' => array_search('control_region', $header),
  'bi_cd' => array_search('bi_cd', $header),
];

// Define field mappings from CSV columns to Drupal fields.
$field_mappings = [
  'permit_no' => 'field_permit_no',
  'hid' => 'field_hid',
  'site_id' => 'field_site_id',
  'control_site_id' => 'field_control_site_id',
  'version_no' => 'field_version_no',
  'registrant_name' => 'field_applicant_business_name',
  'registrant_first_name' => 'field_applicant_first_name',
  'registrant_middle_name' => 'field_applicant_middle_name',
  'registrant_last_name' => 'field_applicant_last_name',
  'registrant_prefix' => 'field_applicant_prefix',
  'registrant_suffix' => 'field_applicant_suffix',
  'registrant_address_l1' => 'field_applicant_address_l1',
  'registrant_address_l2' => 'field_applicant_address_l2',
  'registrant_address_l3' => 'field_applicant_address_l3',
  'registrant_city' => 'field_applicant_city',
  'registrant_county' => 'field_applicant_county',
  'registrant_state' => 'field_applicant_state',
  'registrant_zip' => 'field_applicant_zip',
  'registrant_home_phone' => 'field_applicant_home_phone',
  'registrant_work_phone' => 'field_applicant_work_phone',
  'registrant_email_address' => 'field_applicant_email_address',
  'principal_name' => 'field_principal_name',
  'principal_first_name' => 'field_principal_first_name',
  'principal_middle_name' => 'field_principal_middle_name',
  'principal_last_name' => 'field_principal_last_name',
  'principal_suffix' => 'field_principal_suffix',
  'principal_title' => 'field_principal_title',
  'principal_telephone' => 'field_principal_telephone',
  'primary_contact_name' => 'field_primary_contact_name',
  'primary_contact_telephone' => 'field_primary_contact_telephone',
  'primary_contact_email' => 'field_primary_contact_email',
  'issued_by' => 'field_issued_by',
  'issuing_officer_title' => 'field_issuing_officer_title',
  'biologist_initial' => 'field_biologist_initial',
  'agency_tracking_id' => 'field_agency_tracking_id',
  'create_by' => 'field_create_by',
  'update_by' => 'field_update_by',
  'ep_formid' => 'field_ep_formid',
  'ep_form_no' => 'field_ep_form_no',
  'ep_form_title' => 'field_ep_form_title',
  'offloc_id' => 'field_offloc_id',
  'program_id' => 'field_program_id',
  'region' => 'field_region',
  'control_program_id' => 'field_control_program_id',
  'control_region' => 'field_control_region',
  'amend_seq_number' => 'field_amend_seq_number',
  'bi_cd' => 'field_bi_cd',
];

// Define mappings for date fields.
$date_field_mappings = [
  'dt_create' => 'field_dt_create',
  'dt_update' => 'field_dt_update',
  'dt_expired' => 'field_dt_expired',
  'dt_application_received' => 'field_dt_application_received',
  'dt_permit_request' => 'field_dt_permit_request',
  'dt_applicant_signed' => 'field_dt_applicant_signed',
  'dt_signed' => 'field_dt_signed',
  'dt_permit_issued' => 'field_dt_permit_issued',
  'dt_effective' => 'field_dt_effective',
];

// Define mappings for boolean fields.
$boolean_field_mappings = [
  'applicant_agreement1' => 'field_applicant_agreement1',
  'applicant_agreement2' => 'field_applicant_agreement2',
  'applicant_agreement3' => 'field_applicant_agreement3',
  'applicant_signed' => 'field_applicant_signed',
];

// Define mappings for taxonomy term fields.
$taxonomy_field_mappings = [
  'permit_status_cd' => [
    'field' => 'field_permit_status_cd',
    'vocabulary' => 'permit_status_code',
  ],
  'permit_type_cd' => [
    'field' => 'field_permit_type_cd',
    'vocabulary' => 'permit_type_code',
  ],
  'xml_cd' => [
    'field' => 'field_xml_cd',
    'vocabulary' => 'xml_code',
  ],
  'rcf_cd' => [
    'field' => 'field_rcf_cd',
    'vocabulary' => 'rcf_code',
  ],
  'registrant_type_cd' => [
    'field' => 'field_registrant_type_cd',
    'vocabulary' => 'registrant_type_code',
  ],
  'applicant_request_type' => [
    'field' => 'field_applicant_request_type',
    'vocabulary' => 'applicant_request_type',
  ],
];

// Define value mappings for taxonomy terms.
$taxonomy_value_mappings = [];

// Log the fields being used.
$logger->notice(
  'Using the following field mappings: ' . implode(', ', array_values($field_mappings))
);
$logger->notice(
  'Using the following date field mappings: ' .
  implode(', ', array_values($date_field_mappings))
);
$logger->notice(
  'Using the following boolean field mappings: ' .
  implode(', ', array_values($boolean_field_mappings))
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

    // Associate the permit entity with a user based on legacy userid.
    if (isset($_rcgr_import_csv_map['create_by'])) {
      $legacy_userid = trim($row[$_rcgr_import_csv_map['create_by']], '"');
      if (!empty($legacy_userid)) {
        $uid = permits_find_user_by_legacy_id($legacy_userid);
        if ($uid) {
          // Set the node owner to the user with the matching legacy ID.
          $node->setOwnerId($uid);
        }
      }
    }

    // Set basic fields.
    foreach ($field_mappings as $csv_field => $drupal_field) {
      if (isset($_rcgr_import_csv_map[$csv_field]) && field_exists_for_permit($drupal_field)) {
        $csv_index = $_rcgr_import_csv_map[$csv_field];
        if (isset($row[$csv_index])) {
          $value = trim($row[$csv_index], '"');
          // Skip empty values unless it's explicitly allowed or needed.
          if (!empty($value) || $value === '0') {
            $node->set($drupal_field, $value);
          }
        }
      }
    }

    // Set date fields.
    foreach ($date_field_mappings as $csv_field => $drupal_field) {
      if (isset($_rcgr_import_csv_map[$csv_field]) && field_exists_for_permit($drupal_field)) {
        $csv_index = $_rcgr_import_csv_map[$csv_field];
        if (isset($row[$csv_index])) {
          $value = trim($row[$csv_index], '"');
          $formatted_date = format_datetime_for_drupal($value);
          if ($formatted_date !== FALSE) {
            $node->set($drupal_field, $formatted_date);
          }
        }
      }
    }

    // Set boolean fields.
    foreach ($boolean_field_mappings as $csv_field => $drupal_field) {
      if (isset($_rcgr_import_csv_map[$csv_field]) && field_exists_for_permit($drupal_field)) {
        $csv_index = $_rcgr_import_csv_map[$csv_field];
        if (isset($row[$csv_index])) {
          $value = strtoupper(trim($row[$csv_index], '"'));
          // Assuming '1', 'Y', 'T' represent TRUE.
          $bool_value = in_array($value, ['1', 'Y', 'T', 'TRUE']) ? TRUE : FALSE;
          $node->set($drupal_field, $bool_value);
        }
      }
    }

    // Set taxonomy reference fields.
    foreach ($taxonomy_field_mappings as $csv_field => $mapping) {
      if (isset($_rcgr_import_csv_map[$csv_field]) && field_exists_for_permit($mapping['field'])) {
        $csv_index = $_rcgr_import_csv_map[$csv_field];
        if (isset($row[$csv_index])) {
          $term_name = trim($row[$csv_index], '"');
          if (!empty($term_name)) {
            $tid = get_permit_taxonomy_term_id(
              $term_name,
              $mapping['vocabulary'],
              TRUE,
              $term_cache,
              $taxonomy_value_mappings
            );
            if ($tid !== NULL) {
              $node->set($mapping['field'], ['target_id' => $tid]);
            }
          }
        }
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

        // Associate the historical revision with a user based on legacy userid.
        if (isset($_rcgr_import_csv_map['create_by'])) {
          $legacy_userid = trim($hist_row[$_rcgr_import_csv_map['create_by']], '"');
          if (!empty($legacy_userid)) {
            $uid = permits_find_user_by_legacy_id($legacy_userid);
            if ($uid) {
              // Set the revision owner to the user with the matching legacy ID.
              $node->setRevisionUserId($uid);
            }
          }
        }

        // Set fields from historical record.
        foreach ($field_mappings as $csv_field => $drupal_field) {
          if (isset($_rcgr_import_csv_map[$csv_field]) && field_exists_for_permit($drupal_field)) {
            $csv_index = $_rcgr_import_csv_map[$csv_field];
            if (isset($hist_row[$csv_index])) {
              $value = trim($hist_row[$csv_index], '"');
              $node->set($drupal_field, $value);
            }
          }
        }

        // Set date fields from historical record.
        foreach ($date_field_mappings as $csv_field => $drupal_field) {
          if (isset($_rcgr_import_csv_map[$csv_field]) && field_exists_for_permit($drupal_field)) {
            $csv_index = $_rcgr_import_csv_map[$csv_field];
            if (isset($hist_row[$csv_index])) {
              $value = trim($hist_row[$csv_index], '"');
              $formatted_date = format_datetime_for_drupal($value);
              if ($formatted_date !== FALSE) {
                $node->set($drupal_field, $formatted_date);
              }
            }
          }
        }

        // Set boolean fields from historical record.
        foreach ($boolean_field_mappings as $csv_field => $drupal_field) {
          if (isset($_rcgr_import_csv_map[$csv_field]) && field_exists_for_permit($drupal_field)) {
            $csv_index = $_rcgr_import_csv_map[$csv_field];
            if (isset($hist_row[$csv_index])) {
              $value = strtoupper(trim($hist_row[$csv_index], '"'));
              $bool_value = in_array($value, ['1', 'Y', 'T', 'TRUE']) ? TRUE : FALSE;
              $node->set($drupal_field, $bool_value);
            }
          }
        }

        // Set taxonomy fields from historical record.
        foreach ($taxonomy_field_mappings as $csv_field => $mapping) {
          if (isset($_rcgr_import_csv_map[$csv_field]) && field_exists_for_permit($mapping['field'])) {
            $csv_index = $_rcgr_import_csv_map[$csv_field];
            if (isset($hist_row[$csv_index])) {
              $term_name = trim($hist_row[$csv_index], '"');
              if (!empty($term_name)) {
                $tid = get_permit_taxonomy_term_id(
                  $term_name,
                  $mapping['vocabulary'],
                  TRUE,
                  $term_cache,
                  $taxonomy_value_mappings
                );
                if ($tid !== NULL) {
                  $node->set($mapping['field'], ['target_id' => $tid]);
                }
              }
            }
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
$logger->notice("Users not found for permit records: {$_rcgr_users_not_found}");
$logger->notice("Users imported on-demand: {$_rcgr_users_imported}");
