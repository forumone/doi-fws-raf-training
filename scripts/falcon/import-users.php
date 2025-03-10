<?php

/**
 * @file
 * Drush script to import users from CSV file.
 *
 * Usage: drush scr scripts/falcon/import-users.php [number]
 * The optional [number] parameter limits how many users to successfully import.
 */

use Drupal\user\Entity\User;
use Drush\Drush;

// Get the limit from command line argument if provided.
$input = Drush::input();
$args = $input->getArguments();
$limit = isset($args['extra'][1]) ? (int) $args['extra'][1] : PHP_INT_MAX;

// Define the CSV file path for DDEV environment.
$filename = DRUPAL_ROOT . '/sites/falcon/files/falcon-data/falc_sys_userprofile_202502271311.csv';
$limbo_filename = DRUPAL_ROOT . '/sites/falcon/files/falcon-data/falc_sys_userprofile_limbo_202502271311.csv';

// Initialize counters.
$stats = [
  'processed' => 0,
  'created' => 0,
  'updated' => 0,
  'skipped' => 0,
  'errors' => 0,
  'limbo_processed' => 0,
  'limbo_created' => 0,
  'limbo_updated' => 0,
  'limbo_skipped' => 0,
  'limbo_errors' => 0,
];

// Initialize global counters.
global $_falcon_successful_imports, $_falcon_successful_limbo_imports;
$_falcon_successful_imports = 0;
$_falcon_successful_limbo_imports = 0;

// Define field mappings.
$field_mapping = [
  'userid' => 'name',
  'user_email' => 'mail',
  'user_first_name' => 'field_first_name',
  'user_middle_name' => 'field_middle_name',
  'user_last_name' => 'field_last_name',
  'user_phone1' => 'field_phone1',
  'user_phone2' => 'field_phone2',
  'permit_class' => 'field_permit_class',
  'permit_status_cd' => 'field_permit_status_cd',
  'last_activity' => 'field_last_activity',
  'access_cd' => 'field_access_cd',
  'isMFA' => 'field_is_mfa',
  'rcf_cd' => 'field_rcf_cd',
  'version_no' => 'field_version_no',
  'isDisabled' => 'status',
  'failed_login_count' => 'field_failed_login_count',

  // New fields.
  'authorized_cd' => 'field_authorized_cd',
  'isLocked' => 'field_is_locked',
  'isActivated' => 'field_is_activated',
  'permit_no' => 'field_permit_no',
  'dt_permit_issued' => 'field_dt_permit_issued',
  'dt_permit_expires' => 'field_dt_permit_expires',
  'dt_mfa_login' => 'field_dt_mfa_login',
  'mfa_uuid' => 'field_mfa_uuid',
  'hid' => 'field_hid',
  'falcon_address' => 'field_falcon_address',
  'address_l1' => 'field_address_l1',
  'address_l2' => 'field_address_l2',
  'address_l3' => 'field_address_l3',
  'address_l4' => 'field_address_l4',
  'address_l5' => 'field_address_l5',
  'address_l6' => 'field_address_l6',
  'city' => 'field_city',
  'state_cd' => 'field_state_cd',
  'zip_cd' => 'field_zip_cd',
  'possess_eagle' => 'field_possess_eagle',
  // Map dt_create to Drupal core created field.
  'dt_create' => 'created',
  // Map dt_update to Drupal core changed field.
  'dt_update' => 'changed',
];

/**
 * Function to process a CSV file.
 */
