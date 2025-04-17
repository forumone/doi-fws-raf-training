<?php

/**
 * @file
 * Creates users with specific roles.
 */

use Drupal\user\Entity\User;

// Create users with specific roles.
$users = [
  'daniel@prometsource.com' => 'administrator',
  'iryna.lemeha@prometsource.com' => 'administrator',
  'state_admin' => 'state_admin',
  'state_law' => 'state_law',
  'federal_law' => 'federal_law',
  'falconer' => 'falconer',
];

// Get the VA state term.
try {
  $va_terms = \Drupal::entityTypeManager()
    ->getStorage('taxonomy_term')
    ->loadByProperties([
      'vid' => 'state',
      'name' => 'VA',
    ]);
  $va_term = reset($va_terms);

  if (!$va_term) {
    throw new \Exception("No VA state term found.");
  }

  foreach ($users as $username => $role) {
    // Check if user already exists.
    $existing_users = \Drupal::entityTypeManager()
      ->getStorage('user')
      ->loadByProperties(['name' => $username]);
    $existing_user = reset($existing_users);

    if (empty($existing_user)) {
      $user_data = [
        'name' => $username,
        'mail' => match($username) {
          'daniel@prometsource.com' => 'daniel@prometsource.com',
          'iryna.lemeha@prometsource.com' => 'iryna.lemeha@prometsource.com',
          default => $username . '@example.com'
        },
        'status' => 1,
        'roles' => [$role],
      ];

      // Set state code for state_admin and state_law users.
      if (in_array($username, ['state_admin', 'state_law', 'falconer'])) {
        $user_data['field_state_cd'] = $va_term->id();
      }

      $user = User::create($user_data);
      $user->save();

      print "Created user '$username' with role '$role'.\n";
    }
    else {
      print "User '$username' already exists, skipping creation.\n";
    }
  }
}
catch (Exception $e) {
  print "Error creating users: " . $e->getMessage() . "\n";
}
