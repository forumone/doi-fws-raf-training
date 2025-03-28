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

// Define FWS region names and descriptions.
$fws_regions = [
  '1' => [
    'name' => 'Pacific Coast (CA, ID, NV, OR, WA)',
    'description' => 'FWS Region 1: Pacific Coast states including California, Idaho, Nevada, Oregon, and Washington.',
  ],
  '2' => [
    'name' => 'Southwest (AZ, NM, OK, TX)',
    'description' => 'FWS Region 2: Southwest states including Arizona, New Mexico, Oklahoma, and Texas.',
  ],
  '3' => [
    'name' => 'Great Lakes/Upper Midwest (IA, IL, IN, MI, MN, MO, OH, WI)',
    'description' => 'FWS Region 3: Great Lakes and Upper Midwest states including Iowa, Illinois, Indiana, Michigan, Minnesota, Missouri, Ohio, and Wisconsin.',
  ],
  '4' => [
    'name' => 'Southeast (AL, AR, FL, GA, KY, LA, MS, NC, SC, TN)',
    'description' => 'FWS Region 4: Southeast states including Alabama, Arkansas, Florida, Georgia, Kentucky, Louisiana, Mississippi, North Carolina, South Carolina, and Tennessee.',
  ],
  '5' => [
    'name' => 'Northeast (CT, DC, DE, MA, MD, ME, NH, NJ, NY, PA, RI, VA, VT, WV)',
    'description' => 'FWS Region 5: Northeast states including Connecticut, District of Columbia, Delaware, Massachusetts, Maryland, Maine, New Hampshire, New Jersey, New York, Pennsylvania, Rhode Island, Virginia, Vermont, and West Virginia.',
  ],
  '6' => [
    'name' => 'Mountain-Prairie (CO, KS, MT, ND, NE, SD, UT, WY)',
    'description' => 'FWS Region 6: Mountain-Prairie states including Colorado, Kansas, Montana, North Dakota, Nebraska, South Dakota, Utah, and Wyoming.',
  ],
  '7' => [
    'name' => 'Alaska (AK)',
    'description' => 'FWS Region 7: The state of Alaska.',
  ],
];

