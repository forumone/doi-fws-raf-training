<?php

/**
 * @file
 * Drush script to import user data from CSV into Drupal users.
 *
 * Usage: drush scr scripts/import_users.php
 */

use Drupal\user\Entity\User;
use Drupal\taxonomy\Entity\Term;
use Drupal\Core\Entity\EntityStorageException;

// Initialize counters
$row_count = 0;
$success_count = 0;
$error_count = 0;

try {
  // Read CSV data
  $file_path = '../scripts/data/T_User.csv';
  if (!file_exists($file_path)) {
    throw new Exception("Could not find CSV file: $file_path");
  }

  $file_handle = fopen($file_path, 'r');
  if (!$file_handle) {
    throw new Exception("Could not open CSV file: $file_path");
  }

  // Skip header row
  fgetcsv($file_handle);

  // Process each row
  while (($data = fgetcsv($file_handle)) !== FALSE) {
    $row_count++;

    try {
      // Map CSV columns to variables
      list($username, $firstname, $lastname, $password, $phone, $org, $email, $group, $vet, $active, $createBy, $createDate, $updateBy, $updateDate) = $data;

      // Skip if username is empty
      if (empty($username)) {
        print("\nSkipping row $row_count - empty username");
        continue;
      }

      // Check if user already exists
      $existing_users = \Drupal::entityTypeManager()
        ->getStorage('user')
        ->loadByProperties(['name' => $username]);

      if (!empty($existing_users)) {
        print("\nUser already exists: $username - skipping");
        continue;
      }

      // Create new user
      $user = User::create();

      // Set required fields
      $user->setUsername($username);
      $user->setPassword($password);
      $user->setEmail($email ?: "$username@example.com"); // Set dummy email if empty
      $user->set('status', $active);

      // Set custom fields
      $user->set('field_first_name', $firstname);
      $user->set('field_last_name', $lastname);
      $user->set('field_phone', $phone);

      // Set organization reference
      if (!empty($org)) {
        $org_term = ensure_taxonomy_term('org', $org);
        if ($org_term) {
          $user->set('field_org', ['target_id' => $org_term->id()]);
        }
      }

      // Set security group reference
      if (!empty($group)) {
        $group_term = ensure_taxonomy_term('security_groups', $group);
        if ($group_term) {
          $user->set('field_security_group', ['target_id' => $group_term->id()]);
        }
      }

      // Set veterinarian boolean
      $user->set('field_vet', (bool)$vet);

      // Set timestamps
      if (!empty($createDate)) {
        $user->set('created', strtotime($createDate));
      }
      if (!empty($updateDate)) {
        $user->set('changed', strtotime($updateDate));
      }

      // Save user
      $user->save();
      $success_count++;
      print("\nCreated user: $username");
    } catch (EntityStorageException $e) {
      print("\nError processing row $row_count: " . $e->getMessage());
      $error_count++;
    } catch (Exception $e) {
      print("\nError processing row $row_count: " . $e->getMessage());
      $error_count++;
    }
  }

  fclose($file_handle);
} catch (Exception $e) {
  print("\nFatal error: " . $e->getMessage() . "\n");
  exit(1);
}

// Print summary
print("\n\nImport completed:");
print("\nTotal rows processed: $row_count");
print("\nSuccessfully imported: $success_count");
print("\nErrors: $error_count\n");

/**
 * Helper function to ensure taxonomy term exists and return it.
 *
 * @param string $vocabulary
 *   Machine name of vocabulary.
 * @param string $name
 *   Term name.
 *
 * @return \Drupal\taxonomy\TermInterface|null
 *   The taxonomy term entity or null if creation fails.
 */
function ensure_taxonomy_term($vocabulary, $name)
{
  try {
    // Try to load existing term
    $terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadByProperties([
        'vid' => $vocabulary,
        'name' => $name,
      ]);

    if (!empty($terms)) {
      return reset($terms);
    }

    // Create new term if it doesn't exist
    $term = Term::create([
      'vid' => $vocabulary,
      'name' => $name,
    ]);
    $term->save();
    return $term;
  } catch (Exception $e) {
    print("\nError ensuring taxonomy term ($vocabulary: $name): " . $e->getMessage());
    return null;
  }
}
