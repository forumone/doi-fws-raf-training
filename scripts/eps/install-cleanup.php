<?php

/**
 * @file
 */

use Drupal\user\Entity\User;

/**
 * @file
 * Installation clean-up and additinoal configuration.
 *
 *  Install-cleanup.php.
 */

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

// Copy image files to the specified location.
$source = '../recipes/fws-eps-content/images/';
$destination = './sites/eps/files/inline-images/';

if (!file_exists($destination)) {
  mkdir($destination, 0777, TRUE);
}

$files = scandir($source);
foreach ($files as $file) {
  if (in_array($file, ['.', '..'])) {
    continue;
  }

  copy($source . $file, $destination . $file);
}

echo "Image files have been copied to the specified location.\n";

// Copy PDF file to the specified location.
$source = '../recipes/fws-eps-content/content/file/Crane Survey Data Form_0.pdf';
$destination = './sites/eps/files/';

if (!file_exists($destination)) {
  mkdir($destination, 0777, TRUE);
}

if (file_exists($source)) {
  copy($source, $destination . 'Crane Survey Data Form_0.pdf');
  echo "PDF file has been copied to the specified location.\n";
}
else {
  echo "Warning: Source PDF file not found at: $source\n";
}

// Create users with specific roles if they don't already exist.
$users = [
  'daniel@prometsource.com' => 'administrator',
  'iryna.lemeha@prometsource.com' => 'administrator',
  'keith_setliff@fws.gov' => 'administrator',
  'carl_chitwood@fws.gov' => 'administrator',
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
        'daniel@prometsource.com' => 'daniel@prometsource.com',
        'iryna.lemeha@prometsource.com' => 'iryna.lemeha@prometsource.com',
        'keith_setliff@fws.gov' => 'keith_setliff@fws.gov',
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
