<?php

/**
 * @file
 * Drush script to import users from rcgr_userprofile CSV file.
 *
 * Usage: ddev drush --uri=https://rcgr.ddev.site/ scr scripts/rcgr/import-users.php [limit] [update]
 * The optional [limit] parameter limits how many users to successfully import.
 * The optional [update] parameter (1 or 0) determines if existing users should be updated.
 */

use Drupal\user\Entity\User;
use Drush\Drush;

// Get the limit and update parameters from command line arguments if provided.
$input = Drush::input();
$args = $input->getArguments();
$limit = isset($args['extra'][1]) ? (int) $args['extra'][1] : PHP_INT_MAX;
$update_existing = isset($args['extra'][2]) ? (bool) $args['extra'][2] : FALSE;

// Define variable to track if we're tracking imported, updated, or both.
// 'combined', 'imported_only', 'updated_only'.
$limit_type = 'combined';

// Get input file path.
$timestamp = date('YmdHi');
$project_root = dirname(getcwd());
$input_file = $project_root . '/scripts/rcgr/data/rcgr_userprofile_no_passwords_202503211115.csv';
// No log file will be created, only console output.
$log_file = NULL;

/**
 * Set up logging.
 */
function log_message($message, $log_file) {
  $timestamp = date('Y-m-d H:i:s');
  $log_message = "[{$timestamp}] {$message}\n";
  // Only output to console, no log file.
  echo $log_message;
}

// Initialize log output.
echo "=== RCGR User Import Log ===\n";
log_message("Starting user import from: {$input_file}", $log_file);
log_message("Import limit: " . ($limit === PHP_INT_MAX ? "none" : $limit), $log_file);
log_message("Update existing users: " . ($update_existing ? "Yes" : "No"), $log_file);

// Define field mappings from CSV to user fields.
$field_mappings = [
// Will be used for username.
  'userid' => NULL,
  'applicant_business_name' => 'field_applicant_business_name',
  'applicant_last_name' => 'field_applicant_last_name',
  'applicant_first_name' => 'field_applicant_first_name',
  'applicant_middle_name' => 'field_applicant_middle_name',
  'applicant_prefix' => 'field_applicant_prefix',
  'applicant_suffix' => 'field_applicant_suffix',
  'applicant_address_l1' => 'field_applicant_address_l1',
  'applicant_address_l2' => 'field_applicant_address_l2',
  'applicant_address_l3' => 'field_applicant_address_l3',
  'applicant_county' => 'field_applicant_county',
  'applicant_city' => 'field_applicant_city',
  'applicant_state' => 'field_applicant_state',
  'applicant_zip' => 'field_applicant_zip',
  'applicant_home_phone' => 'field_applicant_home_phone',
  'applicant_work_phone' => 'field_applicant_work_phone',
  'applicant_fax_number' => 'field_fax_number',
// Will be used as email.
  'applicant_email_address' => NULL,
  'principal_name' => 'field_principal_name',
  'principal_last_name' => 'field_principal_last_name',
  'principal_first_name' => 'field_principal_first_name',
  'principal_middle_name' => 'field_principal_middle_name',
  'principal_suffix' => 'field_principal_suffix',
  'principal_title' => 'field_principal_title',
  'principal_email_address' => 'field_principal_email',
  'principal_telephone' => 'field_principal_telephone',
  'primary_contact_name' => 'field_primary_contact_name',
  'primary_contact_telephone' => 'field_primary_contact_phone',
  'primary_contact_email_address' => 'field_primary_contact_email',
  'version_no' => 'field_version_no',
  'hid' => 'field_hid',
  'program_id' => 'field_program_id',
  'registrant_type_cd' => 'field_registrant_type_cd',
  'bi_cd' => 'field_bi_cd',
  'rcf_cd' => 'field_rcf_cd',
  'create_by' => 'field_created_by',
  'update_by' => 'field_updated_by',
  // Field not in CSV but setting default value during import.
  'applicant_agree_to_certify' => 'field_applicant_agree_to_certify',
];

// Open input file.
$handle = fopen($input_file, 'r');
if (!$handle) {
  log_message("Error: Could not open input file {$input_file}", $log_file);
  exit(1);
}

// Get header row.
$header = fgetcsv($handle);
if (!$header) {
  log_message("Error: Could not read header row from CSV", $log_file);
  fclose($handle);
  exit(1);
}

// Check if required columns exist.
$required_columns = [
  'userid',
  'applicant_email_address',
  'applicant_first_name',
  'applicant_last_name',
];
foreach ($required_columns as $column) {
  if (!in_array($column, $header)) {
    log_message("Error: Required column '{$column}' not found in CSV header", $log_file);
    fclose($handle);
    exit(1);
  }
}

// Initialize counters.
$row_count = 0;
$success_count = 0;
$updated_count = 0;
$error_count = 0;
$skipped_count = 0;

/**
 * Check if we're still under our limit.
 */
function check_limit_reached($success_count, $updated_count, $limit, $limit_type) {
  if ($limit === PHP_INT_MAX) {
    return FALSE;
  }

  switch ($limit_type) {
    case 'imported_only':
      return $success_count >= $limit;

    case 'updated_only':
      return $updated_count >= $limit;

    case 'combined':
    default:
      return ($success_count + $updated_count) >= $limit;
  }
}

