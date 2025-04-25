<?php

/**
 * @file
 * Drush script to map CSV data to Permit 3186A nodes.
 *
 * Usage: drush scr scripts/falcon/import_species_3186a.php [limit]
 *   where [limit] is an optional number of records to process.
 */

// Get the limit parameter if provided.
$limit = NULL;
if (!empty($extra[0]) && is_numeric($extra[0])) {
  $limit = (int) $extra[0];
}

$csv_file = DRUPAL_ROOT . '/sites/falcon/files/falcon-data/falc_dad_3186a_master_202502271311.csv';
$limbo_csv_file = DRUPAL_ROOT . '/sites/falcon/files/falcon-data/falc_dad_3186a_master_limbo_202502271311.csv';

if (!file_exists($csv_file)) {
  echo "CSV file not found at: $csv_file\n";
  exit(1);
}

if (!file_exists($limbo_csv_file)) {
  echo "Limbo CSV file not found at: $limbo_csv_file\n";
  exit(1);
}

if ($limit !== NULL) {
  echo "Processing with limit of $limit records\n";
}

/**
 * Maps CSV data to Permit 3186A nodes.
 */
class ImportSpecies3186a {
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
    'sender_street_address' => 'field_sender_street_address',
    'sender_city' => 'field_sender_city',
    'sender_st_cd' => 'field_sender_st_cd',
    'sender_zip_cd' => 'field_sender_zip_cd',
    'sender_phone' => 'field_sender_phone',
    'sender_email_address' => 'field_sender_email_address',
    'sender_permit_no' => 'field_sender_permit_no',
    'sender_permit_type_cd' => 'field_sender_permit_type_cd',
    'sender_permit_other' => 'field_sender_permit_other',
    'sender_transfer_type_cd' => 'field_sender_transfer_type_cd',
    'sender_dt_transfer' => 'field_sender_dt_transfer',
    'sender_release_cd' => 'field_sender_release_cd',
    'sender_cause_of_death' => 'field_sender_cause_of_death',

    // Recipient information.
    'recipient_first_name' => 'field_recipient_first_name',
    'recipient_middle_name' => 'field_recipient_middle_name',
    'recipient_last_name' => 'field_recipient_last_name',
    'recipient_street_address' => 'field_recipient_street_address',
    'recipient_city' => 'field_recipient_city',
    'recipient_st_cd' => 'field_recipient_st_cd',
    'recipient_zip_cd' => 'field_recipient_zip_cd',
    'recipient_phone' => 'field_recipient_phone',
    'recipient_email_address' => 'field_recipient_email_address',
    'recipient_permit_no' => 'field_recipient_permit_no',
    'recipient_permit_type_cd' => 'field_recipient_permit_type_cd',
    'recipient_permit_other' => 'field_recipient_permit_other',
    'recipient_transaction_type_cd' => 'field_recipient_trans_type_cd',
    'recipient_dt_acquired' => 'field_recipient_dt_acquired',

    // Species information.
    'species_cd' => 'field_species_cd',
    'species_name' => 'field_species_name',
    'species_source' => 'field_species_source',
    'species_sex' => 'field_species_sex',
    'species_age' => 'field_species_age',
    'species_color' => 'field_species_color',
    'species_hatch_year' => 'field_species_hatch_year',
    'species_band_no' => 'field_species_band_no',
    'species_band_old_type' => 'field_species_band_old_type',
    'species_band_old_no' => 'field_species_band_old_no',
    'species_band_new_no' => 'field_species_band_new_no',
    'species_band_new_type' => 'field_species_band_new_type',
    'species_chip_no' => 'field_species_chip_no',

    // Location information.
    'state_of_trap_location' => 'field_state_of_trap_location',
    'trap_county' => 'field_trap_county',
    'latitude_num' => 'field_geolocation.lat',
    'longitude_num' => 'field_geolocation.lng',

    // Falconry applicant information.
    'falc_applicant_name' => 'field_falc_applicant_name',
    'falc_applicant_phone' => 'field_falc_applicant_phone',
    'falc_applicant_agreed' => 'field_falc_applicant_agreed',
    'falc_dt_signed' => 'field_falc_dt_signed',

