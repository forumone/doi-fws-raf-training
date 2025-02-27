<?php

/**
 * @file
 * Drush script to import users from CSV.
 *
 * Usage: drush scr import_users.php path/to/csv/file.csv.
 */

use Drupal\user\Entity\User;
use Drupal\Component\Utility\Random;

$filename = '../scripts/eps/data/user_export.csv';

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

// Initialize Random utility for password generation.
$random = new Random();

// Get field definitions for user entity type.
$field_definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('user', 'user');

// Process each row.
while (($row = fgetcsv($file)) !== FALSE) {
  $stats['processed']++;

  // Create associative array from row data.
  $data = array_combine($headers, $row);

  try {
    // Skip if this is the admin user (uid 1)
    if ($data['User ID'] == 1) {
      print("Skipping admin user: " . $data['Email'] . "\n");
      $stats['skipped']++;
      continue;
    }

    // Validate required fields.
    if (empty($data['Email']) || empty($data['Username'])) {
      throw new \Exception('Email and Username are required fields');
    }

    // Validate email format.
    if (!filter_var($data['Email'], FILTER_VALIDATE_EMAIL)) {
      throw new \Exception('Invalid email format');
    }

    // Check if user already exists by email.
    $existing_users = \Drupal::entityTypeManager()
      ->getStorage('user')
      ->loadByProperties(['mail' => $data['Email']]);

    if (!empty($existing_users)) {
      // Update existing user.
      $user = reset($existing_users);
      print("Updating user: " . $data['Email'] . "\n");
      $stats['updated']++;
    }
    else {
      // Create new user.
      $user = User::create();
      print("Creating new user: " . $data['Email'] . "\n");
      $stats['created']++;
    }

    // Generate a random password using Drupal's Random utility.
    $password = $random->string(12, TRUE);

    // Set basic user properties.
    $user->setUsername($data['Username'])
      ->setEmail($data['Email'])
      ->setPassword($password)
      ->set('status', 1);

    // Set custom fields.
    if (!empty($data['First Name']) && isset($field_definitions['field_first_name'])) {
      $user->set('field_first_name', [['value' => $data['First Name']]]);
    }

    if (!empty($data['Last Name']) && isset($field_definitions['field_last_name'])) {
      $user->set('field_last_name', [['value' => $data['Last Name']]]);
    }

    if (!empty($data['Phone Number']) && isset($field_definitions['field_phone'])) {
      // Remove any non-numeric characters from phone.
      $phone = preg_replace('/[^0-9]/', '', $data['Phone Number']);
      $user->set('field_phone', [['value' => $phone]]);
    }

    if (!empty($data['Start Date']) && isset($field_definitions['field_start_date'])) {
      $start_timestamp = strtotime($data['Start Date']);
      if ($start_timestamp) {
        // Format as date only since field_start_date is configured as date-only field.
        $user->set('field_start_date', [['value' => date('Y-m-d', $start_timestamp)]]);
      }
    }

    // Handle roles.
    if (!empty($data['Roles'])) {
      $roles = array_map('trim', explode(',', $data['Roles']));
      foreach ($roles as $role) {
        if (!empty($role)) {
          $user->addRole($role);
        }
      }
    }

    // Set created time if provided.
    if (!empty($data['Created Date'])) {
      $created_timestamp = strtotime($data['Created Date']);
      if ($created_timestamp) {
        $user->set('created', $created_timestamp);
      }
    }

    // Set last access time if provided.
    if (!empty($data['Last Access'])) {
      $access_timestamp = strtotime($data['Last Access']);
      if ($access_timestamp) {
        $user->set('access', $access_timestamp);
      }
    }

    // Set last login time if provided.
    if (!empty($data['Last Login'])) {
      $login_timestamp = strtotime($data['Last Login']);
      if ($login_timestamp) {
        $user->set('login', $login_timestamp);
      }
    }

    // Save the user.
    $user->save();

  }
  catch (\Exception $e) {
    print("Error processing user {$data['Email']}: " . $e->getMessage() . "\n");
    print("Row data: " . print_r($data, TRUE) . "\n");
    $stats['errors']++;
    continue;
  }
}

// Close file.
fclose($file);

// Print summary.
print("\nImport Summary:\n");
print("-------------\n");
print("Total rows processed: " . $stats['processed'] . "\n");
print("Users created: " . $stats['created'] . "\n");
print("Users updated: " . $stats['updated'] . "\n");
print("Users skipped: " . $stats['skipped'] . "\n");
print("Errors encountered: " . $stats['errors'] . "\n");

// Clear caches if needed.
if ($stats['created'] > 0 || $stats['updated'] > 0) {
  print("\nClearing user caches...\n");
  drupal_flush_all_caches();
}