// Process data rows.
while (($data = fgetcsv($handle)) !== FALSE) {
  $row_count++;

  // Check if we've hit our limit of successful imports + updates before processing more.
  if (check_limit_reached($success_count, $updated_count, $limit, $limit_type)) {
    log_message("Import limit of {$limit} reached. Stopping.", $log_file);
    break;
  }

  // Create associative array of row data.
  $row = array_combine($header, $data);

  // Check if we have minimum required data.
  if (empty($row['userid'])) {
    log_message("Warning: Row {$row_count} missing userid - skipping", $log_file);
    $skipped_count++;
    continue;
  }

  if (empty($row['applicant_email_address'])) {
    // Don't log individual missing emails, just increment skip count.
    $skipped_count++;
    continue;
  }

  // Clean and prepare user data.
  $username = trim($row['userid']);
  $email = trim($row['applicant_email_address']);

  // Sanitize and validate the email address.
  $email = sanitize_email($email);
  if (empty($email)) {
    // Don't log individual invalid emails, just increment skip count.
    $skipped_count++;
    continue;
  }

  // Sanitize username to make it valid for Drupal.
  $username = sanitize_username($username);

  // Check if user already exists.
  $existing_user = user_load_by_name($username);
  $existing_email = user_load_by_mail($email);

  if ($existing_user) {
    if ($update_existing) {
      $user = $existing_user;
      log_message("Updating existing user '{$username}'", $log_file);
    }
    else {
      log_message("User '{$username}' already exists - skipping", $log_file);
      $skipped_count++;
      continue;
    }
  }
  elseif ($existing_email) {
    if ($update_existing) {
      $user = $existing_email;
      log_message("Updating existing user with email '{$email}'", $log_file);
    }
    else {
      log_message("Email '{$email}' already in use - skipping user '{$username}'", $log_file);
      $skipped_count++;
      continue;
    }
  }
  else {
    // Create new user object.
    $user = User::create();
    $user->enforceIsNew();
  }

  try {
    // For new users, set required base fields.
    if (!$existing_user && !$existing_email) {
      $user->setUsername($username);
      $user->setEmail($email);
      // Random password - users will need to reset.
      $user->setPassword(\Drupal::service('password_generator')->generate(12));
      $user->set('init', $email);
      $user->set('langcode', 'en');
      // Set status to active.
      $user->activate();
    }

    // Only update email for existing users if it doesn't conflict with another user.
    if ($existing_user && !$existing_email && $existing_user->getEmail() != $email) {
      // Check if the new email exists for another user.
      $email_check = user_load_by_mail($email);
      if (!$email_check) {
        $user->setEmail($email);
      }
      else {
        log_message("Warning: Cannot update email for user '{$username}' - email '{$email}' is already in use", $log_file);
      }
    }

    // Set the account name - prefer business name if available.
    $account_name = '';
    if (!empty($row['applicant_business_name'])) {
      $account_name = $row['applicant_business_name'];
    }
    else {
      // Fall back to applicant name.
      $first_name = !empty($row['applicant_first_name']) ? $row['applicant_first_name'] : '';
      $last_name = !empty($row['applicant_last_name']) ? $row['applicant_last_name'] : '';
      $account_name = trim($first_name . ' ' . $last_name);
    }

    // Ensure account name is not empty.
    if (empty($account_name)) {
      $account_name = $username;
    }

    // Check if account name is too long and truncate if necessary.
    if (strlen($account_name) > 60) {
      $original_name = $account_name;
      $account_name = substr($account_name, 0, 57) . '...';
    }

    $user->set('name', $username);
    // Set the display name instead of account_name.
    $user->set('field_applicant_business_name', $account_name);

    // Set created/changed dates if available.
    if (!empty($row['dt_create'])) {
      try {
        $created_time = strtotime($row['dt_create']);
        if ($created_time) {
          $user->set('created', $created_time);
        }
      }
      catch (Exception $e) {
        log_message("Warning: Invalid creation date format for user '{$username}'", $log_file);
      }
    }

    if (!empty($row['dt_update'])) {
      try {
        $changed_time = strtotime($row['dt_update']);
        if ($changed_time) {
          $user->set('changed', $changed_time);
        }
      }
      catch (Exception $e) {
        log_message("Warning: Invalid update date format for user '{$username}'", $log_file);
      }
    }

    // Set user fields.
    foreach ($field_mappings as $csv_column => $drupal_field) {
      // Skip null mappings.
      if ($drupal_field == NULL) {
        continue;
      }

      // Only set the field if there's a value in the CSV column.
      if (!empty($row[$csv_column])) {
        try {
          $user->set($drupal_field, $row[$csv_column]);
        }
        catch (\Exception $e) {
          log_message("Error setting field $drupal_field to '{$row[$csv_column]}': " . $e->getMessage(), $log_file);
        }
      }
    }

    // Set default value for certification field which is not in the CSV.
    $user->set('field_applicant_agree_to_certify', FALSE);

    // Save the user.
    $user->save();

    if ($existing_user || $existing_email) {
      $updated_count++;
      log_message("Successfully updated user '{$username}' (UID: {$user->id()})", $log_file);
    }
    else {
      $success_count++;
      log_message("Successfully imported user '{$username}' (UID: {$user->id()})", $log_file);
    }
  }
  catch (Exception $e) {
    log_message("Error importing user '{$username}': " . $e->getMessage(), $log_file);
    $error_count++;
  }

  // Provide progress update every 100 records.
  if ($row_count % 100 === 0) {
    log_message("Progress: Processed {$row_count} users so far", $log_file);
  }
}

