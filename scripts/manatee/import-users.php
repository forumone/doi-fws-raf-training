<?php

/**
 * @file
 * Drush script to import user data from CSV into Drupal users with role mapping.
 *
 * Usage: drush scr scripts/import_users.php
 */

use Drupal\Core\Entity\EntityStorageException;
use Drupal\user\Entity\User;
use Drupal\user\Entity\Role;

// Initialize counters.
$row_count = 0;
$success_count = 0;
$error_count = 0;

// Define security group to role mapping.
$security_group_roles = [
  'V' => 'viewer',
  'E' => 'contributor',
  'O' => 'other_researchers',
  'A' => 'partner_administrator',
];

try {
  // Read CSV data.
  $file_path = '../scripts/manatee/data/T_User.csv';
  if (!file_exists($file_path)) {
    throw new Exception("Could not find CSV file: $file_path");
  }

  $file_handle = fopen($file_path, 'r');
  if (!$file_handle) {
    throw new Exception("Could not open CSV file: $file_path");
  }

  // Skip header row.
  fgetcsv($file_handle);

  // Process each row.
  while (($data = fgetcsv($file_handle)) !== FALSE) {
    $row_count++;

    try {
      // Map CSV columns to variables.
      [$username, $firstname, $lastname, $password, $phone, $org, $email, $group, $vet, $active, $createBy, $createDate, $updateBy, $updateDate] = $data;

      // Skip if username is empty.
      if (empty($username)) {
        print("\nSkipping row $row_count - empty username");
        continue;
      }

      // Check if user already exists.
      $existing_users = \Drupal::entityTypeManager()
        ->getStorage('user')
        ->loadByProperties(['name' => $username]);

      if (!empty($existing_users)) {
        $user = reset($existing_users);
        print("\nUpdating existing user: $username");
      } else {
        // Create new user if doesn't exist
        $user = User::create();
        $user->setUsername($username);
      }

      // Set required fields.
      $user->setPassword($password);
      // Set dummy email if empty.
      $user->setEmail($email ?: "$username@example.com");
      $user->set('status', $active);

      // Set custom fields.
      $user->set('field_first_name', $firstname);
      $user->set('field_last_name', $lastname);
      $user->set('field_phone', $phone);

      // Set organization reference.
      if (!empty($org)) {
        $org_term = ensure_taxonomy_term('org', $org);
        if ($org_term) {
          $user->set('field_org', ['target_id' => $org_term->id()]);
        }
      }

      // Set security group reference and corresponding role.
      if (!empty($group)) {
        try {
          // Try to load the existing security group term
          $terms = \Drupal::entityTypeManager()
            ->getStorage('taxonomy_term')
            ->loadByProperties([
              'vid' => 'security_group',
              'name' => $group,
            ]);
          
          if (!empty($terms)) {
            $group_term = reset($terms);
            $user->set('field_security_group', ['target_id' => $group_term->id()]);
          } else {
            print("\nWarning: Security group '$group' not found in taxonomy");
          }

          // Assign corresponding role if mapping exists
          if (isset($security_group_roles[$group])) {
            $role_id = $security_group_roles[$group];
            if (Role::load($role_id)) {
              $user->addRole($role_id);
              print("\nAssigned role '$role_id' to user: $username");
            } else {
              print("\nWarning: Role '$role_id' does not exist - skipping role assignment for user: $username");
            }
          } else {
            print("\nWarning: No role mapping found for security group '$group' - user: $username");
          }
        } catch (Exception $e) {
          print("\nError handling security group for user $username: " . $e->getMessage());
        }
      }

      // Set veterinarian boolean.
      $user->set('field_vet', (bool) $vet);

      // Set timestamps.
      if (!empty($createDate)) {
        $user->set('created', strtotime($createDate));
      }
      if (!empty($updateDate)) {
        $user->set('changed', strtotime($updateDate));
      }

      // Save user.
      $user->save();
      $success_count++;
      print("\n" . (isset($existing_users) ? "Updated" : "Created") . " user: $username");

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

// Print summary.
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
function ensure_taxonomy_term($vocabulary, $name) {
  try {
    // Try to load existing term.
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
    $term = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->create([
        'vid' => $vocabulary,
        'name' => $name,
      ]);
    $term->save();
    return $term;

  } catch (Exception $e) {
    print("\nError ensuring taxonomy term ($vocabulary: $name): " . $e->getMessage());
    return NULL;
  }
}