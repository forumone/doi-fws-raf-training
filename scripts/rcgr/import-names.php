<?php

/**
 * @file
 * Imports name data from CSV file into Drupal name nodes.
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
  $logger->notice("Limiting import to {$limit} records");
}
else {
  $logger->notice("No limit specified - will import all records");
}

// Set the batch size for processing.
$batch_size = 50;

// Get the CSV file path.
$csv_file = __DIR__ . '/data/rcgr_name_202503031405.csv';

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
  'isRemoved' => 'field_is_removed',
  'permit_no' => 'field_permit_no',
  'report_year' => 'field_report_year',
  'person_name' => 'field_person_name',
  'version_no' => 'field_version_no',
  'hid' => 'field_hid',
  'program_id' => 'field_program_id',
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

// Initialize taxonomy term cache.
$term_cache = [];

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
  // Remove global region reference since we're not using it
  // global $_rcgr_fws_regions;.
  // Skip empty values.
  if (empty($name)) {
    $_rcgr_import_logger->warning("Empty value provided for vocabulary '{$vocabulary}'");
    return NULL;
  }

  // Check if we need to map the value to a proper term name.
  if (isset($value_mappings[$name])) {
    $name = $value_mappings[$name];
  }

  // Remove special handling for region terms.
  /*
  // Special handling for region terms.
  if ($vocabulary === 'region') {
  // If it's a numeric region, convert to descriptive name.
  if (is_numeric($name)) {
  if (isset($_rcgr_fws_regions[$name])) {
  $name = $_rcgr_fws_regions[$name]['name'];
  }
  else {
  $_rcgr_import_logger->warning("Invalid region number: {$name}");
  return NULL;
  }
  }
  }
   */

  // Check the cache first.
  $cache_key = "{$vocabulary}:{$name}";
  if (!$force_new_term && isset($term_cache[$cache_key])) {
    return $term_cache[$cache_key];
  }

  // Look up the term.
  $terms = \Drupal::entityTypeManager()
    ->getStorage('taxonomy_term')
    ->loadByProperties([
      'vid' => $vocabulary,
      'name' => $name,
    ]);

  if (!empty($terms)) {
    $term = reset($terms);
    $term_cache[$cache_key] = $term->id();
    return $term->id();
  }

  // Create the term if it doesn't exist and we're allowed to.
  if ($create_if_missing) {
    try {
      $term = Term::create([
        'vid' => $vocabulary,
        'name' => $name,
      ]);
      if ($vocabulary === 'region' && isset($_rcgr_fws_regions[$name])) {
        $term->set('description', $_rcgr_fws_regions[$name]['description']);
      }
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
      $logger->notice("Updating existing name record {$row['recno']}");
    }
    else {
      $node = Node::create(['type' => 'name']);
      $logger->notice("Creating new name record {$row['recno']}");
    }

    $node->setTitle($row['person_name']);

    // Set simple field values.
    foreach ($field_mapping as $csv_column => $field_name) {
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
          $term_id = get_taxonomy_term_id($value, str_replace('field_', '', $field_name), TRUE, $term_cache);
          if ($term_id) {
            $node->set($field_name, ['target_id' => $term_id]);
          }
          continue;
        }

        // Handle entity reference fields.
        if ($field_name === 'field_program_id') {
          // Look up the program node by program_id field.
          $query = \Drupal::entityQuery('node')
            ->condition('type', 'program')
            ->condition('field_program_id', $value)
            ->accessCheck(FALSE);
          $program_nids = $query->execute();

          if (!empty($program_nids)) {
            $node->set($field_name, ['target_id' => reset($program_nids)]);
          }
          continue;
        }

        // Set the field value.
        $node->set($field_name, $value);
      }
    }

    // Save the node.
    $node->save();
    $processed++;

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
$logger->notice("- Skipped: {$skipped}");
$logger->notice("- Errors: {$errors}");

// Only exit explicitly if there were actual errors.
if ($errors > 0) {
  exit(1);
}
