<?php

/**
 * @file
 * Drush script to map CSV data to Permit 3186A nodes.
 *
 * Usage: drush scr scripts/falcon/CsvFieldMapper.php [limit]
 *   where [limit] is an optional number of records to process.
 */

// Get the limit parameter if provided.
$limit = NULL;
if (!empty($extra[0]) && is_numeric($extra[0])) {
  $limit = (int) $extra[0];
}

$csv_file = DRUPAL_ROOT . '/sites/falcon/files/falcon-data/falc_dad_3186a_master_202502271311.csv';

if (!file_exists($csv_file)) {
  echo "CSV file not found at: $csv_file\n";
  exit(1);
}

if ($limit !== NULL) {
  echo "Processing with limit of $limit records\n";
}

/**
 * Maps CSV data to Permit 3186A nodes.
 */
class CsvFieldMapper {
  /**
   * The maximum number of rows to process.
   *
   * @var int|null
   */
  protected $limit;

  /**
   * Constructor.
   *
   * @param int|null $limit
   *   The maximum number of rows to process, or NULL for no limit.
   */
  public function __construct(?int $limit = NULL) {
    $this->limit = $limit;
  }

  /**
   * Field mappings from CSV to Drupal fields.
   *
   * @var array
   */
  protected $fieldMappings = [
    // Sender information.
    'sender_first_name' => 'field_sender_first_name',
    'sender_middle_name' => 'field_sender_middle_name',
    'sender_last_name' => 'field_sender_last_name',
    'sender_address' => 'field_sender_street_address',
    'sender_city' => 'field_sender_city',
    'sender_state' => 'field_sender_st_cd',
    'sender_zip' => 'field_sender_zip_cd',
    'sender_phone' => 'field_sender_phone',
    'sender_email' => 'field_sender_email_address',
    'sender_permit_no' => 'field_sender_permit_no',
    'sender_permit_type' => 'field_sender_permit_type_cd',
    'sender_permit_other' => 'field_sender_permit_other',
    'sender_transfer_type' => 'field_sender_transfer_type_cd',
    'sender_transfer_date' => 'field_sender_dt_transfer',
    'sender_release_code' => 'field_sender_release_cd',
    'sender_cause_of_death' => 'field_sender_cause_of_death',

    // Recipient information.
    'recipient_first_name' => 'field_recipient_first_name',
    'recipient_middle_name' => 'field_recipient_middle_name',
    'recipient_last_name' => 'field_recipient_last_name',
    'recipient_address' => 'field_recipient_street_address',
    'recipient_city' => 'field_recipient_city',
    'recipient_state' => 'field_recipient_st_cd',
    'recipient_zip' => 'field_recipient_zip_cd',
    'recipient_phone' => 'field_recipient_phone',
    'recipient_email' => 'field_recipient_email_address',
    'recipient_permit_no' => 'field_recipient_permit_no',
    'recipient_permit_type' => 'field_recipient_permit_type_cd',
    'recipient_permit_other' => 'field_recipient_permit_other',
    'recipient_transfer_type' => 'field_recipient_trans_type_cd',
    'recipient_date_acquired' => 'field_recipient_dt_acquired',

    // Species information.
    'species_cd' => 'field_species_cd',
    'species_name' => 'field_species_name',
    'species_source' => 'field_species_source',
    'species_sex' => 'field_species_sex',
    'species_age' => 'field_species_age',
    'species_color' => 'field_species_color',
    'species_hatch_year' => 'field_species_hatch_year',
    'species_band_no' => 'field_species_band_no',
    'species_band_type' => 'field_species_band_old_type',
    'species_band_old_no' => 'field_species_band_old_no',
    'species_band_new_no' => 'field_species_band_new_no',
    'species_band_new_type' => 'field_species_band_new_type',
    'species_chip_no' => 'field_species_chip_no',

    // Location information.
    'trap_state' => 'field_state_of_trap_location',
    'trap_county' => 'field_trap_county',
    'latitude' => 'field_latitude_num',
    'longitude' => 'field_longitude_num',

    // Falconry applicant information.
    'falc_applicant_name' => 'field_falc_applicant_name',
    'falc_applicant_phone' => 'field_falc_applicant_phone',
    'falc_applicant_agreed' => 'field_falc_applicant_agreed',
    'falc_date_signed' => 'field_falc_dt_signed',

    // System fields.
    'authorized_code' => 'field_authorized_cd',
    'capture_recapture' => 'field_capture_recapture_cd',
    'rcf_code' => 'field_rcf_cd',
    'owner_access' => 'field_owner_access_cd',
    'owner_state' => 'field_owner_state',
    'record_no' => 'field_recno',
    'hid' => 'field_hid',
    'question_no' => 'field_question_no',
    'comments' => 'field_comments',
    'created_by' => 'field_created_by',
    'updated_by' => 'field_updated_by',
    'date_created' => 'field_dt_create',
    'date_updated' => 'field_dt_update',
    'last_action' => 'field_last_action',
  ];

  /**
   * Fields that reference taxonomy terms.
   *
   * @var array
   */
  protected $taxonomyFields = [
    'field_species_cd' => 'species',
    'field_sender_st_cd' => 'state',
    'field_recipient_st_cd' => 'state',
    'field_state_of_trap_location' => 'state',
    'field_owner_state' => 'state',
    'field_sender_permit_type_cd' => 'permit_type',
    'field_recipient_permit_type_cd' => 'permit_type',
    'field_sender_transfer_type_cd' => 'type_of_transfer',
    'field_recipient_trans_type_cd' => 'type_of_transfer',
    'field_sender_release_cd' => 'if_release_or_loss',
    'field_authorized_cd' => 'authorized_code',
    'field_capture_recapture_cd' => 'capture_recapture',
    'field_rcf_cd' => 'rcf_code',
    'field_owner_access_cd' => 'access_code',
    'field_species_age' => 'age',
  ];