// Define state to region mappings.
$state_region_mappings = [
  // Region 1: Pacific Coast.
  'CA' => '1',
  'ID' => '1',
  'NV' => '1',
  'OR' => '1',
  'WA' => '1',
  // Region 2: Southwest.
  'AZ' => '2',
  'NM' => '2',
  'OK' => '2',
  'TX' => '2',
  // Region 3: Great Lakes/Upper Midwest.
  'IA' => '3',
  'IL' => '3',
  'IN' => '3',
  'MI' => '3',
  'MN' => '3',
  'MO' => '3',
  'OH' => '3',
  'WI' => '3',
  // Region 4: Southeast.
  'AL' => '4',
  'AR' => '4',
  'FL' => '4',
  'GA' => '4',
  'KY' => '4',
  'LA' => '4',
  'MS' => '4',
  'NC' => '4',
  'SC' => '4',
  'TN' => '4',
  // Region 5: Northeast.
  'CT' => '5',
  'DC' => '5',
  'DE' => '5',
  'MA' => '5',
  'MD' => '5',
  'ME' => '5',
  'NH' => '5',
  'NJ' => '5',
  'NY' => '5',
  'PA' => '5',
  'RI' => '5',
  'VA' => '5',
  'VT' => '5',
  'WV' => '5',
  // Region 6: Mountain-Prairie.
  'CO' => '6',
  'KS' => '6',
  'MT' => '6',
  'ND' => '6',
  'NE' => '6',
  'SD' => '6',
  'UT' => '6',
  'WY' => '6',
  // Region 7: Alaska.
  'AK' => '7',
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

// Load region reference data.
$region_reference_file = dirname(__FILE__) . '/data/rcgr_ref_states_202503031405.csv';
$logger->notice('Loading region reference data from: ' . $region_reference_file);
$region_handle = fopen($region_reference_file, 'r');

if ($region_handle === FALSE) {
  $logger->error('Could not open region reference file: ' . $region_reference_file);
  return;
}

// Skip header row.
fgetcsv($region_handle);

// Build region mapping.
$valid_regions = [];
$state_to_region = [];
while (($row = fgetcsv($region_handle)) !== FALSE) {
  if (!empty($row[2]) && !empty($row[0])) {
    $region_number = trim($row[2], '"');
    $state_code = trim($row[0], '"');
    // Skip header and empty rows.
    if ($region_number === 'Region' || $region_number === 'A' || empty($state_code)) {
      continue;
    }
    if (!isset($valid_regions[$region_number])) {
      $valid_regions[$region_number] = [];
    }
    $valid_regions[$region_number][] = $state_code;
    $state_to_region[$state_code] = $region_number;
  }
}
fclose($region_handle);

// Use our predefined state_region_mappings instead of the CSV data.
$state_to_region = $state_region_mappings;
$valid_regions = [];
foreach ($state_region_mappings as $state => $region) {
  if (!isset($valid_regions[$region])) {
    $valid_regions[$region] = [];
  }
  $valid_regions[$region][] = $state;
}

$logger->notice('Using official FWS region mappings for ' . count($state_region_mappings) . ' states');
$logger->notice('Valid FWS regions: ' . implode(', ', array_keys($fws_regions)));

/**
 * Get the proper region name based on state code.
 *
 * @param string $state_code
 *   The state code to look up.
 * @param array $state_region_mappings
 *   The array of state to region number mappings.
 * @param array $fws_regions
 *   The array of region data.
 *
 * @return string|null
 *   The proper region name or null if not found.
 */
function get_region_name_by_state($state_code, array $state_region_mappings, array $fws_regions) {
  global $_rcgr_import_logger;

  // Normalize state code.
  $state_code = trim(strtoupper($state_code));

  // Look up the region number for this state.
  if (isset($state_region_mappings[$state_code])) {
    $region_number = $state_region_mappings[$state_code];
    if (isset($fws_regions[$region_number])) {
      return $fws_regions[$region_number]['name'];
    }
  }

  $_rcgr_import_logger->warning("Could not map state {$state_code} to a valid region");
  return NULL;
}

/**
 * Formats a datetime string for Drupal storage.
 */
function format_datetime_for_drupal($datetime_string) {
  // Handle empty or invalid values.
  if (empty($datetime_string)) {
    return NULL;
  }

  try {
    // Remove milliseconds if present and trim quotes.
    $datetime_string = trim($datetime_string, '"');
    $datetime_string = preg_replace('/\.\d+/', '', $datetime_string);

    // Parse the datetime string and format it for Drupal.
    $datetime = new \DateTime($datetime_string);
    return $datetime->format('Y-m-d\TH:i:s');
  }
  catch (\Exception $e) {
    global $_rcgr_import_permits_logger;
    $_rcgr_import_permits_logger->warning("Could not parse date: {$datetime_string}");
    return NULL;
  }
}

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
  global $fws_regions;
  global $state_region_mappings;

  // Skip empty values.
  if (empty($name)) {
    $_rcgr_import_logger->warning("Empty value provided for vocabulary '{$vocabulary}'");
    return NULL;
  }

  // Check if we need to map the value to a proper term name.
  if (isset($value_mappings[$name])) {
    $name = $value_mappings[$name];
  }

  // Special handling for region terms.
  if ($vocabulary === 'region') {
    // If it's region "9", keep it as "Legacy Region 9".
    if ($name === '9') {
      $name = 'Legacy Region 9';
    }
    // If it's a numeric region, convert to descriptive name.
    elseif (is_numeric($name) && isset($fws_regions[$name])) {
      $name = $fws_regions[$name]['name'];
    }
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

    // Add description for region terms.
    if ($vocabulary === 'region') {
      if ($name === 'Legacy Region 9') {
        $term_data['description'] = [
          'value' => 'Legacy region code - not a valid FWS region',
          'format' => 'plain_text',
        ];
      }
      else {
        // Find the region number by matching the name.
        foreach ($fws_regions as $region_number => $region_data) {
          if ($region_data['name'] === $name) {
            $term_data['description'] = [
              'value' => $region_data['description'],
              'format' => 'plain_text',
            ];
            break;
          }
        }
      }
    }

    $term = Term::create($term_data);
    $term->save();
    $tid = $term->id();
    $term_cache[$cache_key] = $tid;
  }

  return $tid;
}

