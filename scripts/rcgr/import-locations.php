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

// Get the CSV file path.
$csv_file = __DIR__ . '/data/rcgr_location_202503031405.csv';

// Check if file exists.
if (!file_exists($csv_file)) {
  \Drupal::logger('rcgr')->error('CSV file not found at @file', ['@file' => $csv_file]);
  exit(1);
}

// Open the CSV file.
$handle = fopen($csv_file, 'r');
if ($handle === FALSE) {
  \Drupal::logger('rcgr')->error('Could not open CSV file');
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
  'program_id' => 'field_program_id',
  'region' => 'field_region',
  'site_id' => 'field_site_id',
  'control_program_id' => 'field_control_program_id',
  'control_region' => 'field_control_region',
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

// Define FWS region names and descriptions.
global $_rcgr_fws_regions;
$_rcgr_fws_regions = [
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
global $_rcgr_state_region_mappings;
$_rcgr_state_region_mappings = [
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

// Initialize taxonomy term cache.
$term_cache = [];

// Use our predefined state_region_mappings.
$state_to_region = $_rcgr_state_region_mappings;
$valid_regions = [];
foreach ($_rcgr_state_region_mappings as $state => $region) {
  if (!isset($valid_regions[$region])) {
    $valid_regions[$region] = [];
  }
  $valid_regions[$region][] = $state;
}

$logger->warning('Using official FWS region mappings for ' . count($_rcgr_state_region_mappings) . ' states');
$logger->warning('Valid FWS regions: ' . implode(', ', array_keys($_rcgr_fws_regions)));

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
  global $_rcgr_fws_regions;
  global $_rcgr_state_region_mappings;

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
    // If it's a numeric region, convert to descriptive name.
    if (is_numeric($name)) {
      if (isset($_rcgr_fws_regions[$name])) {
        $name = $_rcgr_fws_regions[$name]['name'];
      }
      else {
        $_rcgr_import_logger->warning("Numeric region '{$name}' not found in FWS regions");
        return NULL;
      }
    }
    // If the name doesn't match any FWS region name, return NULL.
    $valid_region_names = array_column($_rcgr_fws_regions, 'name');
    if (!in_array($name, $valid_region_names)) {
      $_rcgr_import_logger->warning("Region name '{$name}' not found in FWS regions");
      return NULL;
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
      // Find the region number by matching the name.
      foreach ($_rcgr_fws_regions as $region_number => $region_data) {
        if ($region_data['name'] === $name) {
          $term_data['description'] = [
            'value' => $region_data['description'],
            'format' => 'plain_text',
          ];
          break;
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

// Process each row.
$row_number = 0;
while (($row = fgetcsv($handle)) !== FALSE) {
  $row_number++;

  // Skip header row.
  if ($row_number === 1) {
    continue;
  }

  // Check if we've hit the limit.
  // +1 because we skipped the header row.
  if ($row_number > $limit + 1) {
    $logger->warning("Reached limit of {$limit} records, stopping import");
    break;
  }

  $data = array_combine($header, $row);

  try {
    // Create new location node.
    $node = Node::create([
      'type' => 'location',
      'title' => $data['location_address_l1'],
      'status' => 1,
    ]);

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
        case 'field_program_id':
        case 'field_region':
        case 'field_rcf_cd':
          // These are entity references - we'll need to look up the target ID.
          if (!empty($value)) {
            $entity_type = 'taxonomy_term';
            $bundle = '';
            // Define value mappings if needed, e.g., for state codes to full names.
            $value_mappings = [];

            switch ($drupal_field) {
              case 'field_location_state_ref':
                $bundle = 'states';
                break;

              case 'field_program_id':
                $bundle = 'program';
                break;

              case 'field_region':
                $bundle = 'region';
                // Region logic: Determine region name based on state.
                $state_value = $data['location_state'] ?? NULL;
                if (!empty($state_value)) {
                  $state_code = trim(strtoupper($state_value));
                  if (isset($_rcgr_state_region_mappings[$state_code])) {
                    $region_number = $_rcgr_state_region_mappings[$state_code];
                    if (isset($_rcgr_fws_regions[$region_number])) {
                      // Use the FWS region name.
                      $value = $_rcgr_fws_regions[$region_number]['name'];
                    }
                    else {
                      // State mapping found, but region number invalid.
                      $value = NULL;
                      \Drupal::logger('rcgr')->warning('Invalid region number @num found for state @state', [
                        '@num' => $region_number,
                        '@state' => $state_code,
                      ]);
                    }
                  }
                  else {
                    // State code not found in mapping.
                    $value = NULL;
                    \Drupal::logger('rcgr')->warning('Could not map state @state to a region', [
                      '@state' => $state_code,
                    ]);
                  }
                }
                else {
                  // State value is empty.
                  $value = NULL;
                }
                break;

              case 'field_rcf_cd':
                $bundle = 'rcf_cd';
                break;
            }

            // Only proceed if we have a valid value after potential mapping/determination.
            if ($value !== NULL) {
              $tid = get_taxonomy_term_id($value, $bundle, TRUE, $term_cache, $value_mappings);

              if ($tid) {
                $node->set($drupal_field, $tid);
              }
              else {
                // Log warning if term ID couldn't be found/created.
                \Drupal::logger('rcgr')->warning('Could not find or create @type term with name @name', [
                  '@type' => $bundle,
                  '@name' => $value,
                ]);
              }
            }
          }
          break;

        case 'field_dt_create':
        case 'field_dt_update':
          if (!empty($value)) {
            $date = new DrupalDateTime($value);
            $node->set($drupal_field, $date->format('Y-m-d\TH:i:s'));
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

        default:
          $node->set($drupal_field, $value);
      }
    }

    // Save the node.
    $node->save();
    $processed++;

    \Drupal::logger('rcgr')->info('Created location node @title', ['@title' => $node->getTitle()]);

    // Print progress.
    if ($processed % $batch_size === 0) {
      \Drupal::logger('rcgr')->info('Processed @count records...', ['@count' => $processed]);
    }

  }
  catch (Exception $e) {
    \Drupal::logger('rcgr')->error('Error processing record: @error', ['@error' => $e->getMessage()]);
    $errors++;
  }
}

// Close the file.
fclose($handle);

// Print summary.
echo "\nImport completed:\n";
echo "Processed: $processed\n";
echo "Skipped: $skipped\n";
echo "Errors: $errors\n";
