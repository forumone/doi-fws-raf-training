#!/usr/bin/env php
<?php

/**
 * @file
 * Import users from CSV file.
 */

use Drupal\user\Entity\User;

/**
 * Validates an email address.
 *
 * @param string $email
 *   The email address to validate.
 *
 * @return bool
 *   TRUE if the email is valid, FALSE otherwise.
 */
function is_valid_email($email) {
  // First check if it's a valid email format.
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    return FALSE;
  }

  // Additional checks for suspicious patterns.
  $suspicious_patterns = [
  // All numbers.
    '/^[0-9]+$/',
  // Test emails.
    '/^test@/',
  // Example domain.
    '/example\.com$/',
  // Local domains.
    '/\.local$/',
  // Known testing service.
    '/bugcrowd/',
  // Security scanner.
    '/netsparker/',
  // Common attack pattern.
    '/trace\.axd/',
  // Common attack pattern.
    '/elmah\.axd/',
  // Common attack pattern.
    '/server-status/',
  // Contains backslashes.
    '/\\\\/',
  // Contains angle brackets (common in XSS attempts)
    '/[<>]/',
  // Contains ${} (common in injection attempts)
    '/\$\{.*\}/',
  // Contains print() (common in injection attempts)
    '/print.*\(/',
  // SQL keywords.
    '/SELECT|INSERT|UPDATE|DELETE|UNION|DROP/i',
  ];

  foreach ($suspicious_patterns as $pattern) {
    if (preg_match($pattern, $email)) {
      return FALSE;
    }
  }

  // Check domain has valid MX or A record.
  $domain = substr(strrchr($email, "@"), 1);
  return checkdnsrr($domain, "MX") || checkdnsrr($domain, "A");
}

// Read CSV file.
$csv_file = dirname(__FILE__) . '/data/_USER_.csv';
if (!file_exists($csv_file)) {
  die("CSV file not found at: $csv_file\n");
}

$handle = fopen($csv_file, 'r');
if (!$handle) {
  die("Could not open CSV file\n");
}

$count = 0;
$updates = 0;
$errors = [];

// Skip header row if it exists
// fgetcsv($handle);
while (($data = fgetcsv($handle)) !== FALSE) {
  if (count($data) < 3) {
    $errors[] = "Invalid row format: " . implode(',', $data);
    continue;
  }

  $id = $data[0];
  $name = trim($data[1]);
  $email = trim($data[2]);

  // Skip if email or name is empty.
  if (empty($email) || empty($name)) {
    $errors[] = "Skipping row with empty name or email: $email";
    continue;
  }

  // Validate email address.
  if (!is_valid_email($email)) {
    $errors[] = "Skipping invalid email address: $email";
    continue;
  }

  try {
    // Check if user already exists.
    $existing_users = \Drupal::entityTypeManager()
      ->getStorage('user')
      ->loadByProperties(['mail' => $email]);

    if (!empty($existing_users)) {
      // Update existing user.
      $user = reset($existing_users);
      // Use email as username to avoid conflicts.
      $user->set('name', $email);
      $user->save();
      $updates++;
      echo "Updated user: $name ($email)\n";
    }
    else {
      // Create new user.
      $user = User::create();
      // Use email as username to avoid conflicts.
      $user->setUsername($email);
      $user->setEmail($email);
      $user->set('init', $email);
      $user->enforceIsNew();
      $user->activate();
      $user->save();
      $count++;
      echo "Created user: $name ($email)\n";
    }
  }
  catch (\Exception $e) {
    $errors[] = "Error processing user $email: " . $e->getMessage();
  }
}

fclose($handle);

echo "\nImport completed.\n";
echo "Successfully created $count users and updated $updates users.\n";

if (!empty($errors)) {
  echo "\nErrors encountered:\n";
  foreach ($errors as $error) {
    echo "- $error\n";
  }
}