/**
 * Check if a field exists for the permit content type.
 *
 * @param string $field_name
 *   The field name to check.
 *
 * @return bool
 *   TRUE if the field exists, FALSE otherwise.
 */
function field_exists_for_permit($field_name) {
  $field_definitions = \Drupal::service('entity_field.manager')
    ->getFieldDefinitions('node', 'permit');
  return isset($field_definitions[$field_name]);
}

// Validate field mappings against existing fields.
$valid_fields = [];
foreach ($field_mappings as $csv_field => $drupal_field) {
  if (field_exists_for_permit($drupal_field)) {
    $valid_fields[$csv_field] = $drupal_field;
  }
}
$field_mappings = $valid_fields;

$valid_date_fields = [];
foreach ($date_field_mappings as $csv_field => $drupal_field) {
  if (field_exists_for_permit($drupal_field)) {
    $valid_date_fields[$csv_field] = $drupal_field;
  }
}
$date_field_mappings = $valid_date_fields;

$valid_taxonomy_fields = [];
foreach ($taxonomy_field_mappings as $csv_field => $mapping) {
  if (field_exists_for_permit($mapping['field'])) {
    $valid_taxonomy_fields[$csv_field] = $mapping;
  }
}
$taxonomy_field_mappings = $valid_taxonomy_fields;

// Log the start of the import.
$logger->notice('Starting import with properly fixed taxonomy reference handling.');

// Open the CSV file.
$csv_file = dirname(__FILE__) . '/data/rcgr_permit_app_mast_202503031405.csv';
$logger->notice('Opening CSV file: ' . $csv_file);
$handle = fopen($csv_file, 'r');

if ($handle === FALSE) {
  $logger->error('Could not open CSV file: ' . $csv_file);
  return;
}

