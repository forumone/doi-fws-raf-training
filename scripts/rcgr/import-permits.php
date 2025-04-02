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

// Process each row.
while (($row = fgetcsv($handle)) !== FALSE) {
  $stats['total']++;

  // Skip if we've reached the limit.
  if ($stats['processed'] >= $limit) {
    $logger->notice("Reached import limit of {$limit} records. Stopping.");
    break;
  }

  // Remove quotes from values.
  $row = array_map(function ($value) {
    return trim($value, '"');
  }, $row);

  // Skip empty rows.
  if (empty($row) || (count($row) === 1 && empty($row[0]))) {
    $stats['skipped']++;
    continue;
  }

  // Get permit number.
  $permit_no = trim($row[$_rcgr_import_csv_map['permit_no']]);
  if (empty($permit_no)) {
    $stats['skipped']++;
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
      foreach ([
        'applicant_address_l1',
        'applicant_address_l2',
        'applicant_address_l3',
      ] as $address_field) {
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

          // Only get/create taxonomy term if we have a valid value.
          if ($value !== NULL) {
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
          else {
            // Clear the field if validation returned NULL.
            $node->set($mapping['field'], NULL);
          }
        }
      }

      try {
        $node->save();
        $stats['created']++;
        $stats['processed']++;
      }
      catch (\Exception $e) {
        $logger->error("Failed to create permit node for permit number {$permit_no}: " . $e->getMessage());
        $stats['errors']++;
      }
    }
    else {
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
        foreach ([
          'applicant_address_l1',
          'applicant_address_l2',
          'applicant_address_l3',
        ] as $address_field) {
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
        try {
          $node->save();
          $stats['updated']++;
          $stats['processed']++;
        }
        catch (\Exception $e) {
          $logger->error("Failed to update permit node for permit number {$permit_no}: " . $e->getMessage());
          $stats['errors']++;
        }
      }
      else {
        $stats['skipped']++;
        $stats['processed']++;
      }
    }
  }
  catch (\Exception $e) {
    $logger->error('Error processing permit ' . $permit_no . ': ' . $e->getMessage());
    $stats['errors']++;
    $stats['processed']++;
  }

  // Progress update every 100 records.
  if ($stats['processed'] % 100 === 0) {
    $logger->warning("Processing progress: {$stats['processed']} records processed (Created: {$stats['created']}, Updated: {$stats['updated']}, Skipped: {$stats['skipped']}, Errors: {$stats['errors']})");
  }

  // Check again at the end of each iteration if we've reached the limit.
  if ($limit > 0 && $stats['processed'] >= $limit) {
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
$logger->warning('Import complete. Total read: ' . $stats['total'] . ', Created: ' . $stats['created'] . ', Updated: ' . $stats['updated'] . ', Skipped: ' . $stats['skipped'] . ', Errors: ' . $stats['errors']);
