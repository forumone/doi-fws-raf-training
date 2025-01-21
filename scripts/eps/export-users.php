<?php

/**
 * @file
 * Drush script to export users to CSV.
 *
 * Usage: drush scr export_users.php.
 */

use Drupal\user\Entity\User;

// Get all user IDs except the anonymous user (uid 0).
$query = \Drupal::entityQuery('user')
  ->condition('uid', 0, '>')
  // Only active users.
  ->condition('status', 1)
  ->accessCheck(FALSE);
$uids = $query->execute();

// Define CSV headers.
$headers = [
  'User ID',
  'Username',
  'Email',
  'First Name',
  'Last Name',
  'Phone Number',
  'Start Date',
  'Status',
  'Roles',
  'Created Date',
  'Last Access',
  'Last Login',
];

// Create CSV file.
$filename = '../scripts/user_export_' . date('Y-m-d_H-i-s') . '.csv';
$file = fopen($filename, 'w');

// Write headers.
fputcsv($file, $headers);

// Load and write user data.
foreach ($uids as $uid) {
  $user = User::load($uid);

  if ($user) {
    // Get user roles, excluding 'authenticated'.
    $roles = $user->getRoles();
    $roles = array_diff($roles, ['authenticated']);
    $roles_string = implode(', ', $roles);

    // Format dates.
    $created = $user->getCreatedTime() ? date('Y-m-d H:i:s', $user->getCreatedTime()) : '';
    $access = $user->getLastAccessedTime() ? date('Y-m-d H:i:s', $user->getLastAccessedTime()) : '';
    $login = $user->getLastLoginTime() ? date('Y-m-d H:i:s', $user->getLastLoginTime()) : '';

    // Get additional fields.
    $first_name = $user->get('field_first_name')->value ?? '';
    $last_name = $user->get('field_last_name')->value ?? '';
    $phone = $user->get('field_phone')->value ?? '';
    $start_date = $user->get('field_start_date')->value ?? '';
    if ($start_date) {
      $start_date = date('Y-m-d H:i:s', strtotime($start_date));
    }

    // Prepare row data.
    $row = [
      $user->id(),
      $user->getAccountName(),
      $user->getEmail(),
      $first_name,
      $last_name,
      $phone,
      $start_date,
      $user->isActive() ? 'Active' : 'Blocked',
      $roles_string,
      $created,
      $access,
      $login,
    ];

    // Write row to CSV.
    fputcsv($file, $row);
  }
}

// Close file.
fclose($file);

// Output success message.
print("Users exported successfully to $filename\n");

// Display total number of users exported.
print('Total users exported: ' . count($uids));