// Read the header row and map column names to indices.
$header = fgetcsv($handle);
global $_rcgr_import_csv_map;
$_rcgr_import_csv_map = [
  'permit_no' => array_search('permit_no', $header),
  'version_no' => array_search('version_no', $header),
  'dt_create' => array_search('dt_create', $header),
  'create_by' => array_search('create_by', $header),
  'dt_update' => array_search('dt_update', $header),
  'update_by' => array_search('update_by', $header),
  'xml_cd' => array_search('xml_cd', $header),
  'rcf_cd' => array_search('rcf_cd', $header),
  'hid' => array_search('hid', $header),
  'site_id' => array_search('site_id', $header),
  'control_site_id' => array_search('control_site_id', $header),
  'program_id' => array_search('program_id', $header),
  'region' => array_search('region', $header),
  'control_program_id' => array_search('control_program_id', $header),
  'control_region' => array_search('control_region', $header),
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
  'dt_signed' => array_search('dt_signed', $header),
  'dt_permit_request' => array_search('dt_permit_request', $header),
  'dt_permit_issued' => array_search('dt_permit_issued', $header),
  'dt_effective' => array_search('dt_effective', $header),
  'dt_expired' => array_search('dt_expired', $header),
  'dt_applicant_signed' => array_search('dt_applicant_signed', $header),
  'dt_application_received' => array_search('dt_application_received', $header),
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
  'control_program_id' => 'field_control_program_id',
  'control_region' => 'field_control_region',
  'applicant_email_address' => 'field_bi_cd',
  'applicant_city' => 'field_location_city',
  'applicant_business_name' => 'field_business_name',
  'applicant_zip' => 'field_zip',
  'applicant_home_phone' => 'field_home_phone',
  'applicant_work_phone' => 'field_work_phone',
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
  'region' => [
    'field' => 'field_region',
    'vocabulary' => 'region',
    'validate' => function ($value, $row) use ($fws_regions, $state_region_mappings, $logger) {
      global $_rcgr_import_csv_map;
      // Get the state from the row data.
      $state = trim($row[$_rcgr_import_csv_map['applicant_state']], '"');

      // Look up the region number for this state.
      if (isset($state_region_mappings[$state])) {
        $region_number = $state_region_mappings[$state];
        $logger->notice("Mapped state {$state} to region {$region_number}");

        // Get the region name from fws_regions.
        if (isset($fws_regions[$region_number])) {
          $region_name = $fws_regions[$region_number]['name'];
          $logger->notice("Using region {$region_number}: {$region_name}");

          // Query for the existing taxonomy term.
          $query = \Drupal::entityQuery('taxonomy_term')
            ->condition('vid', 'region')
            ->condition('name', $region_name)
            ->accessCheck(FALSE)
            ->range(0, 1);
          $tids = $query->execute();

          if (!empty($tids)) {
            $tid = reset($tids);
            $logger->notice("Found existing region term ID: {$tid} for name: {$region_name}");
            return $region_name;
          }
          else {
            $logger->warning("No existing taxonomy term found for region name: {$region_name}");
            return NULL;
          }
        }
      }
      else {
        $logger->warning("Could not map state {$state} to a region");
      }

      return NULL;
    },
  ],
  'program_id' => [
    'field' => 'field_program_id',
    'vocabulary' => 'program',
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
$logger->notice('Using the following field mappings: ' . implode(', ', array_values($field_mappings)));
$logger->notice('Using the following date field mappings: ' . implode(', ', array_values($date_field_mappings)));
$logger->notice('Using the following taxonomy field mappings: ' . implode(', ', array_column($taxonomy_field_mappings, 'field')));

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

// Process each row.
while (($row = fgetcsv($handle)) !== FALSE) {
  $total++;

  // Skip if we've reached the limit.
  if ($processed >= $limit) {
    $logger->notice("Reached import limit of {$limit} records. Stopping.");
    break;
  }

  // Remove quotes from values.
  $row = array_map(function ($value) {
    return trim($value, '"');
  }, $row);

  // Skip empty rows.
  if (empty($row) || (count($row) === 1 && empty($row[0]))) {
    continue;
  }

  // Get permit number.
  $permit_no = trim($row[$_rcgr_import_csv_map['permit_no']]);
  if (empty($permit_no)) {
    continue;
  }

  try {
    // Check if a node with this permit number already exists.
    $query = \Drupal::entityQuery('node')
      ->condition('type', 'permit')
      ->condition('field_permit_no', $permit_no)
      ->accessCheck(FALSE);

    $nids = $query->execute();

    if (empty($nids)) {
      $logger->notice('Creating new permit node for: ' . $permit_no);

      // Create a new node.
      $node = Node::create([
        'type' => 'permit',
        'title' => 'Permit: ' . $permit_no,
        'status' => 1,
      ]);

      // Set regular field values.
      foreach ($field_mappings as $csv_field => $drupal_field) {
        if (isset($_rcgr_import_csv_map[$csv_field]) && isset($row[$_rcgr_import_csv_map[$csv_field]]) && $row[$_rcgr_import_csv_map[$csv_field]] !== '') {
          $node->set($drupal_field, trim($row[$_rcgr_import_csv_map[$csv_field]], '"'));
        }
      }

      // Set date field values.
      foreach ($date_field_mappings as $csv_field => $drupal_field) {
        if (isset($_rcgr_import_csv_map[$csv_field]) && isset($row[$_rcgr_import_csv_map[$csv_field]]) && $row[$_rcgr_import_csv_map[$csv_field]] !== '') {
          $datetime = format_datetime_for_drupal(trim($row[$_rcgr_import_csv_map[$csv_field]], '"'));
          if ($datetime !== FALSE) {
            $node->set($drupal_field, $datetime);
          }
        }
      }

      // Handle special field mappings.
      $address_lines = [];
      foreach (['applicant_address_l1', 'applicant_address_l2', 'applicant_address_l3'] as $address_field) {
        if (isset($_rcgr_import_csv_map[$address_field]) && isset($row[$_rcgr_import_csv_map[$address_field]]) && $row[$_rcgr_import_csv_map[$address_field]] !== '') {
          $address_lines[] = [
            'value' => trim($row[$_rcgr_import_csv_map[$address_field]], '"'),
            'format' => 'plain_text',
          ];
        }
      }
      if (!empty($address_lines)) {
        $node->set('field_location_address', $address_lines);
      }

      // Handle location certification.
      if (isset($_rcgr_import_csv_map['applicant_signed']) && isset($row[$_rcgr_import_csv_map['applicant_signed']])) {
        $is_certified = (int) trim($row[$_rcgr_import_csv_map['applicant_signed']], '"') === 1;
        $node->set('field_is_location_certified', $is_certified);
      }

      // Handle taxonomy field values.
      foreach ($taxonomy_field_mappings as $csv_field => $mapping) {
        if (isset($_rcgr_import_csv_map[$csv_field]) && isset($row[$_rcgr_import_csv_map[$csv_field]]) && $row[$_rcgr_import_csv_map[$csv_field]] !== '') {
          $value = trim($row[$_rcgr_import_csv_map[$csv_field]], '"');

          // Apply validation if provided.
          if (isset($mapping['validate'])) {
            $value = $mapping['validate']($value, $row);
          }

          // Get or create the taxonomy term.
          $tid = get_taxonomy_term_id(
            $value,
            $mapping['vocabulary'],
            TRUE,
            $term_cache,
            $taxonomy_value_mappings
          );

          if ($tid) {
            $node->set($mapping['field'], ['target_id' => $tid]);
          }
        }
      }

      try {
        $node->save();
        $processed++;
        $created++;
      }
      catch (\Exception $e) {
        $logger->error("Failed to create permit node for permit number {$permit_no}: " . $e->getMessage());
        $errors++;
      }
    }
    else {
      $logger->notice('Permit already exists: ' . $permit_no . '. Updating fields.');

      // Load the existing node.
      $nid = reset($nids);
      $node = Node::load($nid);
      $updated_fields = 0;

      // Update regular fields.
      foreach ($field_mappings as $csv_field => $drupal_field) {
        if (isset($_rcgr_import_csv_map[$csv_field]) && isset($row[$_rcgr_import_csv_map[$csv_field]]) && $row[$_rcgr_import_csv_map[$csv_field]] !== '') {
          if ($node->hasField($drupal_field)) {
            $current_value = $node->get($drupal_field)->value;
            $new_value = trim($row[$_rcgr_import_csv_map[$csv_field]], '"');

            if ($current_value !== $new_value) {
              $node->set($drupal_field, $new_value);
              $updated_fields++;
            }
          }
        }
      }

      // Update date fields.
      foreach ($date_field_mappings as $csv_field => $drupal_field) {
        if (isset($_rcgr_import_csv_map[$csv_field]) && isset($row[$_rcgr_import_csv_map[$csv_field]]) && $row[$_rcgr_import_csv_map[$csv_field]] !== '') {
          $formatted_date = format_datetime_for_drupal($row[$_rcgr_import_csv_map[$csv_field]]);
          if ($formatted_date && $node->hasField($drupal_field)) {
            $current_value = $node->get($drupal_field)->value;
            if ($current_value !== $formatted_date) {
              $node->set($drupal_field, $formatted_date);
              $updated_fields++;
            }
          }
        }
      }

      // Update special fields
      // Update location address fields.
      if ($node->hasField('field_location_address')) {
        $address_values = [];
        foreach (['applicant_address_l1', 'applicant_address_l2', 'applicant_address_l3'] as $address_field) {
          if (isset($row[$_rcgr_import_csv_map[$address_field]]) && $row[$_rcgr_import_csv_map[$address_field]] !== '') {
            $address_values[] = [
              'value' => trim($row[$_rcgr_import_csv_map[$address_field]], '"'),
              'format' => 'plain_text',
            ];
          }
        }
        if (!empty($address_values)) {
          $node->set('field_location_address', $address_values);
          $updated_fields++;
        }
      }

      // Update phone fields.
      if (field_exists_for_permit('field_home_phone') || field_exists_for_permit('field_work_phone')) {
        if (field_exists_for_permit('field_home_phone') && isset($_rcgr_import_csv_map['applicant_home_phone']) && isset($row[$_rcgr_import_csv_map['applicant_home_phone']]) && $row[$_rcgr_import_csv_map['applicant_home_phone']] !== '') {
          $node->set('field_home_phone', trim($row[$_rcgr_import_csv_map['applicant_home_phone']], '"'));
          $updated_fields++;
        }
        if (field_exists_for_permit('field_work_phone') && isset($_rcgr_import_csv_map['applicant_work_phone']) && isset($row[$_rcgr_import_csv_map['applicant_work_phone']]) && $row[$_rcgr_import_csv_map['applicant_work_phone']] !== '') {
          $node->set('field_work_phone', trim($row[$_rcgr_import_csv_map['applicant_work_phone']], '"'));
          $updated_fields++;
        }
      }

      // Update zip code.
      if (field_exists_for_permit('field_zip') && isset($_rcgr_import_csv_map['applicant_zip']) && isset($row[$_rcgr_import_csv_map['applicant_zip']]) && $row[$_rcgr_import_csv_map['applicant_zip']] !== '') {
        $node->set('field_zip', trim($row[$_rcgr_import_csv_map['applicant_zip']], '"'));
        $updated_fields++;
      }

      // Update taxonomy reference fields.
      foreach ($taxonomy_field_mappings as $csv_field => $mapping) {
        if (isset($_rcgr_import_csv_map[$csv_field]) && isset($row[$_rcgr_import_csv_map[$csv_field]]) && $row[$_rcgr_import_csv_map[$csv_field]] !== '') {
          if ($node->hasField($mapping['field'])) {
            $term_value = $row[$_rcgr_import_csv_map[$csv_field]];
            $tid = get_taxonomy_term_id(
              $term_value,
              $mapping['vocabulary'],
              TRUE,
              $term_cache,
              $taxonomy_value_mappings,
              !empty($mapping['force_new_term'])
            );

            if ($tid) {
              $current_target_id = NULL;
              if (!$node->get($mapping['field'])->isEmpty()) {
                $current_target_id = $node->get($mapping['field'])->target_id;
              }

              if ($current_target_id != $tid) {
                $node->set($mapping['field'], ['target_id' => $tid]);
                $updated_fields++;
              }
            }
          }
        }
      }

      // Save the node if fields were updated.
      if ($updated_fields > 0) {
        $node->save();
        $updated++;
        $processed++;

        // Check if we've reached the limit for processed nodes.
        if ($limit > 0 && $processed >= $limit) {
          $logger->notice("Reached limit of {$limit} processed records. Stopping import.");
          break;
        }
      }
      else {
        $skipped++;
      }
    }
  }
  catch (\Exception $e) {
    $logger->error('Error processing permit ' . $permit_no . ': ' . $e->getMessage());
    $errors++;
  }

  // Progress update every 100 records.
  if ($processed % 100 === 0) {
    $logger->notice("Processing progress: {$processed} records processed");
  }

  // Check again at the end of each iteration if we've reached the limit.
  if ($limit > 0 && $processed >= $limit) {
    $logger->notice("Reached limit of {$limit} processed records. Stopping import.");
    break;
  }
}

// Close the file handle.
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

// Log the final statistics.
$logger->notice('Import complete. Total read: ' . $total . ', Created: ' . $created . ', Updated: ' . $updated . ', Skipped: ' . $skipped . ', Errors: ' . $errors);
