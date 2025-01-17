<?php

/**
 * @file
 * Drush script to import users from CSV.
 *
 * Usage: drush scr import_users.php path/to/csv/file.csv.
 */

use Drupal\user\Entity\User;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Component\Utility\Random;

$filename = '../scripts/eps/data/user_export_2025-01-17_12-14-32.csv';

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

// Process each row.
while (($row = fgetcsv($file)) !== FALSE) {
  $stats['processed']++;

  // Create associative array from row data.
  $data = array_combine($headers, $row);

  try {
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
      ->set('langcode', LanguageInterface::LANGCODE_DEFAULT)
      ->set('preferred_langcode', LanguageInterface::LANGCODE_DEFAULT)
      ->set('preferred_admin_langcode', LanguageInterface::LANGCODE_DEFAULT)
      ->set('status', 1);

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

    // Save the user.
    $user->save();

    // Send email with login credentials if it's a new user.
    if (!empty($existing_users)) {
      _user_mail_notify('register_no_approval_required', $user);
    }

  }
  catch (\Exception $e) {
    print("Error processing user {$data['Email']}: " . $e->getMessage() . "\n");
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
print("Errors encountered: " . $stats['errors'] . "\n");

// Provide instructions for users.
if ($stats['created'] > 0) {
  print("\nNote: New users will receive an email with login instructions.\n");
}

// Clear caches if needed.
if ($stats['created'] > 0 || $stats['updated'] > 0) {
  print("\nClearing user caches...\n");
  drupal_flush_all_caches();
}