  /**
   * Get or create a taxonomy term.
   *
   * @param string $name
   *   The term name.
   * @param string $vocabulary
   *   The vocabulary machine name.
   *
   * @return \Drupal\taxonomy\TermInterface|null
   *   The term object or NULL if creation fails.
   */
  protected function getOrCreateTerm(string $name, string $vocabulary) {
    if (empty($name)) {
      return NULL;
    }

    // First try to find the term by name.
    $terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadByProperties([
        'vid' => $vocabulary,
        'name' => $name,
      ]);

    if (!empty($terms)) {
      return reset($terms);
    }

    try {
      // Create new term if it doesn't exist.
      $term = \Drupal::entityTypeManager()
        ->getStorage('taxonomy_term')
        ->create([
          'vid' => $vocabulary,
          'name' => $name,
        ]);
      $term->save();
      echo "Created taxonomy term '$name' in vocabulary '$vocabulary'\n";
      return $term;
    }
    catch (Exception $e) {
      echo "Error creating taxonomy term '$name' in vocabulary '$vocabulary': " . $e->getMessage() . "\n";
      return NULL;
    }
  }

  /**
   * Format a date string to Drupal format.
   *
   * @param string $date_str
   *   The date string to format.
   *
   * @return string|null
   *   The formatted date or NULL if invalid.
   */
  protected function formatDate(string $date_str) {
    if (empty($date_str)) {
      return NULL;
    }

    try {
      $date = new DateTime($date_str);
      return $date->format('Y-m-d');
    }
    catch (Exception $e) {
      return NULL;
    }
  }

  /**
   * Map a CSV row to Drupal field values.
   *
   * @param array $row
   *   The CSV row data.
   *
   * @return array
   *   The mapped Drupal field values.
   */
  protected function mapRow(array $row) {
    $values = [
      'type' => 'permit_3186a',
      'title' => sprintf('Permit 3186A - %s', $row['record_no'] ?? 'Unknown'),
      'status' => 1,
    ];

    foreach ($this->fieldMappings as $csv_field => $drupal_field) {
      if (isset($row[$csv_field]) && $row[$csv_field] !== '') {
        $value = $row[$csv_field];

        // Handle special field types.
        if (str_ends_with($drupal_field, '_dt_') || str_ends_with($drupal_field, '_date')) {
          $value = $this->formatDate($value);
        }
        elseif (isset($this->taxonomyFields[$drupal_field])) {
          // Special handling for species code - ensure it's properly formatted.
          if ($drupal_field === 'field_species_cd') {
            if (is_numeric($value)) {
              $value = sprintf('%04d', (int) $value);
            }
            // Also store the species name for reference.
            if (!empty($row['species_name'])) {
              $values['field_species_name'] = $row['species_name'];
            }
          }

          // Create the term if it doesn't exist.
          $term = $this->getOrCreateTerm($value, $this->taxonomyFields[$drupal_field]);
          // Only set the value if we successfully got a term.
          if ($term && $term->id()) {
            $value = ['target_id' => $term->id()];
          }
          else {
            $value = NULL;
            echo "Warning: Could not create or find taxonomy term '$value' for vocabulary '{$this->taxonomyFields[$drupal_field]}' in field '$drupal_field'\n";
          }
        }
        elseif (str_ends_with($drupal_field, '_num')) {
          $value = is_numeric($value) ? (float) $value : NULL;
        }
        elseif (str_ends_with($drupal_field, '_agreed')) {
          $value = in_array(strtolower($value), ['true', 'yes', '1', 't']);
        }

        if ($value !== NULL) {
          $values[$drupal_field] = $value;
        }
      }
    }

    // Validate required fields.
    if (empty($values['field_species_cd'])) {
      $record_no = $row['record_no'] ?? 'Unknown';
      $species_code = $row['species_cd'] ?? 'Not provided';
      throw new Exception("Missing required species code for record $record_no (Species code provided: $species_code)");
    }

    return $values;
  }

  /**
   * Process the CSV file and create nodes.
   *
   * @param string $file_path
   *   The path to the CSV file.
   *
   * @throws \Exception
   *   If the file cannot be opened or processed.
   */
  public function processFile(string $file_path) {
    if (($handle = fopen($file_path, 'r')) === FALSE) {
      throw new Exception("Could not open file: $file_path");
    }

    $headers = fgetcsv($handle);
    $row_count = 0;
    $success_count = 0;
    $error_count = 0;

    while (($data = fgetcsv($handle)) !== FALSE) {
      $row_count++;

      // Check if we've hit the limit.
      if ($this->limit !== NULL && $success_count >= $this->limit) {
        echo "Reached limit of {$this->limit} records\n";
        break;
      }

      $row = array_combine($headers, $data);

      try {
        $values = $this->mapRow($row);
        $node = \Drupal::entityTypeManager()
          ->getStorage('node')
          ->create($values);
        $node->save();
        $success_count++;
        echo "Created node {$node->id()} for record {$row['record_no']}\n";
      }
      catch (Exception $e) {
        $error_count++;
        echo "Error processing row {$row_count}: {$e->getMessage()}\n";
      }
    }

    fclose($handle);

    echo "\nProcessing complete:\n";
    echo "Total rows processed: $row_count\n";
    echo "Successful imports: $success_count\n";
    echo "Errors: $error_count\n";
  }

}

try {
  $mapper = new CsvFieldMapper($limit);
  $mapper->processFile($csv_file);
}
catch (Exception $e) {
  echo "Error: {$e->getMessage()}\n";
  exit(1);
}