function process_csv_file($file_path, &$stats, $limit, $field_mapping, $is_limbo = FALSE) {
  global $_falcon_successful_imports, $_falcon_successful_limbo_imports;

  // Check if file exists and is readable.
  if (!file_exists($file_path) || !is_readable($file_path)) {
    print("Error: Cannot read file $file_path\n");
    return FALSE;
  }

  // Open CSV file.
  $file = fopen($file_path, 'r');
  if (!$file) {
    print("Error: Unable to open file $file_path\n");
    return FALSE;
  }

  print("Processing file: $file_path\n");

  // Read headers.
  $headers = fgetcsv($file);
  if (!$headers) {
    print("Error: CSV file appears to be empty\n");
    fclose($file);
    return FALSE;
  }

  // Determine column indices based on headers.
  $column_indices = [];
  foreach ($field_mapping as $csv_header => $drupal_field) {
    $index = array_search($csv_header, $headers);
    if ($index !== FALSE) {
      $column_indices[$drupal_field] = $index;
    }
    else {
      print("Warning: Could not find column for $csv_header\n");
    }
  }

  // Process each row.
  while (($data = fgetcsv($file)) !== FALSE && ($is_limbo ? $_falcon_successful_limbo_imports : $_falcon_successful_imports) < $limit) {
    $stat_key = $is_limbo ? 'limbo_processed' : 'processed';
    $stats[$stat_key]++;

    // Extract data from CSV row using column indices.
    $username = isset($column_indices['name']) ? $data[$column_indices['name']] : '';
    $email = isset($column_indices['mail']) ? $data[$column_indices['mail']] : '';

    // Skip if required fields are missing.
    if (empty($username) || empty($email)) {
      print("Skipping row: Missing username or email\n");
      $stat_key = $is_limbo ? 'limbo_skipped' : 'skipped';
      $stats[$stat_key]++;
      continue;
    }

    // Validate email format.
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      print("Invalid email format: $email \n");
      $stat_key = $is_limbo ? 'limbo_errors' : 'errors';
      $stats[$stat_key]++;
      continue;
    }

    try {
      // Check if user exists by email.
      $existing_users = \Drupal::entityTypeManager()
        ->getStorage('user')
        ->loadByProperties(['mail' => $email]);

      if (!empty($existing_users)) {
        $user = reset($existing_users);
        print("Updating existing user: $email\n");
        $stat_key = $is_limbo ? 'limbo_updated' : 'updated';
        $stats[$stat_key]++;
      }
      else {
        $user = User::create();
        $stat_key = $is_limbo ? 'limbo_created' : 'created';
        $stats[$stat_key]++;
      }

      // Set basic user fields.
      $user->setUsername($username);
      $user->setEmail($email);

      // Set additional fields from CSV.
      foreach ($field_mapping as $csv_header => $drupal_field) {
        if (isset($column_indices[$drupal_field]) && isset($data[$column_indices[$drupal_field]])) {
          $value = $data[$column_indices[$drupal_field]];

          // Handle special field types and conversions.
          switch ($drupal_field) {
            case 'field_is_locked':
            case 'field_is_activated':
              // Convert to boolean.
              $value = ($value === 'Y' || $value === '1') ? 1 : 0;
              break;

            case 'field_dt_permit_issued':
            case 'field_dt_permit_expires':
            case 'field_dt_mfa_login':
              // Convert to datetime if not empty, using ISO 8601 format.
              if (!empty($value)) {
                // Remove milliseconds if present.
                $value = preg_replace('/\.\d+$/', '', $value);
                if (strtotime($value) !== FALSE) {
                  $datetime = date('Y-m-d\TH:i:s', strtotime($value));
                  $value = [
                    'value' => $datetime,
                  ];
                }
                else {
                  print("Warning: Could not parse date value: $value\n");
                  $value = NULL;
                }
              }
              else {
                $value = NULL;
              }
              break;

            case 'created':
            case 'changed':
              // Convert to Unix timestamp for Drupal core fields.
              $value = !empty($value) ? strtotime($value) : time();
              break;

            case 'field_is_mfa':
              // Convert to boolean or specific string.
              $value = ($value === 'Y') ? 'enabled' : 'disabled';
              break;

            case 'field_profile_completeness_score':
              $value = (int) $value;
              break;

            case 'field_two_factor_method':
              // Convert boolean 'isMFA' to appropriate value.
              $value = $value === '1' ? 'enabled' : 'disabled';
              break;

            case 'field_security_level':
              // Map access_cd to security level values.
              $value = !empty($value) ? $value : 'standard';
              break;

            case 'field_account_risk_level':
              // Map permit_status_cd to risk level values.
              $value = !empty($value) ? $value : 'normal';
              break;

            case 'status':
              // For limbo records, always set status to 0 (blocked)
              // For regular records, if isDisabled is 'N', set status to 1 (active), otherwise 0 (blocked)
              $value = $is_limbo ? 0 : (($value === 'N') ? 1 : 0);
              break;
          }

          $user->set($drupal_field, $value);
        }
      }

      // For limbo records, set the status to 0 (blocked)
      if ($is_limbo) {
        $user->set('status', 0);
      }

      // Save the user without checking the return value.
      $user->save();

      // If we get here, the save was successful (no exception thrown)
      if ($is_limbo) {
        $_falcon_successful_limbo_imports++;
        print("Successfully saved limbo user: " . $user->getAccountName() . " (" . $user->getEmail() . ") - Import #$_falcon_successful_limbo_imports/$limit\n");
      }
      else {
        $_falcon_successful_imports++;
        print("Successfully saved user: " . $user->getAccountName() . " (" . $user->getEmail() . ") - Import #$_falcon_successful_imports/$limit\n");
      }

    }
    catch (Exception $e) {
      print("Error processing user: " . $e->getMessage() . "\n");
      $stat_key = $is_limbo ? 'limbo_errors' : 'errors';
      $stats[$stat_key]++;
    }
  }

  // Close file.
  fclose($file);
  return TRUE;
}

// Print the user limit we're enforcing.
print("Importing with a limit of $limit users per type...\n\n");

// Process regular records.
print("Processing regular records...\n");
process_csv_file($filename, $stats, $limit, $field_mapping, FALSE);

// Process limbo records.
print("\nProcessing limbo records...\n");
process_csv_file($limbo_filename, $stats, $limit, $field_mapping, TRUE);

// Output import statistics.
print("\nImport completed.\n");
print("Regular records:\n");
print("  Processed rows: {$stats['processed']}\n");
print("  Users created: {$stats['created']}\n");
print("  Users updated: {$stats['updated']}\n");
print("  Rows skipped: {$stats['skipped']}\n");
print("  Errors: {$stats['errors']}\n");
print("  Total successful imports: $_falcon_successful_imports\n");

print("\nLimbo records:\n");
print("  Processed rows: {$stats['limbo_processed']}\n");
print("  Users created: {$stats['limbo_created']}\n");
print("  Users updated: {$stats['limbo_updated']}\n");
print("  Rows skipped: {$stats['limbo_skipped']}\n");
print("  Errors: {$stats['limbo_errors']}\n");
print("  Total successful imports: $_falcon_successful_limbo_imports\n");

if ($_falcon_successful_imports >= $limit) {
  print("Regular import LIMIT REACHED ($limit users).\n");
}
if ($_falcon_successful_limbo_imports >= $limit) {
  print("Limbo import LIMIT REACHED ($limit users).\n");
}

// Print out available fields for debugging.
$available_fields = \Drupal::service('entity_field.manager')->getFieldDefinitions('user', 'user');

print("\nAvailable user fields:\n");
foreach ($available_fields as $field_name => $field_definition) {
  print("- $field_name\n");
}
