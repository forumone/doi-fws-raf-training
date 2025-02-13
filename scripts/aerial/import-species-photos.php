#!/usr/bin/env php
<?php

/**
 * @file
 * Import species photos from CSV into media entities.
 */

use Drupal\media\Entity\Media;
use Drupal\file\Entity\File;

// Read CSV file.
$csv_file = dirname(__FILE__) . '/data/PHOTO_FILE_METADATA.csv';
if (!file_exists($csv_file)) {
  die("CSV file not found at: $csv_file\n");
}

$handle = fopen($csv_file, 'r');
if (!$handle) {
  die("Could not open CSV file\n");
}

$count = 0;
$errors = [];

// Skip header row.
fgetcsv($handle);

// Process each row.
while (($data = fgetcsv($handle)) !== FALSE) {
  if (count($data) < 9) {
    $errors[] = "Invalid row format: " . implode(',', $data);
    continue;
  }

  $file_id = $data[0];
  $filename = $data[1];
  $bird_count = $data[2];
  $plumage = strtolower($data[3]);
  $position = strtolower($data[4]);
  $created_by_email = $data[7];

  // Look up user ID from email.
  $users = \Drupal::entityTypeManager()
    ->getStorage('user')
    ->loadByProperties(['mail' => $created_by_email]);

  if (empty($users)) {
    $errors[] = "User not found for email: $created_by_email";
    continue;
  }
  $user = reset($users);
  $uid = $user->id();

  // Check if file exists in aerial files directory.
  $uri = 'public://images/test/' . $filename;
  $real_path = DRUPAL_ROOT . '/sites/aerial/files/images/test/' . $filename;

  // If file not found directly, try case-insensitive search.
  if (!file_exists($real_path)) {
    $dir = dirname($real_path);
    $base_name = basename($filename);
    $found = FALSE;

    if (is_dir($dir)) {
      $files = scandir($dir);
      foreach ($files as $file) {
        if (strcasecmp($file, $base_name) === 0) {
          $filename = $file;
          $uri = 'public://images/test/' . $filename;
          $real_path = DRUPAL_ROOT . '/sites/aerial/files/images/test/' . $filename;
          $found = TRUE;
          break;
        }
      }
    }

    if (!$found) {
      $errors[] = "File not found: $filename";
      continue;
    }
  }

  try {
    // Create managed file entity.
    $file = File::create([
      'uri' => $uri,
      'uid' => $uid,
      'status' => 1,
    ]);
    $file->save();

    // Create media entity.
    $media = Media::create([
      'bundle' => 'species_image',
      'uid' => $uid,
      'field_image' => [
        'target_id' => $file->id(),
        'alt' => pathinfo($filename, PATHINFO_FILENAME),
      ],
      'field_bird_count' => $bird_count,
      'field_plumage' => $plumage,
      'field_position' => $position,
      'status' => 1,
    ]);
    $media->save();

    $count++;
    echo "Imported: $filename\n";
  }
  catch (\Exception $e) {
    $errors[] = "Error processing file $filename: " . $e->getMessage();
  }
}

fclose($handle);

echo "\nImport completed.\n";
echo "Successfully imported $count media entities.\n";

if (!empty($errors)) {
  echo "\nErrors encountered:\n";
  foreach ($errors as $error) {
    echo "- $error\n";
  }
}
