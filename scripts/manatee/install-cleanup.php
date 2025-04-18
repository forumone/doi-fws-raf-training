<?php

/**
 * @file
 * Installation clean-up and additinoal configuration.
 *
 *  Install-cleanup.php.
 */

use Drupal\user\Entity\User;
use Drupal\views\Entity\View;

// Small cleanup to delete erroneous folder.
if (file_exists('public:') && is_writable('public:')) {
  rmdir('public:');
}

// The menu configuration data.
$menuConfig = [
  'menu_config' => [
    '7ef232d6-dae0-4113-8d12-a0e8895ea451' => [
      'rows_content' => [],
      'submenu_config' => [
        'width' => '',
        'class' => '',
        'type' => '',
      ],
      'item_config' => [
        'level' => 0,
        'type' => 'we-mega-menu-li',
        'id' => '7ef232d6-dae0-4113-8d12-a0e8895ea451',
        'submenu' => '0',
        'hide_sub_when_collapse' => '',
        'group' => '0',
        'class' => 'home',
        'data-icon' => 'home',
        'data-caption' => '',
        'data-alignsub' => '',
        'data-target' => '',
      ],
    ],
    // Additional menu items as per your configuration...
    '0e7dfd71-4a5c-4d64-ad44-24dcf2b3cce1' => [
      'rows_content' => [],
      'submenu_config' => [
        'width' => '',
        'class' => '',
        'type' => '',
      ],
      'item_config' => [
        'level' => 0,
        'type' => 'we-mega-menu-li',
        'id' => '0e7dfd71-4a5c-4d64-ad44-24dcf2b3cce1',
        'submenu' => '0',
        'hide_sub_when_collapse' => '',
        'group' => '0',
        'class' => '',
        'data-icon' => '',
        'data-caption' => '',
        'data-alignsub' => '',
        'data-target' => '',
      ],
    ],
  ],
  'block_config' => [
    'style' => 'Default',
    'animation' => 'None',
    'delay' => '',
    'duration' => '',
    'auto-arrow' => '',
    'always-show-submenu' => '1',
    'action' => 'hover',
    'auto-mobile-collapse' => '0',
  ],
];

try {
  // Convert the array to JSON.
  $jsonConfig = json_encode($menuConfig);

  // Using Drupal's database API.
  \Drupal::database()->merge('we_megamenu')
    ->key([
      'menu_name' => 'main',
      'theme' => 'fws_raf',
    ])
    ->fields([
      'menu_name' => 'main',
      'theme' => 'fws_raf',
      'data_config' => $jsonConfig,
    ])
    ->execute();

  echo "WE Megamenu configuration has been successfully updated.\n";
}
catch (Exception $e) {
  echo "Error updating WE Megamenu configuration: " . $e->getMessage() . "\n";
}

// Create users with specific roles if they don't already exist.
$users = [
  'contributor' => 'contributor',
  'researcher' => 'other_researchers',
  'partner' => 'partner_administrator',
  'viewer' => 'viewer',
  'sonal' => 'administrator',
  'keith_setliff@fws.gov' => 'administrator',
  'carl_chitwood@fws.gov' => 'administrator',
  'Nadia.Lentz@myfwc.com' => 'partner_administrator',
  'daniel@prometsource.com' => 'administrator',
  'iryna.lemeha@prometsource.com' => 'administrator',
];

foreach ($users as $username => $role) {
  // Check if user already exists.
  $existing_user = \Drupal::entityTypeManager()
    ->getStorage('user')
    ->loadByProperties(['name' => $username]);

  if (empty($existing_user)) {
    $user = User::create([
      'name' => $username,
      'mail' => match($username) {
        'sonal' => 'sonal@prometsource.com',
        'Nadia.Lentz@myfwc.com' => 'Nadia.Lentz@myfwc.com',
        'keith_setliff@fws.gov' => 'keith_setliff@fws.gov',
        'daniel@prometsource.com' => 'daniel@prometsource.com',
        'iryna.lemeha@prometsource.com' => 'iryna.lemeha@prometsource.com',
        'carl_chitwood@fws.gov' => 'carl_chitwood@fws.gov',
        default => $username . '@example.com'
      },
      'status' => 1,
      'roles' => [$role],
    ]);
    $user->save();
    echo "User '$username' with role '$role' has been created.\n";
  }
  else {
    echo "User '$username' already exists, skipping creation.\n";
  }
}

// Disable the taxonomy_term view if it exists and is enabled.
$view = View::load('taxonomy_term');
if ($view && !$view->status()) {
  $view->disable()->save();
  echo "The taxonomy_term view has been disabled.\n";
}
elseif ($view) {
  echo "The taxonomy_term view is already disabled.\n";
}
else {
  echo "The taxonomy_term view does not exist.\n";
}

// Copy the image file to the specified location if it doesn't exist.
$source = '../recipes/fws-manatee-content/images/usfws-manatee-mother-and-calf.jpeg';
$destination = './sites/default/files/inline-images/usfws-manatee-mother-and-calf.jpeg';

if (!file_exists($destination)) {
  if (!file_exists(dirname($destination))) {
    mkdir(dirname($destination), 0777, TRUE);
  }

  if (copy($source, $destination)) {
    echo "Image has been successfully copied to $destination.\n";
  }
  else {
    echo "Failed to copy the image to $destination.\n";
  }
}
else {
  echo "Image already exists at $destination, skipping copy.\n";
}

// Copy documents to the specified location if they don't exist.
$source = '../recipes/fws-manatee-content/documents';
$destination = './sites/default/files/documents';

if (!file_exists($destination)) {
  mkdir($destination, 0777, TRUE);
}

$files = scandir($source);
foreach ($files as $file) {
  if ($file === '.' || $file === '..') {
    continue;
  }

  $sourceFile = $source . '/' . $file;
  $destinationFile = $destination . '/' . $file;

  if (!file_exists($destinationFile)) {
    if (copy($sourceFile, $destinationFile)) {
      echo "File '$file' has been successfully copied to $destination.\n";
    }
    else {
      echo "Failed to copy the file '$file' to $destination.\n";
    }
  }
  else {
    echo "File '$file' already exists at $destination, skipping copy.\n";
  }
}
