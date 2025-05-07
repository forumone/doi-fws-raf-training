<?php

/**
 * @file
 * Prepares file_managed entries for species videos before content import.
 *
 * This script reads the species data from the content recipe,
 * then creates the necessary file_managed entries with correct UUIDs.
 */

use Drupal\user\Entity\User;

// Small cleanup to delete erroneous folder.
if (file_exists('public:') && is_writable('public:')) {
  rmdir('public:');
}

// Delete views.view.user_admin_people.
$view = \Drupal::entityTypeManager()->getStorage('view')->load('user_admin_people');
if ($view) {
  $view->delete();
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
$source = '../recipes/fws-aerial-content/images/';
$destination = './sites/aerial/files/inline-images/';

if (!file_exists($destination)) {
  mkdir($destination, 0777, TRUE);
}

$files = scandir($source);
foreach ($files as $file) {
  if (in_array($file, ['.', '..'])) {
    continue;
  }

  if (!file_exists($destination . $file)) {
    copy($source . $file, $destination . $file);
  }
}

echo "Image files have been copied to the specified location.\n";

// Copy technique video files.
$video_source = 'https://systems.fws.gov/waterfowlsurveys/videos/newvideos/';
$video_destination = './sites/aerial/files/videos/';

if (!file_exists($video_destination)) {
  mkdir($video_destination, 0777, TRUE);
}

$counting_videos = [
  'Counting_Techniques_2030kbps.mp4',
  'Counting_Techniques_590kbps.mp4',
];

foreach ($counting_videos as $video) {
  if (!file_exists($video_destination . $video)) {
    copy($video_source . $video, $video_destination . $video);
  }
}

echo "Technique Video files have been copied to the specified location.\n";

// Create users with specific roles if they don't already exist.
$users = [
  'sonal@prometsource.com' => 'administrator',
  'daniel@prometsource.com' => 'administrator',
  'iryna.lemeha@prometsource.com' => 'administrator',
  'keith_setliff@fws.gov' => 'administrator',
  'carl_chitwood@fws.gov' => 'administrator',
  'daniel+admin@prometsource.com' => 'administrator',
  'daniel+contentpublisher@prometsource.com' => 'content_publisher',
  'daniel+contenteditor@prometsource.com' => 'content_editor',
];

foreach ($users as $username => $role) {
  // Check if user already exists.
  $existing_user = \Drupal::entityTypeManager()
    ->getStorage('user')
    ->loadByProperties(['name' => $username]);

  if (empty($existing_user)) {
    $user = User::create([
      'name' => $username,
      'mail' => $username,
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
