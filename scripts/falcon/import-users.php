<?php

/**
 * @file
 * Drush script to import users from CSV file.
 *
 * Usage: drush scr scripts/falcon/import-users.php [number]
 * The optional [number] parameter limits how many users to successfully import.
 */

use Drupal\user\Entity\User;
use Drupal\Component\Utility\Random;
use Drush\Drush;

// Get the limit from command line argument if provided.
$input = Drush::input();
$args = $input->getArguments();
$limit = isset($args['extra'][1]) ? (int) $args['extra'][1] : PHP_INT_MAX;

$successful_imports = 0;

// Define the CSV file path for DDEV environment.
$filename = DRUPAL_ROOT . '/sites/falcon/files/falcon-data/falc_sys_userprofile_202502271311.csv';

// Check if file exists and is readable.
if (!file_exists($filename) || !is_readable($filename)) {
  print("Error: Cannot read file $filename\n");
  exit(1);
}

// Initialize counters.
$stats = [
  'processed' => 0,
  'created' => 0,
  'updated' => 0,
  'skipped' => 0,
  'errors' => 0,
];

// Open CSV file.
$file = fopen($filename, 'r');
if (!$file) {
  print("Error: Unable to open file $filename\n");
  exit(1);
}

// Read headers.
$headers = fgetcsv($file);
if (!$headers) {
  print("Error: CSV file appears to be empty\n");
  fclose($file);
  exit(1);
}

// Define field mappings.
$field_mapping = [
  'userid' => 'name',
  'user_email' => 'mail',
  'user_first_name' => 'field_first_name',
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

  // New fields.
  'authorized_cd' => 'field_authorized_code',
  'isLocked' => 'field_is_locked',
  'isActivated' => 'field_is_activated',
  'failedlogin_count' => 'field_failed_login_count',
  'permit_no' => 'field_permit_number',
  'dt_permit_issued' => 'field_permit_issued_date',
  'dt_permit_expires' => 'field_permit_expiration_date',
  'dt_create' => 'field_created_timestamp',
  'dt_update' => 'field_updated_timestamp',
  'created_by' => 'field_created_by',
  'updated_by' => 'field_updated_by',
  'dt_mfa_login' => 'field_mfa_login_timestamp',
  'mfa_uuid' => 'field_mfa_uuid',
  'hid' => 'field_hid',
];

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

// Initialize Random utility for password generation.
$random = new Random();

// Print the user limit we're enforcing.
print("Importing with a limit of $limit users...\n\n");

// Process each row.
while (($data = fgetcsv($file)) !== FALSE && $successful_imports < $limit) {
  $stats['processed']++;

  // Extract data from CSV row using column indices.
  $username = isset($column_indices['name']) ? $data[$column_indices['name']] : '';
  $email = isset($column_indices['mail']) ? $data[$column_indices['mail']] : '';

  // Skip if required fields are missing.
  if (empty($username) || empty($email)) {
    print("Skipping row: Missing username or email\n");
    $stats['skipped']++;
    continue;
  }

  // Validate email format.
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    print("Invalid email format: $email \n");
    $stats['errors']++;
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
      $stats['updated']++;
    }
    else {
      $user = User::create();
      $stats['created']++;
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

          case 'field_failed_login_count':
            // Ensure integer.
            $value = (int) $value;
            break;

          case 'field_permit_issued_date':
          case 'field_permit_expiration_date':
          case 'field_created_timestamp':
          case 'field_updated_timestamp':
          case 'field_mfa_login_timestamp':
            // Convert to datetime if not empty.
            $value = !empty($value) ? date('Y-m-d\TH:i:s', strtotime($value)) : NULL;
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
            // If isDisabled is 'N', set status to 1 (active), otherwise 0 (blocked)
            $value = ($value === 'N') ? 1 : 0;
            break;
        }

        $user->set($drupal_field, $value);
      }
    }

    // Save the user without checking the return value.
    $user->save();

    // If we get here, the save was successful (no exception thrown)
    $successful_imports++;
    print("Successfully saved user: " . $user->getAccountName() . " (" . $user->getEmail() . ") - Import #$successful_imports/$limit\n");

    // No need for an additional check here as the while loop condition will stop the loop.
  }
  catch (Exception $e) {
    print("Error processing user: " . $e->getMessage() . "\n");
    $stats['errors']++;
  }
}

// Close file.
fclose($file);

// Output import statistics.
print("\nImport completed.\n");
print("Processed rows: {$stats['processed']}\n");
print("Users created: {$stats['created']}\n");
print("Users updated: {$stats['updated']}\n");
print("Rows skipped: {$stats['skipped']}\n");
print("Errors: {$stats['errors']}\n");
print("Total successful imports: $successful_imports\n");

if ($successful_imports >= $limit) {
  print("Import LIMIT REACHED ($limit users). Additional users were not imported.\n");
}

// Print out available fields for debugging.
$available_fields = \Drupal::service('entity_field.manager')->getFieldDefinitions('user', 'user');

print("\nAvailable user fields:\n");
foreach ($available_fields as $field_name => $field_definition) {
  print("- $field_name\n");
}