// Close the input file.
fclose($handle);

// Log final statistics.
log_message("Import completed. Total rows processed: {$row_count}", $log_file);
log_message("Successfully imported: {$success_count}", $log_file);
log_message("Successfully updated: {$updated_count}", $log_file);
log_message("Errors: {$error_count}", $log_file);
log_message("Skipped: {$skipped_count}", $log_file);

if (check_limit_reached($success_count, $updated_count, $limit, $limit_type)) {
  log_message("Import LIMIT REACHED ($limit users)", $log_file);
}

/**
 * Sanitizes a username to make it valid for Drupal.
 *
 * Drupal usernames must be:
 * - Between 1 and 60 characters.
 * - Can contain only letters, numbers, spaces, _, ., and @.
 * - Cannot contain consecutive spaces.
 * - Must not start or end with a space.
 *
 * @param string $username
 *   The raw username to sanitize.
 *
 * @return string
 *   The sanitized username.
 */
function sanitize_username($username) {
  // Remove leading spaces, periods, and other problematic characters.
  $username = ltrim($username, ' .-_@');

  // Remove invalid characters.
  $username = preg_replace('/[^\w\s@.-]/', '', $username);

  // Replace multiple spaces with a single space.
  $username = preg_replace('/\s+/', ' ', $username);

  // Trim spaces from beginning and end.
  $username = trim($username);

  // Ensure the username starts with a letter or number.
  if (!empty($username) && !preg_match('/^[a-zA-Z0-9]/', $username)) {
    $username = 'u_' . $username;
  }

  // Ensure the username isn't empty.
  if (empty($username)) {
    $username = 'user_' . substr(md5(rand()), 0, 8);
  }

  // Ensure the username isn't too long.
  if (strlen($username) > 60) {
    $username = substr($username, 0, 57) . '...';
  }

  return $username;
}

/**
 * Sanitizes and validates an email address.
 *
 * @param string $email
 *   The raw email address to sanitize and validate.
 *
 * @return string
 *   The sanitized email address, or empty string if invalid.
 */
function sanitize_email($email) {
  // Trim whitespace.
  $email = trim($email);

  // Lowercase the email.
  $email = strtolower($email);

  // Skip numerical values that are clearly not emails.
  if (is_numeric($email)) {
    return '';
  }

  // Remove any spaces.
  $email = str_replace(' ', '', $email);

  // Fix common typos in email domains.
  $common_fixes = [
    'gmail.com' => [
      'gmail.co',
      'gamil.com',
      'gmail.comm',
      'gmail.cmo',
      'gmai.com',
    ],
    'yahoo.com' => [
      'yahoo.co',
      'yaho.com',
      'yahoo.comm',
      'yahoo.cmo',
    ],
    'hotmail.com' => [
      'hotmail.co',
      'hotmal.com',
      'hotmail.comm',
      'hotmail.cmo',
    ],
    'outlook.com' => [
      'outlook.co',
      'outlook.comm',
      'outlook.cmo',
    ],
    'aol.com' => [
      'aol.co',
      'aol.comm',
      'aol.cmo',
    ],
  ];

  foreach ($common_fixes as $correct => $mistakes) {
    foreach ($mistakes as $mistake) {
      if (str_ends_with($email, $mistake)) {
        $email = substr($email, 0, strlen($email) - strlen($mistake)) . $correct;
        break 2;
      }
    }
  }

  // Basic format check using PHP's filter_var.
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    // Check for missing @ symbol or if it appears multiple times.
    $at_count = substr_count($email, '@');
    if ($at_count === 0) {
      // Invalid, no @ symbol.
      return '';
    }
    elseif ($at_count > 1) {
      // Keep only the first part and last part after @ to form valid email.
      $parts = explode('@', $email);
      $email = $parts[0] . '@' . end($parts);
    }

    // Check again after fixing.
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      // Still invalid.
      return '';
    }
  }

  // Additional checks for realistic domain TLDs.
  $parts = explode('@', $email);
  if (count($parts) !== 2) {
    // Malformed email.
    return '';
  }

  $domain = $parts[1];
  $domain_parts = explode('.', $domain);
  $tld = end($domain_parts);

  // Check if TLD is extremely short or long (unrealistic).
  if (strlen($tld) < 2 || strlen($tld) > 6) {
    // Invalid TLD.
    return '';
  }

  // Ensure domain name makes sense.
  if (count($domain_parts) < 2) {
    // Needs at least a domain and TLD.
    return '';
  }

  return $email;
}