    // System fields.
    'authorized_cd' => 'field_authorized_cd',
    'capture_recapture_cd' => 'field_capture_recapture_cd',
    'rcf_cd' => 'field_rcf_cd',
    'owner_access_cd' => 'field_owner_access_cd',
    'owner_state' => 'field_owner_state',
    'recno' => 'field_recno',
    'hid' => 'field_hid',
    'question_no' => 'field_question_no',
    'comments' => 'field_comments',
    'created_by' => 'field_created_by',
    'updated_by' => 'field_updated_by',
    'dt_create' => 'field_dt_create',
    'dt_update' => 'field_dt_update',
    'last_action' => 'field_last_action',
  ];

  /**
   * Fields that reference taxonomy terms.
   *
   * @var array
   */
  protected $taxonomyFields = [
    'field_species_cd' => 'species_code',
    'field_species_name' => 'species',
    'field_species_sex' => 'sex',
    'field_species_source' => 'source',
    'field_sender_st_cd' => 'state',
    'field_recipient_st_cd' => 'state',
    'field_state_of_trap_location' => 'state',
    'field_sender_permit_type_cd' => 'permit_type',
    'field_recipient_permit_type_cd' => 'permit_type',
    'field_sender_transfer_type_cd' => 'type_of_acquisition',
    'field_recipient_trans_type_cd' => 'type_of_acquisition',
    'field_sender_release_cd' => 'if_release_or_loss',
    'field_species_age' => 'age',
    'field_owner_state' => 'state',
  ];

  /**
   * Fields that should be treated as plain strings.
   *
   * @var array
   */
  protected $stringFields = [
    'field_authorized_cd',
    'field_owner_access_cd',
    'field_rcf_cd',
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
   * @param string $field_name
   *   The Drupal field name.
   *
   * @return array|null
   *   The formatted date or NULL if invalid.
   */
  protected function formatDate(string $date_str, string $field_name) {
    if (empty($date_str)) {
      return NULL;
    }

    try {
      $date = new DateTime($date_str);
      // For fields with dt_ in their name or ending in _dt, use datetime format.
      if (strpos($field_name, 'dt_') !== FALSE || str_ends_with($field_name, '_dt')) {
        return [
          'value' => $date->format('Y-m-d H:i:s'),
        ];
      }
      return [
        'value' => $date->format('Y-m-d'),
      ];
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
      'title' => sprintf('Permit 3186A - %s', $row['recno'] ?? 'Unknown'),
      'status' => 1,
    ];

    foreach ($this->fieldMappings as $csv_field => $drupal_field) {
      if (isset($row[$csv_field])) {
        $value = trim($row[$csv_field]);

        // Handle special field types.
        if (str_ends_with($drupal_field, '_dt_') || str_ends_with($drupal_field, '_date') || strpos($drupal_field, 'dt_') !== FALSE) {
          $value = $this->formatDate($value, $drupal_field);
          if ($value !== NULL) {
            $values[$drupal_field] = $value;
          }
          continue;
        }
        elseif (isset($this->taxonomyFields[$drupal_field])) {
          // Skip empty values for taxonomy fields.
          if (empty($value)) {
            continue;
          }

          // Special handling for species code - ensure it's properly formatted.
          if ($drupal_field === 'field_species_cd' && is_numeric($value)) {
            $value = sprintf('%04d', (int) $value);
          }

          // Create or get the term.
          $term = $this->getOrCreateTerm($value, $this->taxonomyFields[$drupal_field]);
          if ($term && $term->id()) {
            $values[$drupal_field] = [
              'target_id' => $term->id(),
            ];
            echo "Linked term '$value' to field '$drupal_field'\n";
          }
          else {
            echo "Warning: Could not create or find taxonomy term '$value' for vocabulary '{$this->taxonomyFields[$drupal_field]}' in field '$drupal_field'\n";
            continue;
          }
        }
        elseif (in_array($drupal_field, $this->stringFields)) {
          // Handle string fields.
          if (!empty($value)) {
            $values[$drupal_field] = ['value' => $value];
            echo "Set string value '$value' for field '$drupal_field'\n";
          }
        }
        elseif (str_ends_with($drupal_field, '_num')) {
          $value = is_numeric($value) ? (float) $value : NULL;
        }
        elseif (str_ends_with($drupal_field, '_agreed')) {
          $value = in_array(strtolower($value), ['true', 'yes', '1', 't']);
        }
        elseif (strpos($drupal_field, '.')) {
          [$drupal_field, $property] = explode('.', $drupal_field);
          $values[$drupal_field]['value'][$property] = $value;
        }
        else {
          $values[$drupal_field] = ['value' => $value];
        }
      }
    }

    // Validate required fields.
    if (empty($values['field_species_cd'])) {
      $record_no = $row['recno'] ?? 'Unknown';
      $species_code = $row['species_cd'] ?? 'Not provided';
      throw new Exception("Missing required species code for record $record_no (Species code provided: $species_code)");
    }

    // Look up the user ID based on the created_by field
    if (!empty($row['created_by'])) {
      $username = trim($row['created_by']);

      // Look up the user by username
      $users = \Drupal::entityTypeManager()
        ->getStorage('user')
        ->loadByProperties(['name' => $username]);

      if (!empty($users)) {
        $user = reset($users);
        $values['uid'] = $user->id();
      }
      else {
        // If user not found, default to admin (uid=1)
        $values['uid'] = 1;
      }
    }
    else {
      // If created_by is empty, default to admin (uid=1)
      $values['uid'] = 1;
    }

    return $values;
  }

  /**
   * Process the CSV file and create nodes.
   *
   * @param string $file_path
   *   The path to the CSV file.
   * @param bool $is_limbo
   *   Whether this is processing limbo records.
   *
   * @throws \Exception
   *   If the file cannot be opened or processed.
   */
  public function processFile(string $file_path, bool $is_limbo = FALSE) {
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

        // Set status for limbo records.
        if ($is_limbo) {
          // Unpublished.
          $values['status'] = 0;
          // Mark as deleted with current timestamp.
          $values['deleted'] = time();
        }

        $node = \Drupal::entityTypeManager()
          ->getStorage('node')
          ->create($values);
        $node->save();
        $success_count++;
        echo "Created node {$node->id()} for record {$row['recno']}" . ($is_limbo ? " (limbo/deleted)" : "") . "\n";
      }
      catch (Exception $e) {
        $error_count++;
        echo "Error processing row {$row_count}: {$e->getMessage()}\n";
      }
    }

    fclose($handle);

    echo "\nProcessing complete for " . ($is_limbo ? "limbo" : "active") . " records:\n";
    echo "Total rows processed: $row_count\n";
    echo "Successful imports: $success_count\n";
    echo "Errors: $error_count\n";
  }

}

try {
  $mapper = new ImportSpecies3186a($limit);

  // Process regular records.
  echo "\nProcessing regular records...\n";
  $mapper->processFile($csv_file);

  // Process limbo records.
  echo "\nProcessing limbo records...\n";
  $mapper->processFile($limbo_csv_file, TRUE);
}
catch (Exception $e) {
  echo "Error: {$e->getMessage()}\n";
  exit(1);
}
